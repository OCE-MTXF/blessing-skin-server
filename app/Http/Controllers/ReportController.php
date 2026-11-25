<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Texture;
use App\Models\User;
use App\Services\Auth\AccountSecurity;
use App\Services\Textures\DeleteTexture;
use App\Services\Textures\TextureReferences;
use Blessing\Filter;
use Blessing\Rejection;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function submit(Request $request, Dispatcher $dispatcher, Filter $filter, TextureReferences $references)
    {
        $data = $request->validate([
            'tid' => 'required|integer|exists:textures',
            'reason' => 'required|string|max:2000',
        ]);
        ['tid' => $tid, 'reason' => $reason] = $data;
        $uid = $request->user()->uid;

        return DB::transaction(function () use ($tid, $reason, $uid, $references, $filter, $dispatcher) {
            $texture = Texture::lockForUpdate()->findOrFail($tid);
            $reporter = User::lockForUpdate()->findOrFail($uid);
            abort_unless($references->canView($reporter, $texture) && $reporter->permission !== User::BANNED, 404);
            $can = $filter->apply('user_can_report', true, [$tid, $reason, $reporter]);
            if ($can instanceof Rejection) {
                return json($can->getReason(), 1);
            }
            $dispatcher->dispatch('report.submitting', [$tid, $reason, $reporter]);
            if (Report::where('reporter', $uid)->where('tid', $tid)->exists()) {
                return json(trans('skinlib.report.duplicate'), 1);
            }
            $score = (int) option('reporter_score_modification', 0);
            if ($score < 0 && $reporter->score < -$score) {
                return json(trans('skinlib.upload.lack-score'), 1);
            }
            $reporter->increment('score', $score);

            $report = new Report();
            $report->tid = $tid;
            $report->uploader = $texture->uploader;
            $report->reporter = $uid;
            $report->reason = $reason;
            $report->status = Report::PENDING;
            $report->save();
            $dispatcher->dispatch('report.submitted', [$report]);

            return json(trans('skinlib.report.success'), 0);
        });
    }

    public function track()
    {
        $reports = Report::where('reporter', auth()->id())
            ->orderBy('report_at', 'desc')
            ->paginate(10);

        return view('user.report', ['reports' => $reports]);
    }

    public function manage(Request $request)
    {
        $q = $request->input('q');

        return Report::usingSearchString($q)
            ->with(['texture', 'textureUploader', 'informer'])
            ->paginate(9);
    }

    public function review(
        Report $report,
        Request $request,
        Dispatcher $dispatcher,
        DeleteTexture $delete,
        AccountSecurity $security,
    ) {
        $action = $request->validate(['action' => ['required', Rule::in(['delete', 'ban', 'reject'])]])['action'];
        $actor = $request->user();

        return $security->serialized(function () use ($report, $actor, $action, $dispatcher, $delete, $security) {
            $report = Report::lockForUpdate()->findOrFail($report->id);
            // Account roles are serialized; avoid locking a user row ahead of digest/texture locks on deletion.
            $current = $action === 'ban' ? $security->lockUser($actor->uid) : User::findOrFail($actor->uid);
            $security->assertCurrent($actor, $current);
            abort_unless($current->isAdmin(), 403);

            return $this->reviewLocked($report, $action, $dispatcher, $delete, $security, $current);
        });
    }

    private function reviewLocked(
        Report $report,
        string $action,
        Dispatcher $dispatcher,
        DeleteTexture $delete,
        AccountSecurity $security,
        User $actor,
    )
    {
        $dispatcher->dispatch('report.reviewing', [$report, $action]);

        if ($action == 'reject') {
            if (
                $report->informer
                && ($score = option('reporter_score_modification', 0)) > 0
                && $report->status == Report::PENDING
            ) {
                $report->informer->decrement('score', (int) $score);
            }
            $report->status = Report::REJECTED;
            $report->save();

            $dispatcher->dispatch('report.rejected', [$report]);

            return json(trans('general.op-success'), 0, ['status' => Report::REJECTED]);
        }

        switch ($action) {
            case 'delete':
                $texture = $report->texture;
                if (!$texture || !$delete->handle($texture)) {
                    // The texture has been deleted by its uploader
                    // We will return the score, but will not give the informer any reward
                    self::returnScore($report);
                    $report->status = Report::RESOLVED;
                    $report->save();

                    $dispatcher->dispatch('report.resolved', [$report, $action]);

                    return json(trans('general.texture-deleted'), 0, ['status' => Report::RESOLVED]);
                }
                break;
            case 'ban':
                try {
                    $uploader = $security->lockUser((int) $report->uploader);
                } catch (ModelNotFoundException) {
                    return json(trans('admin.users.operations.non-existent'), 1);
                }
                // A higher, distinct administrator always remains privileged.
                if ($actor->uid === $uploader->uid || $actor->permission <= $uploader->permission) {
                    return json(trans('admin.users.operations.no-permission'), 1);
                }
                if ($uploader->permission !== User::BANNED) {
                    $uploader->permission = User::BANNED;
                    $security->invalidate($uploader);
                    $dispatcher->dispatch('user.banned', [$uploader]);
                }
                break;
        }

        self::returnScore($report);
        self::giveAward($report);
        $report->status = Report::RESOLVED;
        $report->save();

        $dispatcher->dispatch('report.resolved', [$report, $action]);

        return json(trans('general.op-success'), 0, ['status' => Report::RESOLVED]);
    }

    public static function returnScore($report)
    {
        if (
            $report->status == Report::PENDING
            && ($score = option('reporter_score_modification', 0)) < 0
            && $report->informer
        ) {
            $report->informer->decrement('score', (int) $score);
        }
    }

    public static function giveAward($report)
    {
        if ($report->status == Report::PENDING && $report->informer) {
            $report->informer->increment('score', (int) option('reporter_reward_score', 0));
        }
    }
}
