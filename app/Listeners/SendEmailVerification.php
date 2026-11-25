<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\AccountMail;

class SendEmailVerification
{
    public function __construct(private AccountMail $mail)
    {
    }

    public function handle(User $user): void
    {
        if (option('require_verification')) {
            $this->mail->verification($user->uid, $user->account_version);
        }
    }
}
