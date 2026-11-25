<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SendAccountMail implements ShouldQueue, ShouldBeEncrypted
{
    public int $tries = 1;
    public int $timeout = 25;
    public bool $failOnTimeout = true;
    public int $expiresAt = 0;

    public function __construct(public string $kind, public string|int $subject, public ?int $version = null)
    {
        // Discard stale work normally rather than retaining it in failed_jobs.
        $this->expiresAt = now()->timestamp + ($kind === AccountMail::ADDRESS_CHANGED ? 86400 : 900);
    }

    public function expired(): bool
    {
        return now()->timestamp >= $this->expiresAt;
    }

    public function handle(AccountMail $mail): void
    {
        $mail->deliver($this);
    }
}
