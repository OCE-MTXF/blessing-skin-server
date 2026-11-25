<?php

namespace App\Services;

final class ImageUrl
{
    /** Image settings are browser URLs, not server-side fetch instructions or CSS. */
    public static function allowed(mixed $url): ?string
    {
        if (!is_string($url) || $url === '' || strlen($url) > 2048 || !mb_check_encoding($url, 'UTF-8')
            || preg_match('/[\x00-\x20\x7f]/', $url) || str_contains($url, '\\')
            || str_starts_with($url, '//') || preg_match('/%(?![0-9a-f]{2})/i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['scheme'])) {
            return str_starts_with(strtolower($url), 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false
                ? $url
                : null;
        }

        return !isset($parts['host']) && ($parts['path'] ?? '') !== '' ? $url : null;
    }
}
