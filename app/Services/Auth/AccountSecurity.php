<?php

namespace App\Services\Auth;

use App\Models\User;
use Blessing\Filter;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use RuntimeException;
use SensitiveParameter;

final class AccountSecurity
{
    public const SESSION_VERSION = 'auth.account_version';

    public function __construct(private PasswordCipher $cipher, private Filter $filter)
    {
    }

    /** Serialize OAuth issuance/revocation and account lifecycle transitions on the same DB. */
    public function serialized(Closure $operation): mixed
    {
        $connection = DB::connection();
        foreach ([Passport::client(), Passport::token(), Passport::refreshToken(), Passport::authCode(), new User()] as $model) {
            if ($model->getConnection() !== $connection) {
                throw new RuntimeException('Passport and accounts must share the default database connection.');
            }
        }

        return $connection->transaction(function () use ($connection, $operation) {
            $mutex = $connection->table('account_security_mutex')->where('id', 1);
            if ($connection->getDriverName() === 'sqlite') {
                // The first write acquires SQLite's writer lock; SELECT FOR UPDATE is not available.
                $mutex->update(['id' => 1]);
            }
            if ($mutex->lockForUpdate()->value('id') === null) {
                throw new RuntimeException('Account security schema is not ready.');
            }

            return $operation();
        });
    }

    public function lockUser(int $uid): User
    {
        $query = User::whereKey($uid);
        if (DB::getDriverName() === 'sqlite') {
            (clone $query)->update(['uid' => $uid]);
        }

        return $query->lockForUpdate()->firstOrFail();
    }

    public function assertCurrent(User $snapshot, User $locked): void
    {
        if ($snapshot->isNot($locked) || $snapshot->account_version !== $locked->account_version || $locked->permission === User::BANNED) {
            throw new AuthenticationException();
        }
    }

    public function allowsOAuth(User $user): bool
    {
        return $user->permission !== User::BANNED
            && AccountIdentity::canMail($user->email)
            && (!option('require_verification') || $user->verified);
    }

    public function hashNewPassword(#[SensitiveParameter] string $plain): string
    {
        $hash = $this->cipher->hash($plain);
        $filtered = $this->filter->apply('user_password', $hash);
        // Keep the hook for compatible integrations, but never downgrade a new hash.
        if (is_string($filtered) && !$this->cipher->needsRehash($filtered) && password_verify($plain, $filtered)) {
            return $filtered;
        }

        return $hash;
    }

    public function updatePassword(User $lockedUser, #[SensitiveParameter] string $plain): void
    {
        $lockedUser->password = $this->hashNewPassword($plain);
        $this->invalidate($lockedUser);
    }

    public function updateEmail(User $lockedUser, string $email): bool
    {
        $email = AccountIdentity::email($email);
        if ($lockedUser->email_key === AccountIdentity::emailKey($email)) {
            return false;
        }

        $oldEmail = $lockedUser->email;
        $lockedUser->email = $email;
        $lockedUser->verified = false;
        $this->invalidate($lockedUser);
        if (AccountIdentity::canMail($oldEmail)) {
            DB::afterCommit(fn () => app(AccountMail::class)->notifyAddressChange($oldEmail));
        }
        if (option('require_verification')) {
            $version = $lockedUser->account_version;
            DB::afterCommit(fn () => app(AccountMail::class)->verification($lockedUser->uid, $version));
        }

        return true;
    }

    /** Call within serialized(), with the current user row locked. */
    public function invalidate(User $lockedUser): void
    {
        $lockedUser->account_version++;
        $lockedUser->setRememberToken(null);
        $lockedUser->verification_token = '';
        $this->save($lockedUser);
        DB::table('account_action_tokens')->where('user_id', $lockedUser->uid)->delete();
        $this->revokeOAuth($lockedUser->uid);
    }

    private function save(Model $model): void
    {
        if (!$model->save()) {
            throw new RuntimeException('Account change was cancelled.');
        }
    }

    public function delete(User $lockedUser): void
    {
        $this->invalidate($lockedUser);
        // Revoke owned clients as well as token grants made to the departing user.
        $clients = Passport::client()->newQuery()
            ->where('owner_type', $lockedUser->getMorphClass())
            ->where('owner_id', $lockedUser->uid);
        $tokens = Passport::token()->newQuery()->whereIn('client_id', (clone $clients)->select('id'));
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);
        Passport::authCode()->newQuery()->whereIn('client_id', (clone $clients)->select('id'))->update(['revoked' => true]);
        $clients->update(['revoked' => true]);

        $lockedUser->closet()->detach();
        $lockedUser->notifications()->delete();
        if (!$lockedUser->delete()) {
            throw new RuntimeException('Account removal was cancelled.');
        }
    }

    private function revokeOAuth(int $uid): void
    {
        $tokens = Passport::token()->newQuery()->where('user_id', $uid);
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);
        Passport::authCode()->newQuery()->where('user_id', $uid)->update(['revoked' => true]);
    }
}
