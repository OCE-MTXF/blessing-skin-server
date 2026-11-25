<?php

namespace App\Listeners;

use App\Http\Middleware\DetectLanguagePrefer;
use App\Models\User;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Http\Request;

final class SetAppLocale
{
    public function __construct(private Request $request)
    {
    }

    public function handle(Authenticated $event): void
    {
        if ($event->guard !== 'web' || !$event->user instanceof User
            || DetectLanguagePrefer::normalize($this->request->query->all()['lang'] ?? null) !== null
            || DetectLanguagePrefer::normalize($this->request->cookie('locale')) !== null) {
            return;
        }

        $locale = DetectLanguagePrefer::normalize($event->user->locale);
        if ($locale !== null) {
            app()->setLocale($locale);
        }
    }
}
