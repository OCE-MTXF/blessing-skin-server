<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\AccountIdentity;
use App\Services\Auth\AccountMail;
use App\Services\Auth\AccountSecurity;
use App\Services\Auth\AccountThrottle;
use App\Services\Auth\PasswordCipher;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

class UsersManagementController extends Controller
{
    public function __construct(private AccountSecurity $security, private AccountThrottle $throttle)
    {
    }

    public function list(Request $request)
    {
        return User::usingSearchString($request->input('q'))->paginate(10);
    }

    public function email(User $user, Request $request, Dispatcher $dispatcher)
    {
        ['email' => $email] = $request->validate(['email' => AccountIdentity::emailRules()]);
        $email = AccountIdentity::email($email);
        try {
            return $this->withTarget($request, $user, false, function (User $target, User $actor) use ($dispatcher, $email) {
                $old = $target->replicate();
                $dispatcher->dispatch('user.email.updating', [$target, $email]);
                if ($this->security->updateEmail($target, $email)) {
                    DB::afterCommit(function () use ($dispatcher, $target, $actor, $old) {
                        Log::notice('Managed account email changed.', ['actor_id' => $actor->uid, 'target_id' => $target->uid]);
                        $dispatcher->dispatch('user.email.updated', [$target, $old]);
                    });
                }

                return json(trans('admin.users.operations.email.success'), 0);
            });
        } catch (UniqueConstraintViolationException) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => trans('validation.unique', ['attribute' => trans('auth.email')])]);
        }
    }

    public function verification(User $user, Request $request, Dispatcher $dispatcher, AccountMail $mail)
    {
        $this->throttle->reserve('verification_mail', AccountIdentity::source($request), 12, 600, (string) $user->uid, 1, 60);

        return $this->withTarget($request, $user, false, function (User $target) use ($dispatcher, $mail) {
            if ($target->verified) {
                $dispatcher->dispatch('user.verification.updating', [$target]);
                $target->verified = false;
                $this->security->invalidate($target);
                DB::afterCommit(fn () => $dispatcher->dispatch('user.verification.updated', [$target]));

                return json(trans('admin.users.operations.verification.success'), 0, ['verified' => false]);
            }
            if (!option('require_verification') || !$mail->enabled() || !AccountIdentity::canMail($target->email)) {
                return json(trans('user.verification.disabled'), 1);
            }
            DB::afterCommit(fn () => $mail->verification($target->uid, $target->account_version));

            return json(trans('user.verification.success'), 0, ['verified' => false]);
        });
    }

    public function nickname(User $user, Request $request, Dispatcher $dispatcher)
    {
        ['nickname' => $nickname] = $request->validate(['nickname' => 'required|string|max:50']);

        return $this->withTarget($request, $user, true, function (User $target) use ($dispatcher, $nickname) {
            $dispatcher->dispatch('user.nickname.updating', [$target, $nickname]);
            $old = $target->replicate();
            $target->nickname = $nickname;
            $this->save($target);
            DB::afterCommit(fn () => $dispatcher->dispatch('user.nickname.updated', [$target, $old]));

            return json(trans('admin.users.operations.nickname.success', ['new' => $nickname]), 0);
        });
    }

    public function password(User $user, Request $request, Dispatcher $dispatcher)
    {
        ['password' => $password] = $request->validate(['password' => PasswordCipher::NEW_RULE]);

        return $this->withTarget($request, $user, false, function (User $target, User $actor) use ($dispatcher, $password) {
            $dispatcher->dispatch('user.password.updating', [$target, null]);
            $this->security->updatePassword($target, $password);
            DB::afterCommit(function () use ($dispatcher, $target, $actor) {
                Log::notice('Managed account password changed.', ['actor_id' => $actor->uid, 'target_id' => $target->uid]);
                $dispatcher->dispatch('user.password.updated', [$target]);
            });

            return json(trans('admin.users.operations.password.success'), 0);
        });
    }

    public function score(User $user, Request $request, Dispatcher $dispatcher)
    {
        ['score' => $score] = $request->validate(['score' => 'required|integer|between:-2147483648,2147483647']);
        $score = (int) $score;

        return $this->withTarget($request, $user, true, function (User $target) use ($dispatcher, $score) {
            $dispatcher->dispatch('user.score.updating', [$target, $score]);
            $old = $target->replicate();
            $target->score = $score;
            $this->save($target);
            DB::afterCommit(fn () => $dispatcher->dispatch('user.score.updated', [$target, $old]));

            return json(trans('admin.users.operations.score.success'), 0);
        });
    }

    public function permission(User $user, Request $request, Dispatcher $dispatcher)
    {
        ['permission' => $permission] = $request->validate([
            'permission' => ['required', Rule::in([User::BANNED, User::NORMAL, User::ADMIN])],
        ]);
        $permission = (int) $permission;

        return $this->withTarget($request, $user, false, function (User $target, User $actor) use ($dispatcher, $permission) {
            if (($permission === User::ADMIN && $actor->permission < User::SUPER_ADMIN)
                || ($permission < User::ADMIN && $this->lastPrivileged($target))) {
                return $this->denied();
            }
            if ($target->permission !== $permission) {
                $dispatcher->dispatch('user.permission.updating', [$target, $permission]);
                $old = $target->replicate();
                $target->permission = $permission;
                // Revoke on either direction: existing grants must not pick up new privileges.
                $this->security->invalidate($target);
                DB::afterCommit(function () use ($dispatcher, $target, $actor, $old, $permission) {
                    Log::notice('Managed account permission changed.', ['actor_id' => $actor->uid, 'target_id' => $target->uid, 'permission' => $permission]);
                    if ($permission === User::BANNED) {
                        $dispatcher->dispatch('user.banned', [$target]);
                    }
                    $dispatcher->dispatch('user.permission.updated', [$target, $old]);
                });
            }

            return json(trans('admin.users.operations.permission'), 0);
        });
    }

    public function delete(User $user, Request $request, Dispatcher $dispatcher)
    {
        $actor = $request->user();
        $this->throttle->reserve('reauth', AccountIdentity::source($request), 20, 600, (string) $actor->uid, 6, 600);
        ['password' => $password] = $request->validate([
            'password' => PasswordCipher::CURRENT_RULE,
            'confirmation' => ['required', 'string', Rule::in(['delete'])],
        ]);

        return $this->withTarget($request, $user, false, function (User $target, User $actor) use ($dispatcher, $password) {
            if ($this->lastPrivileged($target) || !$actor->verifyPassword($password)) {
                Log::notice('Managed account removal denied.', ['actor_id' => $actor->uid, 'target_id' => $target->uid]);

                return $this->denied();
            }
            $dispatcher->dispatch('user.deleting', [$target]);
            $this->security->delete($target);
            DB::afterCommit(function () use ($dispatcher, $target, $actor) {
                Log::notice('Managed account removal completed.', ['actor_id' => $actor->uid, 'target_id' => $target->uid]);
                $dispatcher->dispatch('user.deleted', [$target]);
            });

            return json(trans('admin.users.operations.delete.success'), 0);
        });
    }

    private function withTarget(Request $request, User $snapshot, bool $allowSelf, Closure $change)
    {
        $actorSnapshot = $request->user();

        return $this->security->serialized(function () use ($actorSnapshot, $snapshot, $allowSelf, $change) {
            $actor = $this->security->lockUser($actorSnapshot->uid);
            $this->security->assertCurrent($actorSnapshot, $actor);
            $self = $actor->is($snapshot);
            $target = $self ? $actor : $this->security->lockUser($snapshot->uid);
            if (!$actor->isAdmin() || !$this->security->allowsOAuth($actor)
                || ($self ? !$allowSelf : $target->permission >= $actor->permission)) {
                return $this->denied();
            }

            return $change($target, $actor);
        });
    }

    private function lastPrivileged(User $target): bool
    {
        return $target->isAdmin() && !User::where('permission', '>=', User::ADMIN)->whereKeyNot($target->uid)->exists();
    }

    private function denied()
    {
        return json(trans('admin.users.operations.no-permission'), 1)->setStatusCode(403);
    }

    private function save(User $target): void
    {
        if (!$target->save()) {
            throw new RuntimeException('Managed account update was cancelled.');
        }
    }
}
