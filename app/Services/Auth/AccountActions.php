<?php

namespace App\Services\Auth;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use SensitiveParameter;

final class AccountActions
{
    public const RESET = 'reset';
    public const VERIFY = 'verify';

    public function __construct(private AccountSecurity $security)
    {
    }

    /** Issue only for a locked user, within AccountSecurity::serialized(). */
    public function issue(User $user, string $purpose): string
    {
        [$route, $parameter, $minutes] = match ($purpose) {
            self::RESET => ['auth.reset', 'uid', 30],
            self::VERIFY => ['auth.verify', 'user', 60],
        };
        $raw = bin2hex(random_bytes(32));
        // URL::route(..., false) must not trust the request Host, protocol, or prefix.
        $root = rtrim((string) config('app.url'), '/');
        $parts = parse_url($root);
        if (!$parts || !isset($parts['host']) || !filter_var($root, FILTER_VALIDATE_URL)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['scheme'] ?? '', app()->environment('local', 'testing') ? ['http', 'https'] : ['https'], true)) {
            throw new RuntimeException('Account mail requires a valid canonical application URL.');
        }
        $path = URL::route($route, [$parameter => $user->uid, 'token' => $raw], false);
        DB::table('account_action_tokens')->where('user_id', $user->uid)->where('purpose', $purpose)->delete();
        DB::table('account_action_tokens')->insert([
            'digest' => hash('sha256', $raw),
            'user_id' => $user->uid,
            'purpose' => $purpose,
            // Exact stored delivery address, not a client-supplied value.
            'email_digest' => hash('sha256', $user->email),
            'account_version' => $user->account_version,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        return $root.$path;
    }

    public function preview(int $uid, string $purpose, #[SensitiveParameter] mixed $raw): User
    {
        return $this->validUser($uid, $purpose, $raw, false);
    }

    public function consume(int $uid, string $purpose, #[SensitiveParameter] mixed $raw, Closure $operation): mixed
    {
        return $this->security->serialized(function () use ($uid, $purpose, $raw, $operation) {
            $user = $this->validUser($uid, $purpose, $raw, true);
            $count = DB::table('account_action_tokens')->where('digest', hash('sha256', $raw))
                ->whereNull('consumed_at')->where('expires_at', '>', now())->update(['consumed_at' => now()]);
            abort_unless($count === 1, 403, $this->message($purpose));

            return $operation($user);
        });
    }

    private function validUser(int $uid, string $purpose, #[SensitiveParameter] mixed $raw, bool $lock): User
    {
        abort_unless(is_string($raw) && preg_match('/\A[0-9a-f]{64}\z/', $raw), 403, $this->message($purpose));
        $user = ($lock ? User::lockForUpdate() : User::query())->find($uid);
        $record = DB::table('account_action_tokens')->where('digest', hash('sha256', $raw))
            ->where('user_id', $uid)->where('purpose', $purpose)->whereNull('consumed_at')->where('expires_at', '>', now());
        if ($lock) {
            $record->lockForUpdate();
        }
        $record = $record->first();
        abort_unless($user && $record && $user->permission !== User::BANNED && AccountIdentity::canMail($user->email)
            && $user->account_version === (int) $record->account_version
            && hash_equals($record->email_digest, hash('sha256', $user->email)), 403, $this->message($purpose));

        return $user;
    }

    private function message(string $purpose): string
    {
        return trans($purpose === self::RESET ? 'auth.reset.invalid' : 'auth.verify.invalid');
    }
}
