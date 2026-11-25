<?php

namespace App\Services\Auth;

final class OAuthRedirectUri
{
    /** Return the scheme of a permitted HTTPS or literal HTTP loopback URI. */
    public static function allowedScheme(#[\SensitiveParameter] string $uri): ?string
    {
        if (preg_match('~[\\\\\x00-\x20\x7f]~', $uri) !== 0 || !filter_var($uri, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] < 1)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return $scheme;
        }

        return $scheme === 'http' && in_array($parts['host'], ['127.0.0.1', '[::1]'], true) ? 'http' : null;
    }
}
