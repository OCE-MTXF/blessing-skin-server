<?php

namespace App\Services\Installation;

use PDO;

final class RuntimeRequirements
{
    public function verify(string $driver): void
    {
        if (PHP_VERSION_ID < 80600 || PHP_VERSION_ID >= 80700) {
            throw new LifecycleException('Use PHP 8.6 and check the locked platform requirements.');
        }
        $gd = function_exists('gd_info') ? gd_info() : [];
        if (empty($gd['PNG Support']) || empty($gd['WebP Support'])
            || !in_array('argon2id', password_algos(), true)
            || !in_array($driver === 'mariadb' ? 'mysql' : $driver, PDO::getAvailableDrivers(), true)) {
            throw new LifecycleException('Enable GD PNG/WebP, Argon2id and the selected PDO driver.');
        }

        $url = config('app.url');
        $parts = is_string($url) ? parse_url($url) : false;
        $local = app()->environment('local', 'testing');
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)
            || (($parts['scheme'] ?? '') !== 'https'
                && (!$local || ($parts['scheme'] ?? '') !== 'http'
                    || !in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)))) {
            throw new LifecycleException('Set APP_URL to the canonical HTTPS origin (loopback HTTP only in local/testing).');
        }
        if (!$local && config('app.debug')) {
            throw new LifecycleException('Disable APP_DEBUG on deployed instances.');
        }
    }
}
