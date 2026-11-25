<?php

namespace App\Services\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

final class AccountThrottle
{
    public const CAPTCHA_AFTER = 4;

    public function loginAttempts(string $source): int
    {
        $entry = DB::table('account_rate_limits')->where('bucket', $this->key('login', 'source', $source))->first();

        return $entry && (int) $entry->expires_at > now()->timestamp ? (int) $entry->attempts : 0;
    }

    /** @return int Number of reserved attempts for this source within its window. */
    public function reserve(
        string $action,
        string $source,
        int $sourceLimit,
        int $sourceSeconds,
        ?string $subject = null,
        int $subjectLimit = 1,
        int $subjectSeconds = 60,
    ): int {
        $count = $this->claim($this->key($action, 'source', $source), $sourceLimit, $sourceSeconds);
        if ($subject !== null) {
            $this->subject($action, $subject, $subjectLimit, $subjectSeconds);
        }

        return $count;
    }

    public function subject(string $action, string $subject, int $limit, int $seconds): void
    {
        $this->claim($this->key($action, 'subject', $subject), $limit, $seconds);
    }

    private function claim(string $bucket, int $limit, int $seconds): int
    {
        [$count, $retry] = DB::transaction(function () use ($bucket, $limit, $seconds) {
            $now = now()->timestamp;
            $query = DB::table('account_rate_limits')->where('bucket', $bucket);
            DB::table('account_rate_limits')->insertOrIgnore(['bucket' => $bucket, 'attempts' => 0, 'expires_at' => $now + $seconds]);
            $entry = $query->lockForUpdate()->first();
            $active = (int) $entry->expires_at > $now;
            $attempts = $active ? (int) $entry->attempts : 0;
            if ($attempts >= $limit) {
                return [$attempts, max(1, (int) $entry->expires_at - $now)];
            }
            $query->update(['attempts' => ++$attempts, 'expires_at' => $active ? $entry->expires_at : $now + $seconds]);
            if (random_int(1, 64) === 1) {
                $expired = DB::table('account_rate_limits')->where('expires_at', '<=', $now)->limit(512)->pluck('bucket');
                DB::table('account_rate_limits')->whereIn('bucket', $expired)->where('expires_at', '<=', $now)->delete();
            }

            return [$attempts, 0];
        }, 3);
        if ($retry) {
            throw new HttpResponseException(json(__('Too Many Requests.'), 1)->setStatusCode(429)->header('Retry-After', (string) $retry));
        }

        return $count;
    }

    private function key(string $action, string $scope, string $value): string
    {
        return hash_hmac('sha256', $action."\0".$scope."\0".$value, (string) config('app.key'));
    }
}
