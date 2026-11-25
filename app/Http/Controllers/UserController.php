<?php

namespace App\Http\Controllers;

use App\Events\UserProfileUpdated;
use App\Models\Texture;
use App\Models\User;
use App\Services\Auth\AccountIdentity;
use App\Services\Auth\AccountMail;
use App\Services\Auth\AccountSecurity;
use App\Services\Auth\AccountThrottle;
use App\Services\Auth\PasswordCipher;
use App\Services\SafeMarkdown;
use App\Services\Textures\TextureReferences;
use Blessing\Filter;
use Blessing\Rejection;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UserController extends Controller
{
    public function user()
    {
        /** @var User */
        $user = auth()->user();

        return $user
            ->makeHidden(['password', 'ip', 'remember_token', 'verification_token']);
    }

    public function index(Filter $filter)
    {
        $user = Auth::user();

        [$min, $max] = explode(',', option('sign_score'));
        $scoreIntro = trans('user.score-intro.introduction', [
            'initial_score' => option('user_initial_score'),
            'score-from' => $min,
            'score-to' => $max,
            'return-score' => option('return_score')
                ? trans('user.score-intro.will-return-score')
                : trans('user.score-intro.no-return-score'),
        ]);

        $grid = [
            'layout' => [
                ['md-7', 'md-5'],
            ],
            'widgets' => [
                [
                    [
                        'user.widgets.email-verification',
                        'user.widgets.dashboard.usage',
                    ],
                    ['user.widgets.dashboard.announcement'],
                ],
            ],
        ];
        $grid = $filter->apply('grid:user.index', $grid);

        return view('user.index')->with([
            'score_intro' => $scoreIntro,
            'rates' => [
                'storage' => option('score_per_storage'),
                'player' => option('score_per_player'),
                'closet' => option('score_per_closet_item'),
            ],
            'announcement' => app(SafeMarkdown::class)->render((string) option_localized('announcement')),
            'grid' => $grid,
            'extra' => ['unverified' => option('require_verification') && !$user->verified],
        ]);
    }

    public function scoreInfo()
    {
        /** @var User */
        $user = Auth::user();

        return response()->json([
            'user' => [
                'score' => $user->score,
                'lastSignAt' => $user->last_sign_at,
            ],
            'rate' => [
                'storage' => (int) option('score_per_storage'),
                'players' => (int) option('score_per_player'),
            ],
            'usage' => [
                'players' => $user->players()->count(),
                'storage' => (int) Texture::where('uploader', $user->uid)->sum('size'),
            ],
            'signAfterZero' => (bool) option('sign_after_zero'),
            'signGapTime' => (int) option('sign_gap_time'),
        ]);
    }

    public function sign(Request $request, Dispatcher $dispatcher, Filter $filter, AccountSecurity $security)
    {
        $actor = $request->user();
        $can = $filter->apply('can_sign', true);
        if ($can instanceof Rejection) {
            return json($can->getReason(), 2);
        }

        return DB::transaction(function () use ($actor, $dispatcher, $filter, $security) {
            $user = $security->lockUser($actor->uid);
            $security->assertCurrent($actor, $user);
            $now = CarbonImmutable::now();
            $lastSign = CarbonImmutable::parse($user->last_sign_at);
            $eligible = option('sign_after_zero')
                ? $lastSign->lt($now->startOfDay())
                : $lastSign->addHours(max(1, (int) option('sign_gap_time')))->lte($now);
            if (!$eligible) {
                return json('', 1);
            }

            [$min, $max] = array_map('intval', explode(',', option('sign_score')));
            $acquiredScore = (int) $filter->apply('sign_score', random_int($min, $max));
            $dispatcher->dispatch('user.sign.before', [$acquiredScore]);
            $user->score += $acquiredScore;
            $user->last_sign_at = $now;
            if (!$user->save()) {
                throw new RuntimeException('Sign-in award was cancelled.');
            }
            DB::afterCommit(fn () => $dispatcher->dispatch('user.sign.after', [$acquiredScore]));

            return json(trans('user.sign-success', ['score' => $acquiredScore]), 0, ['score' => $user->score]);
        });
    }

    public function sendVerificationEmail(Request $request, AccountThrottle $throttle, AccountMail $mail)
    {
        if (!option('require_verification') || !$mail->enabled()) {
            return json(trans('user.verification.disabled'), 1);
        }
        $user = $request->user();
        $throttle->reserve('verification_mail', AccountIdentity::source($request), 12, 600, (string) $user->uid, 1, 60);
        if ($user->verified) {
            return json(trans('user.verification.verified'), 1);
        }
        $mail->verification($user->uid, $user->account_version);

        return json(trans('user.verification.success'), 0);
    }

    public function profile(Filter $filter)
    {
        $user = Auth::user();

        $grid = [
            'layout' => [
                ['md-6', 'md-6'],
            ],
            'widgets' => [
                [
                    [
                        'user.widgets.profile.avatar',
                        'user.widgets.profile.password',
                    ],
                    [
                        'user.widgets.profile.nickname',
                        'user.widgets.profile.email',
                        'user.widgets.profile.delete-account',
                    ],
                ],
            ],
        ];
        $grid = $filter->apply('grid:user.profile', $grid);

        return view('user.profile')
            ->with('user', $user)
            ->with('grid', $grid)
            ->with('site_name', option_localized('site_name'));
    }

    public function handleProfile(Request $request, Filter $filter, Dispatcher $dispatcher, AccountSecurity $security, AccountThrottle $throttle)
    {
        $action = $request->input('action', '');
        $actor = $request->user();
        $addition = $request->only(['new_nickname', 'email']);

        $can = $filter->apply('user_can_edit_profile', true, [$action, $addition]);
        if ($can instanceof Rejection) {
            return json($can->getReason(), 1);
        }
        if (in_array($action, ['email', 'password', 'delete'], true)) {
            $throttle->reserve('reauth', AccountIdentity::source($request), 20, 600, (string) $actor->uid, 6, 600);
        }
        $data = $request->validate(match ($action) {
            'nickname' => ['new_nickname' => 'required|string|max:50'],
            'password' => ['current_password' => PasswordCipher::CURRENT_RULE, 'new_password' => PasswordCipher::NEW_RULE],
            'email' => ['email' => AccountIdentity::emailRules(), 'password' => PasswordCipher::CURRENT_RULE],
            'delete' => ['password' => PasswordCipher::CURRENT_RULE],
            default => [],
        });
        if ($data === []) {
            return json(trans('general.illegal-parameters'), 1);
        }

        try {
            [$response, $retireSession] = $security->serialized(function () use ($actor, $action, $addition, $data, $dispatcher, $security) {
                $user = $security->lockUser($actor->uid);
                $security->assertCurrent($actor, $user);
                if ($action === 'delete' && $user->isAdmin()) {
                    return [json(trans('user.profile.delete.admin'), 1), false];
                }
                if ($action !== 'nickname' && !$user->verifyPassword($data[$action === 'password' ? 'current_password' : 'password'])) {
                    return [json(trans('user.profile.'.$action.'.wrong-password'), 1), false];
                }

                $dispatcher->dispatch('user.profile.updating', [$user, $action, $addition]);
                if ($action === 'nickname') {
                    $user->nickname = $data['new_nickname'];
                    if (!$user->save()) {
                        throw new RuntimeException('Profile change was cancelled.');
                    }
                } elseif ($action === 'email') {
                    if (!$security->updateEmail($user, $data['email'])) {
                        return [json(trans('user.profile.email.existed'), 1), false];
                    }
                } elseif ($action === 'password') {
                    $security->updatePassword($user, $data['new_password']);
                } else {
                    $dispatcher->dispatch('user.deleting', [$user]);
                    $security->delete($user);
                    DB::afterCommit(function () use ($dispatcher, $user) {
                        Log::notice('Account self-removal completed.', ['user_id' => $user->uid]);
                        $dispatcher->dispatch('user.deleted', [$user]);
                    });

                    return [json(trans('user.profile.delete.success'), 0), true];
                }
                DB::afterCommit(function () use ($dispatcher, $user, $action, $addition) {
                    $dispatcher->dispatch('user.profile.updated', [$user, $action, $addition]);
                    event(new UserProfileUpdated($action, $user));
                });

                return [json(trans('user.profile.'.$action.'.success', ['nickname' => $user->nickname]), 0), $action !== 'nickname'];
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($action !== 'email') {
                throw $e;
            }

            return json(trans('user.profile.email.existed'), 1);
        }
        if ($retireSession) {
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $response;
    }

    public function setAvatar(Request $request, Filter $filter, Dispatcher $dispatcher, TextureReferences $references, AccountSecurity $security)
    {
        $request->validate(['tid' => 'required|integer|min:0']);
        $tid = (int) $request->input('tid');
        $actor = $request->user();
        $can = $filter->apply('user_can_update_avatar', true, [$actor, $tid]);
        if ($can instanceof Rejection) {
            return json($can->getReason(), 1);
        }

        return DB::transaction(function () use ($actor, $tid, $dispatcher, $references, $security) {
            // Keep texture -> account order consistent with mutations which detach references.
            $texture = $tid === 0 ? null : Texture::lockForUpdate()->find($tid);
            if ($tid !== 0 && $texture === null) {
                return json(trans('skinlib.non-existent'), 1);
            }
            if ($texture && !$texture->fitsSlot('avatar')) {
                return json(trans('user.profile.avatar.wrong-type'), 1);
            }
            $user = $security->lockUser($actor->uid);
            $security->assertCurrent($actor, $user);
            if ($texture && !$references->accepts($user, $texture, 'avatar')) {
                return json(trans('skinlib.show.private'), 1);
            }
            $dispatcher->dispatch('user.avatar.updating', [$user, $tid]);
            $user->avatar = $tid;
            if (!$user->save()) {
                throw new RuntimeException('Avatar change was cancelled.');
            }
            DB::afterCommit(fn () => $dispatcher->dispatch('user.avatar.updated', [$user, $tid]));

            return json(trans('user.profile.avatar.success'), 0);
        });
    }

    public function toggleDarkMode()
    {
        /** @var User */
        $user = auth()->user();
        $user->is_dark_mode = !$user->is_dark_mode;
        $user->save();

        return response()->noContent();
    }
}
