<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DetectLanguagePrefer
{
    public function handle(Request $request, Closure $next): Response
    {
        $requested = self::normalize($request->query->all()['lang'] ?? null);
        $locale = $requested ?? self::normalize($request->cookie('locale'))
            ?? self::normalize($request->getPreferredLanguage()) ?? config('app.fallback_locale');
        app()->setLocale($locale);

        $response = $next($request);
        if ($requested !== null && $request->isMethod('GET')
            && in_array('web', $request->route()?->middleware() ?? [], true)) {
            $response->headers->setCookie(cookie('locale', $requested, 120, '/', null, (bool) config('session.secure'), true, false, 'lax'));
        }

        return $response;
    }

    public static function normalize(mixed $locale): ?string
    {
        $locales = config('locales');
        if (!is_string($locale) || !isset($locales[$locale])) {
            return null;
        }

        return $locales[$locale]['alias'] ?? $locale;
    }
}
