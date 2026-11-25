<?php

namespace App\Services\Installation;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

final class StateStore
{
    public const TABLE = 'installation_state';
    public const INSTALLING = 'installing';
    public const INSTALLED = 'installed';
    public const UPGRADING = 'upgrading';

    public static function ensure(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasTable(self::TABLE)) {
            // Invoked under the deployment lock, before Laravel starts its migration ledger.
            $schema->create(self::TABLE, function (Blueprint $table) use ($db) {
                if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $table->engine = 'InnoDB';
                }
                $table->unsignedTinyInteger('id')->primary();
                $table->string('status', 16);
                $table->string('version', 64)->nullable();
                $table->string('target_version', 64);
                $table->char('keys_digest', 64);
                $table->timestamp('changed_at');
            });
        }
    }

    public static function read(Connection $db): ?object
    {
        return $db->table(self::TABLE)->useWritePdo()->where('id', 1)->first();
    }

    public static function transition(Connection $db, string $status, ?string $version, string $target, string $keys): void
    {
        $values = [
            'status' => $status,
            'version' => $version,
            'target_version' => $target,
            'keys_digest' => $keys,
            'changed_at' => now(),
        ];
        if (self::read($db) === null) {
            if (!$db->table(self::TABLE)->insert(['id' => 1] + $values)) {
                throw new LifecycleException('Could not persist the installation state.');
            }
        } elseif ($db->table(self::TABLE)->where('id', 1)->update($values) !== 1) {
            throw new LifecycleException('Could not persist the installation state.');
        }
    }
}
