<?php

namespace Tests\ServicesTest;

use App\Models\User;
use App\Services\Installation\DeploymentLock;
use App\Services\Installation\KeyMaterial;
use App\Services\Installation\Lifecycle;
use App\Services\Installation\LifecycleException;
use App\Services\Installation\MigrationPlan;
use App\Services\Installation\Readiness;
use App\Services\Installation\StateStore;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class InstallationLifecycleTest extends TestCase
{
    protected function initializeInstalledDatabase(): bool
    {
        return false;
    }

    public function testHttpCannotCreateStateAndAnOldMarkerIsNotReadiness(): void
    {
        file_put_contents(storage_path('install.lock'), '6.0.2');
        foreach (['/setup', '/setup/database', '/admin/update', '/admin/update/check'] as $path) {
            $this->get($path)->assertNotFound();
            $this->post($path, ['password' => 'synthetic only'])->assertNotFound();
        }
        $this->get('/')->assertStatus(503)->assertContent('Service Unavailable');
        $this->head('/healthz')->assertStatus(503);
        $this->get('/healthz')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([], Schema::getTableListing());
    }

    public function testCleanInstallCommitsOneArgonAdministratorAndVersionAndCannotRepeat(): void
    {
        $lifecycle = app(Lifecycle::class);
        $lifecycle->install('SITE-ADMIN@example.test', ' Example Admin ', 'throwaway-install-password');
        $this->get('/healthz')->assertOk()->assertContent('ready')->assertHeader('Cache-Control', 'no-store, private');
        $this->head('/healthz')->assertOk();
        $this->post('/setup')->assertNotFound();
        $this->get('/admin/update')->assertNotFound();

        $admin = User::query()->sole();
        $this->assertSame('site-admin@example.test', $admin->email);
        $this->assertSame('Example Admin', $admin->nickname);
        $this->assertSame(User::SUPER_ADMIN, $admin->permission);
        $this->assertTrue($admin->verified);
        $this->assertSame('argon2id', password_get_info($admin->password)['algoName']);
        $this->assertTrue(password_verify('throwaway-install-password', $admin->password));
        $this->assertSame(config('app.version'), app(MigrationPlan::class)->reportedVersion(DB::connection()));
        $this->assertFalse($lifecycle->upgrade(false), 'A complete matching release is a no-op.');
        $state = (array) StateStore::read(DB::connection());
        try {
            $lifecycle->install('other@example.test', 'Other Admin', 'never-create-this-admin');
            $this->fail('A second install should fail.');
        } catch (LifecycleException) {
            $this->assertSame($state, (array) StateStore::read(DB::connection()));
            $this->assertSame(1, User::query()->count());
        }
    }

    public function testMigrationFailureLeavesAClosedRetryableState(): void
    {
        $real = app(Migrator::class);
        $fault = $this->mock(Migrator::class);
        $fault->shouldReceive('usingConnection')->once()->with('sqlite', \Mockery::type('callable'))
            ->andThrow(new RuntimeException('Simulated migration interruption'));
        try {
            app(Lifecycle::class)->install('admin@example.test', 'Admin', 'throwaway-password');
            $this->fail('The simulated migration should fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated migration interruption', $error->getMessage());
        } finally {
            $this->app->instance(Migrator::class, $real);
        }

        $state = StateStore::read(DB::connection());
        $this->assertSame(StateStore::INSTALLING, $state->status);
        $this->assertNull($state->version);
        $this->assertFalse(Schema::hasTable('users'));
        $this->get('/healthz')->assertStatus(503);
        app(Lifecycle::class)->install('admin@example.test', 'Admin', 'throwaway-password');
        $this->get('/healthz')->assertOk();
        $this->assertSame(1, User::query()->count());
    }

    public function testPassportFileKeysUseTheSameIdentityAndFailClosedOnUnsafePosixModes(): void
    {
        $keys = app(KeyMaterial::class);
        $digest = $keys->digest();
        $private = storage_path('oauth-private.key');
        $public = storage_path('oauth-public.key');
        $this->assertNotFalse(file_put_contents($private, config('passport.private_key')));
        $this->assertNotFalse(file_put_contents($public, config('passport.public_key')));
        config(['passport.private_key' => null, 'passport.public_key' => null]);

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertTrue(chmod($private, 0600));
            $this->assertTrue(chmod($public, 0644));
            try {
                app(Lifecycle::class)->install('admin@example.test', 'Admin', 'throwaway-install-password');
                $this->fail('World-readable Passport file mode should fail before provisioning.');
            } catch (LifecycleException $error) {
                $this->assertStringContainsString('set both Passport key files to mode', $error->getMessage());
                $this->assertSame([], Schema::getTableListing());
            }
            $this->assertTrue(chmod($public, 0640));
        }

        $this->assertSame($digest, $keys->digest());
        app(Lifecycle::class)->install('admin@example.test', 'Admin', 'throwaway-install-password');
        $this->get('/healthz')->assertOk();
        $state = (array) StateStore::read(DB::connection());

        // A configured inline key must not silently select another file path.
        config(['passport.public_key' => 'file://'.$public]);
        $this->get('/healthz')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($state, (array) StateStore::read(DB::connection()));
        config(['passport.public_key' => null]);
        $this->get('/healthz')->assertOk();

        if (PHP_OS_FAMILY !== 'Windows') {
            foreach ([[$public, 0644], [$public, 0660], [$private, 0660]] as [$path, $mode]) {
                $this->assertTrue(chmod($path, $mode));
                $this->get('/healthz')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
                $this->assertSame($state, (array) StateStore::read(DB::connection()));
                $this->assertTrue(chmod($path, 0640));
                $this->get('/healthz')->assertOk();
            }
        }

        $this->assertTrue(unlink($public));
        $this->get('/healthz')->assertStatus(503);
        $this->assertSame($state, (array) StateStore::read(DB::connection()));
    }

    public function testIncompleteMigrationLedgerAndReplacementKeysFailClosed(): void
    {
        app(Lifecycle::class)->install('admin@example.test', 'Admin', 'throwaway-password');
        $readiness = app(Readiness::class);
        $this->assertTrue($readiness->isReady());
        $key = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->get('/healthz')->assertStatus(503);
        config(['app.key' => $key]);
        $this->assertTrue($readiness->isReady());

        $ledger = DB::table(MigrationPlan::repository());
        $row = $ledger->where('migration', '2026_11_25_000001_create_installation_state')->first();
        $ledger->where('migration', $row->migration)->delete();
        $this->get('/healthz')->assertStatus(503);
        $this->assertFalse($ledger->where('migration', $row->migration)->exists(), 'HTTP never repairs the database.');
        $this->expectException(LifecycleException::class);
        app(Lifecycle::class)->upgrade(false);
    }

    public function testIncompleteMailQueueSchemaCannotBeReady(): void
    {
        app(Lifecycle::class)->install('admin@example.test', 'Admin', 'throwaway-password');
        $this->get('/healthz')->assertOk();
        Schema::drop('failed_jobs');
        $this->get('/healthz')->assertStatus(503);
        $this->assertFalse(Schema::hasTable('failed_jobs'), 'HTTP cannot repair the queue schema.');
    }

    public function testPartialDatabaseIsNotAcceptedAsFreshOrAsLegacy(): void
    {
        Schema::create('foreign_data', static fn (Blueprint $table) => $table->id());
        $lifecycle = app(Lifecycle::class);
        foreach ([fn () => $lifecycle->install('admin@example.test', 'Admin', 'throwaway-password'), fn () => $lifecycle->upgrade(true)] as $operation) {
            try {
                $operation();
                $this->fail('Partial or unrelated schema must not be adopted.');
            } catch (LifecycleException) {
                $this->assertFalse(Schema::hasTable(StateStore::TABLE));
            }
        }
        $this->get('/healthz')->assertStatus(503);
    }

    public function testLocalSqliteLockRejectsAnOverlappingOperation(): void
    {
        $file = storage_path('lifecycle-test.sqlite');
        $a = new SQLiteConnection(new PDO('sqlite:'.$file), $file, '', ['driver' => 'sqlite']);
        $b = new SQLiteConnection(new PDO('sqlite:'.$file), $file, '', ['driver' => 'sqlite']);
        $lock = app(DeploymentLock::class);
        $lock->run($a, function () use ($lock, $b): void {
            try {
                $lock->run($b, fn () => $this->fail('The second operation must not start.'));
                $this->fail('Expected the SQLite lock to deny overlap.');
            } catch (LifecycleException $error) {
                $this->assertSame('Another lifecycle operation is active.', $error->getMessage());
            }
        });
        $this->assertSame('second instance', $lock->run($b, static fn () => 'second instance'));
        $this->assertFileExists($file.'.lifecycle.lock');
    }
}
