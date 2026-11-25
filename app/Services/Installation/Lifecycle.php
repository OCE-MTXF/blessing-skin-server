<?php

namespace App\Services\Installation;

use App\Models\User;
use App\Services\Auth\AccountIdentity;
use App\Services\Auth\PasswordCipher;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Lifecycle
{
    public function __construct(
        private DeploymentLock $lock,
        private KeyMaterial $keys,
        private MigrationPlan $plan,
        private RuntimeRequirements $runtime,
        private Migrator $migrator,
    ) {
    }

    public function install(string $email, string $nickname, #[\SensitiveParameter] string $password): void
    {
        $email = AccountIdentity::email($email);
        $nickname = trim($nickname);
        if (Validator::make(compact('email', 'nickname', 'password'), [
            'email' => 'required|email:rfc|max:100',
            'nickname' => 'required|string|max:50',
            'password' => 'required|string|min:8|max:1024',
        ])->fails()) {
            throw new LifecycleException('Provide a valid email, nickname (1–50 characters), and password (8–1024 characters).');
        }

        $db = DB::connection();
        $this->runtime->verify($db->getDriverName());
        $keys = $this->keys->digest();
        $version = config('app.version');
        $this->lock->run($db, function () use ($db, $keys, $version, $email, $nickname, $password): void {
            $schema = $db->getSchemaBuilder();
            $state = $schema->hasTable(StateStore::TABLE) ? StateStore::read($db) : null;
            if ($state === null) {
                if ($this->plan->hasExistingApplicationData($db)) {
                    throw new LifecycleException('Database is not empty; use the separately verified upgrade procedure.');
                }
                StateStore::ensure($db);
                StateStore::transition($db, StateStore::INSTALLING, null, $version, $keys);
            } elseif ($state->status !== StateStore::INSTALLING || $state->version !== null
                || !$this->matches($state, $version, $keys)) {
                throw new LifecycleException('Database is already installed or has a different pending lifecycle operation.');
            }

            $this->migrate($db);
            $db->transaction(function () use ($db, $keys, $version, $email, $nickname, $password): void {
                if ($db->table('users')->exists()) {
                    throw new LifecycleException('Installation requires an empty user table; restore the initial backup.');
                }
                $admin = new User();
                $admin->email = $email;
                $admin->nickname = $nickname;
                $admin->score = (int) config('options.user_initial_score');
                $admin->avatar = 0;
                $admin->password = app(PasswordCipher::class)->hash($password);
                $admin->ip = '127.0.0.1';
                $admin->permission = User::SUPER_ADMIN;
                $admin->register_at = now();
                $admin->last_sign_at = now()->subDay();
                $admin->verified = true;
                if (!$admin->save()) {
                    throw new LifecycleException('Could not create the administrator.');
                }
                $this->finish($db, $version, $keys);
            });
        });
    }

    public function upgrade(bool $adoptLegacy): bool
    {
        $db = DB::connection();
        $this->runtime->verify($db->getDriverName());
        $keys = $this->keys->digest();
        $target = config('app.version');

        return $this->lock->run($db, function () use ($db, $keys, $target, $adoptLegacy): bool {
            $schema = $db->getSchemaBuilder();
            $state = $schema->hasTable(StateStore::TABLE) ? StateStore::read($db) : null;
            if ($state === null) {
                if (!$adoptLegacy || !$this->plan->isLegacySixTwo($db)
                    || !version_compare('6.0.2', $target, '<')) {
                    throw new LifecycleException('Legacy adoption requires an intact Blessing Skin 6.0.2 database and explicit --adopt-legacy.');
                }
                StateStore::ensure($db);
                StateStore::transition($db, StateStore::UPGRADING, '6.0.2', $target, $keys);
            } else {
                if (!hash_equals($state->keys_digest, $keys) || !is_string($state->version)
                    || !in_array($state->status, [StateStore::INSTALLED, StateStore::UPGRADING], true)
                    || $this->plan->reportedVersion($db) !== $state->version) {
                    throw new LifecycleException('Installation identity or recorded version does not match; restore the coordinated backup.');
                }
                if ($state->status === StateStore::INSTALLED) {
                    if ($state->target_version !== $state->version || version_compare($state->version, $target, '>')) {
                        throw new LifecycleException('Downgrades and inconsistent state are not supported.');
                    }
                    if ($state->version === $target) {
                        if (!app(Readiness::class)->isReady()) {
                            throw new LifecycleException('Current version is incomplete; restore the coordinated backup.');
                        }

                        return false;
                    }
                    StateStore::transition($db, StateStore::UPGRADING, $state->version, $target, $keys);
                } elseif (!$this->matches($state, $target, $keys) || !version_compare($state->version, $target, '<')) {
                    throw new LifecycleException('A different upgrade is pending; restore the coordinated backup.');
                }
            }

            $this->migrate($db);
            $db->transaction(fn () => $this->finish($db, $target, $keys));

            return true;
        });
    }

    private function matches(object $state, string $target, string $keys): bool
    {
        return $state->target_version === $target && hash_equals($state->keys_digest, $keys);
    }

    private function migrate(Connection $db): void
    {
        $this->migrator->usingConnection($db->getName(), function (): void {
            if (!$this->migrator->repositoryExists()) {
                $this->migrator->getRepository()->createRepository();
            }
            // Do not load schema dumps, plugin migrations, or unrelated migration paths.
            $this->migrator->run([base_path(MigrationPlan::PATH)]);
        });
        if (!$this->plan->isComplete($db) || !$this->plan->hasRequiredSchema($db)) {
            throw new LifecycleException('The expected migration ledger or schema is incomplete.');
        }
    }

    private function finish(Connection $db, string $target, string $keys): void
    {
        if (!$db->table('users')->where('permission', User::SUPER_ADMIN)->exists()
            || $this->keys->digest() !== $keys) {
            throw new LifecycleException('Administrator or provisioned keys are not consistent.');
        }
        $db->table('options')->updateOrInsert(['option_name' => 'version'], ['option_value' => $target]);
        if ($this->plan->reportedVersion($db) !== $target) {
            throw new LifecycleException('Could not persist the installed release version.');
        }
        StateStore::transition($db, StateStore::INSTALLED, $target, $target, $keys);
    }
}
