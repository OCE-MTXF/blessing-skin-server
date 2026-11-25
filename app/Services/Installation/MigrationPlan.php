<?php

namespace App\Services\Installation;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;

final class MigrationPlan
{
    public const PATH = 'database/migrations';

    public static function repository(): string
    {
        return config('database.migrations.table', config('database.migrations', 'migrations'));
    }

    public function isComplete(Connection $db): bool
    {
        $files = app(Migrator::class)->getMigrationFiles([base_path(self::PATH)]);
        if (!isset($files['2026_11_25_000001_create_installation_state'])) {
            return false;
        }
        $ran = $db->table(self::repository())->useWritePdo()->pluck('migration')->all();

        return array_diff(array_keys($files), $ran) === [] && $ran === array_values(array_unique($ran));
    }

    public function hasExistingApplicationData(Connection $db): bool
    {
        $schema = $db->getSchemaBuilder();
        $current = $schema->getCurrentSchemaName();
        $allowed = [$db->getTablePrefix().StateStore::TABLE];
        $tables = $schema->getTableListing($current, false);

        return array_diff($tables, $allowed) !== [] || $schema->getViews($current) !== [];
    }

    public function isLegacySixTwo(Connection $db): bool
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasTable(self::repository()) || !$schema->hasTable('users') || !$schema->hasTable('options')
            || !$db->table('users')->useWritePdo()->where('permission', \App\Models\User::SUPER_ADMIN)->exists()
            || $this->reportedVersion($db) !== '6.0.2') {
            return false;
        }
        $files = app(Migrator::class)->getMigrationFiles([base_path(self::PATH)]);
        $legacy = array_filter(array_keys($files), static fn (string $name) => strcmp($name, '2025_06_29_064944_add_max_texture_width_option') <= 0);
        $ran = $db->table(self::repository())->useWritePdo()->pluck('migration')->all();

        // Adoption is intentionally restricted to the complete previous release, not old or partial installs.
        return $legacy !== [] && array_diff($legacy, $ran) === [] && count($ran) === count(array_unique($ran))
            && array_intersect(array_diff(array_keys($files), $legacy), $ran) === [];
    }

    public function hasRequiredTables(Connection $db): bool
    {
        $schema = $db->getSchemaBuilder();
        $expected = [StateStore::TABLE, self::repository(), 'options', 'users', 'players', 'textures', 'texture_blobs',
            'user_closet', 'reports', 'notifications', 'jobs', 'failed_jobs', 'language_lines', 'scopes',
            'oauth_clients', 'oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens',
            'account_action_tokens', 'account_rate_limits', 'account_registration_sources', 'account_security_mutex'];
        $prefix = $db->getTablePrefix();
        $actual = $schema->getTableListing($schema->getCurrentSchemaName(), false);

        return array_diff(array_map(static fn (string $name) => $prefix.$name, $expected), $actual) === [];
    }

    public function hasRequiredSchema(Connection $db): bool
    {
        $schema = $db->getSchemaBuilder();
        if (!$this->hasRequiredTables($db)
            || !$schema->hasColumns('users', ['email_key', 'account_version'])
            || !$schema->hasColumns('oauth_clients', ['secret', 'grant_types', 'redirect_uris'])
            || !$schema->hasColumns('texture_blobs', ['hash', 'unreferenced_at'])
            || !$schema->hasColumns('jobs', ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
            || !$schema->hasColumns('failed_jobs', ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])) {
            return false;
        }
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $prefix = $db->getTablePrefix();
            foreach ($schema->getTables($schema->getCurrentSchemaName()) as $table) {
                if (in_array($table['name'], [$prefix.'users', $prefix.'options', $prefix.StateStore::TABLE], true)
                    && strcasecmp((string) $table['engine'], 'InnoDB') !== 0) {
                    return false;
                }
            }
        }

        return true;
    }

    public function reportedVersion(Connection $db): ?string
    {
        $versions = $db->table('options')->useWritePdo()->where('option_name', 'version')->pluck('option_value');

        return $versions->count() === 1 ? $versions->first() : null;
    }
}
