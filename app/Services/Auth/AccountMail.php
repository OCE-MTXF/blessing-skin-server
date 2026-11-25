<?php

namespace App\Services\Auth;

use App\Mail\EmailVerification;
use App\Mail\ForgotPassword;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class AccountMail
{
    public const ADDRESS_CHANGED = 'address_changed';

    public function __construct(private AccountActions $actions, private AccountSecurity $security)
    {
    }

    public function reset(string $email): void
    {
        // Schedule the same work, with the same outward result, for every identifier.
        $key = AccountIdentity::emailKey($email);
        if ($key !== null) {
            $this->dispatch(new SendAccountMail(AccountActions::RESET, $key));
        }
    }

    public function verification(int $uid, int $version): void
    {
        $this->dispatch(new SendAccountMail(AccountActions::VERIFY, $uid, $version));
    }

    public function notifyAddressChange(string $oldEmail): void
    {
        $this->dispatch(new SendAccountMail(self::ADDRESS_CHANGED, $oldEmail));
    }

    private function dispatch(SendAccountMail $job): void
    {
        if (!$this->enabled()) {
            return;
        }
        try {
            Bus::dispatch($job);
        } catch (Throwable) {
            Log::warning('An account message could not be queued.');
        }
    }

    public function enabled(): bool
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        $queueDriver = config('queue.connections.'.config('queue.default').'.driver');
        $async = in_array($queueDriver, ['database', 'redis'], true);

        // Real delivery must not run in the originating request. Only the
        // in-memory mailer may use sync, and only in an isolated environment.
        if ($transport === 'array') {
            return app()->environment('testing', 'local') && ($async || $queueDriver === 'sync');
        }

        // A sensitive message must not end up in an application log (including failover).
        return $async && in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true);
    }

    /** A queue worker resolves the stored address and current account state, not request data. */
    public function deliver(SendAccountMail $job): void
    {
        if ($job->expired() || !$this->enabled()) {
            return;
        }
        try {
            if ($job->kind === self::ADDRESS_CHANGED) {
                $address = (string) $job->subject;
                if (AccountIdentity::canMail($address)) {
                    Mail::send('mails.address-changed', [], fn ($message) => $message->to($address)->subject(__('Account email address changed')));
                }

                return;
            }
            $message = $this->security->serialized(function () use ($job) {
                if ($job->expired()) {
                    return null;
                }
                if ($job->kind === AccountActions::RESET) {
                    $user = User::where('email_key', $job->subject)->first();
                    if ($user) {
                        event('auth.forgot.ready', [$user]);
                    }
                } elseif ($job->kind === AccountActions::VERIFY) {
                    $user = User::find($job->subject);
                    if ($user && ((int) $job->version !== $user->account_version || $user->verified || !option('require_verification'))) {
                        return null;
                    }
                } else {
                    return null;
                }
                if (!$user || $user->permission === User::BANNED || !AccountIdentity::canMail($user->email)) {
                    return null;
                }
                $url = $this->actions->issue($user, $job->kind);

                return [$user->email, $job->kind === AccountActions::RESET ? new ForgotPassword($url) : new EmailVerification($url)];
            });
            if ($message !== null) {
                Mail::to($message[0])->send($message[1]);
            }
        } catch (Throwable) {
            // Transport exceptions can contain the email body or credentials. Do not log/report them.
            Log::warning('An account message could not be delivered.');
        }
    }
}
