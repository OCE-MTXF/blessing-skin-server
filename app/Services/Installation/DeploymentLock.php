<?php

namespace App\Services\Installation;

use Illuminate\Database\Connection;
use PDO;
use Throwable;

final class DeploymentLock
{
    public function run(Connection $db, callable $operation): mixed
    {
        if ($db->transactionLevel() !== 0 || $db->getConfig('read') || $db->getConfig('write')) {
            throw new LifecycleException('Use a direct database connection for installation and upgrade.');
        }

        $pdo = $db->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new LifecycleException('Use a non-persistent database session for installation and upgrade.');
        }

        $release = match ($db->getDriverName()) {
            'sqlite' => $this->lockSqlite($db),
            'mysql', 'mariadb' => $this->lockMysql($pdo, $db->getTablePrefix()),
            'pgsql' => $this->lockPostgres($pdo, $db->getTablePrefix()),
            default => throw new LifecycleException('Unsupported lifecycle database driver.'),
        };

        // Never transparently reconnect: the new session would not hold the database lock.
        $db->setReconnector(static fn () => throw new LifecycleException('Database connection lost during lifecycle operation.'));
        try {
            return $operation();
        } finally {
            try {
                $release();
            } catch (Throwable) {
                // The driver releases session locks when the (non-persistent) session closes.
            } finally {
                $db->setReconnector(static function (Connection $connection) {
                    $connection->setPdo(app('db')->reconnect($connection->getNameWithReadWriteType())->getRawPdo());
                });
            }
        }
    }

    private function lockSqlite(Connection $db): callable
    {
        // In-memory databases have no peer process. Disk SQLite must use one local filesystem.
        if ($db->getDatabaseName() === ':memory:' && app()->environment('testing')) {
            return static fn () => null;
        }
        $path = realpath($db->getDatabaseName());
        if (!$path || !is_file($path)) {
            throw new LifecycleException('Use an existing, local SQLite database file.');
        }
        $lock = @fopen($path.'.lifecycle.lock', 'c+b');
        if (!$lock) {
            throw new LifecycleException('Cannot access the SQLite lifecycle lock file.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new LifecycleException('Another lifecycle operation is active.');
        }

        // Never unlink the lock file: another process could already have this inode open.
        return static function () use ($lock): void {
            flock($lock, LOCK_UN);
            fclose($lock);
        };
    }

    private function lockMysql(PDO $pdo, string $prefix): callable
    {
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') {
            throw new LifecycleException('No active lifecycle database selected.');
        }
        $key = hash('sha256', 'blessing-skin:lifecycle:'.strtolower($database).'|'.strtolower($prefix));
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$key]);
        $acquired = (int) $lock->fetchColumn() === 1;
        $lock->closeCursor();
        if (!$acquired) {
            throw new LifecycleException('Another lifecycle operation is active or the database lock is unavailable.');
        }

        return static function () use ($pdo, $key): void {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);
        };
    }

    private function lockPostgres(PDO $pdo, string $prefix): callable
    {
        $schema = $pdo->query('SELECT current_schema()')->fetchColumn();
        if (!is_string($schema) || $schema === '') {
            throw new LifecycleException('No active lifecycle database schema selected.');
        }
        // Postgres advisory locks are scoped to the database. Use the effective schema as well.
        $key = hash('sha256', 'blessing-skin:lifecycle:'.$schema.'|'.$prefix);
        $lock = $pdo->prepare('SELECT CAST(pg_try_advisory_lock(hashtextextended(?, 0)) AS int)');
        $lock->execute([$key]);
        $acquired = (int) $lock->fetchColumn() === 1;
        $lock->closeCursor();
        if (!$acquired) {
            throw new LifecycleException('Another lifecycle operation is active or the database lock is unavailable.');
        }

        return static function () use ($pdo, $key): void {
            $pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(?, 0))')->execute([$key]);
        };
    }
}
