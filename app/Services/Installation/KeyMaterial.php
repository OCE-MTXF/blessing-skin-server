<?php

namespace App\Services\Installation;

use Illuminate\Encryption\Encrypter;
use Laravel\Passport\Passport;

final class KeyMaterial
{
    public function digest(): string
    {
        $appKey = config('app.key');
        if (!is_string($appKey)) {
            throw new LifecycleException('Provision an instance-specific APP_KEY before running this command.');
        }
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true);
        }
        if (!is_string($appKey) || !Encrypter::supported($appKey, config('app.cipher'))) {
            throw new LifecycleException('Provision a valid instance-specific APP_KEY before running this command.');
        }

        $private = @openssl_pkey_get_private($this->pem('private'));
        $public = @openssl_pkey_get_public($this->pem('public'));
        $privateDetails = $private ? openssl_pkey_get_details($private) : false;
        $publicDetails = $public ? openssl_pkey_get_details($public) : false;
        if (!$privateDetails || !$publicDetails
            || $publicDetails['type'] !== OPENSSL_KEYTYPE_RSA || $publicDetails['bits'] < 2048
            || !hash_equals($privateDetails['key'], $publicDetails['key'])) {
            throw new LifecycleException('Provision a matching Passport RSA key pair (minimum 2048 bits).');
        }

        // Fingerprint the identity without recording PEM data or the application key.
        return hash_hmac('sha256', "blessing-skin:installation:v1\0".$publicDetails['key'], $appKey);
    }

    private function pem(string $type): string
    {
        $configured = config("passport.{$type}_key");
        if ($configured !== null && $configured !== '') {
            if (!is_string($configured)) {
                throw new LifecycleException('Configure inline Passport keys as PEM strings.');
            }

            return $this->requirePem(str_replace('\\n', "\n", $configured));
        }

        $path = Passport::keyPath("oauth-{$type}.key");
        $file = is_file($path) ? @fopen($path, 'rb') : false;
        if ($file === false) {
            throw new LifecycleException('Provision readable Passport key files outside the Web root.');
        }

        try {
            $metadata = fstat($file);
            if ($metadata === false || ($metadata['mode'] & 0170000) !== 0100000) {
                throw new LifecycleException('Passport keys must be regular files.');
            }
            // Passport checks both file keys on POSIX. Only the deployment owner may
            // write either key: the public key also determines which tokens we trust.
            if (PHP_OS_FAMILY !== 'Windows' && !in_array($metadata['mode'] & 07777, [0400, 0440, 0600, 0640], true)) {
                throw new LifecycleException('On POSIX, set both Passport key files to mode 0400, 0440, 0600, or 0640 and make them readable by PHP.');
            }
            $pem = @stream_get_contents($file, 16385);
            if ($pem === false) {
                throw new LifecycleException('Passport key files must be readable.');
            }

            return $this->requirePem($pem);
        } finally {
            fclose($file);
        }
    }

    private function requirePem(#[\SensitiveParameter] string $pem): string
    {
        if (strlen($pem) > 16384 || !str_starts_with(ltrim($pem, " \t\r\n"), '-----BEGIN ')) {
            throw new LifecycleException('Provision Passport key contents as PEM data of at most 16 KiB.');
        }

        return $pem;
    }
}
