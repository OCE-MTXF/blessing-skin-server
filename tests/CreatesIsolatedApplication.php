<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\AccountSecurity;
use App\Services\Installation\KeyMaterial;
use App\Services\Installation\MigrationPlan;
use App\Services\Installation\StateStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use RuntimeException;

trait CreatesIsolatedApplication
{
    public function createApplication(): Application
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'blessing-phpunit-'.bin2hex(random_bytes(12));
        foreach (['bootstrap/cache', 'app', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'textures'] as $dir) {
            if (!mkdir($path.'/'.$dir, 0700, true) && !is_dir($path.'/'.$dir)) {
                throw new RuntimeException('Could not initialize private PHPUnit directories.');
            }
        }
        // Prevent inherited deployment caches and APP_BASE_PATH from being loaded at bootstrap.
        $env = ['APP_BASE_PATH' => dirname(__DIR__), 'APP_ENV' => 'testing'];
        foreach (['CONFIG', 'SERVICES', 'PACKAGES', 'ROUTES', 'EVENTS'] as $name) {
            $env['APP_'.$name.'_CACHE'] = $path.'/bootstrap/cache/'.strtolower($name).'.php';
        }
        $saved = [];
        foreach ($env as $name => $value) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $cleanup = function () use ($saved, $path): void {
            foreach ($saved as $name => [$environment, $server]) {
                if ($environment === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $environment;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
            if (!is_link($path) && str_starts_with(basename($path), 'blessing-phpunit-')) {
                (new Filesystem())->deleteDirectory($path);
            }
        };

        try {
            $app = require __DIR__.'/../bootstrap/app.php';
            $app->addAbsoluteCachePathPrefix($path.DIRECTORY_SEPARATOR);
            $app->addAbsoluteCachePathPrefix($path.'/');
            $app->useStoragePath($path)->useBootstrapPath($path.'/bootstrap')->useEnvironmentPath($path);
            $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app) use ($path): void {
                [$private, $public] = self::testPassportKeys();
                $app['config']->set([
                    'app.env' => 'testing', 'app.debug' => false, 'app.url' => 'http://localhost', 'app.trusted_proxies' => '',
                    'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.maintenance' => ['driver' => 'file'],
                    'passport.private_key' => $private, 'passport.public_key' => $public,
                    'database.default' => 'sqlite',
                    'database.connections' => ['sqlite' => [
                        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
                    ]],
                    'cache.default' => 'array', 'session.driver' => 'array', 'session.secure' => false,
                    'mail.default' => 'array', 'logging.default' => 'null', 'queue.default' => 'sync',
                    'filesystems.default' => 'local', 'filesystems.disks.root.root' => $path,
                    'filesystems.disks.textures' => ['driver' => 'local', 'root' => $path.'/textures', 'throw' => true],
                    'plugins.directory' => null, 'plugins.approvals_file' => null, 'plugins.approvals_sha256' => null,
                    'plugins.catalog_file' => null, 'twigbridge.twig.debug' => false,
                ]);
            });
            $app->make(Kernel::class)->bootstrap();

            if ($this->initializeInstalledDatabase()) {
                $migrator = $app->make(Migrator::class);
                $migrator->usingConnection('sqlite', function () use ($app, $migrator): void {
                    $migrator->getRepository()->createRepository();
                    $migrator->run([$app->basePath(MigrationPlan::PATH)]);
                });
                $db = $app['db']->connection();
                $version = $app['config']->get('app.version');
                $db->table('options')->updateOrInsert(['option_name' => 'version'], ['option_value' => $version]);
                StateStore::transition($db, StateStore::INSTALLED, $version, $version, $app->make(KeyMaterial::class)->digest());
            }
        } catch (\Throwable $failure) {
            $cleanup();
            throw $failure;
        }
        $this->beforeApplicationDestroyed($cleanup);

        return $app;
    }

    // The framework's impersonation skips the Login event: simulate its web-session mark only
    // here. Tests for a missing mark can inject the guard user directly; stale ones use withSession.
    public function be(Authenticatable $user, $guard = null)
    {
        $guard ??= 'web';
        $result = parent::be($user, $guard);
        if ($guard === 'web' && $user instanceof User) {
            $this->withSession([AccountSecurity::SESSION_VERSION => $user->account_version]);
        }

        return $result;
    }

    protected function initializeInstalledDatabase(): bool
    {
        return true;
    }

    private static function testPassportKeys(): array
    {
        static $pair;
        if ($pair === null) {
            $config = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
            $windowsConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
            if (PHP_OS_FAMILY === 'Windows' && is_file($windowsConfig)) {
                $config['config'] = $windowsConfig;
            }
            $rsa = openssl_pkey_new($config);
            if (!$rsa || !openssl_pkey_export($rsa, $private, null, $config) || !$details = openssl_pkey_get_details($rsa)) {
                throw new RuntimeException('Could not generate isolated test-only Passport keys.');
            }
            $pair = [$private, $details['key']];
        }

        return $pair;
    }
}
