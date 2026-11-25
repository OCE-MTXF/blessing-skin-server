<?php

declare(strict_types=1);

namespace Tests\DatabaseLab;

use App\Models\User;
use App\Services\Auth\AccountActions;
use App\Services\Auth\AccountIdentity;
use App\Services\Auth\SendAccountMail;
use App\Services\Facades\Option;
use App\Services\Installation\KeyMaterial;
use App\Services\Installation\StateStore;
use App\Services\Textures\TextureBlobs;
use App\Services\Textures\TextureImages;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// The Windows runner invokes this from a disposable source-only project copy.
const STAMP = '2020-06-03 12:34:56';
const CALLBACK = 'https://callback.example.test/return';
const LEGACY_IDS = [11, 12];
const FRESH_EMAIL = 'fresh-admin@example.test';
const MODERN_EMAIL = 'modern@example.test';

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new LabFailure($label);
    }
}

final class LabFailure extends RuntimeException {}

function emit(string $label, string|int $value): void
{
    echo "$label=$value\n";
}

function secret(string $key): string
{
    $value = getenv('BS_DB_LAB_'.$key);
    check(is_string($value) && preg_match('/\A[a-f0-9]{48}\z/', $value) === 1, 'disposable credential missing');

    return $value;
}

function response(string $path): Response
{
    $kernel = app(HttpKernel::class);
    $request = Request::create($path, 'GET', server: [
        'HTTPS' => 'on', 'HTTP_HOST' => 'db-lab.example.test',
        'SERVER_NAME' => 'db-lab.example.test', 'SERVER_PORT' => 443,
    ]);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
}

function png(int $width, int $height, int $rgb): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, $rgb);
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

/** @return array{int, string, string, string} */
function fixture(string $kind): array
{
    return match ($kind) {
        'legacy-skin' => [41, 'steve', 'Legacy Skin', png(64, 64, 0x183450)],
        'legacy-cape' => [42, 'cape', 'Legacy Cape', png(64, 32, 0x314159)],
        'modern-skin' => [43, 'alex', 'Modern Skin', png(64, 64, 0x7103b8)],
        'modern-private' => [44, 'cape', 'Modern Private Cape', png(64, 32, 0x271828)],
    };
}

function putTexture(string $kind, int $uid, bool $legacy, bool $public = true): void
{
    [$id, $type, $name, $bytes] = fixture($kind);
    $digest = hash('sha256', $bytes);
    $blobs = app(TextureBlobs::class);
    if ($legacy) {
        $file = rtrim((string) getenv('TEXTURES_DIR'), '/\\').DIRECTORY_SEPARATOR.$digest;
        check(file_put_contents($file, $bytes, LOCK_EX) === strlen($bytes), 'legacy texture write');
    } else {
        $blobs->lock($digest);
        $blobs->publish($digest, $bytes);
    }
    DB::table('textures')->insert([
        'tid' => $id, 'name' => $name, 'type' => $type, 'hash' => $digest,
        'size' => (int) ceil(strlen($bytes) / 1024), 'uploader' => $uid, 'public' => $public, 'upload_at' => STAMP,
    ]);
    DB::table('user_closet')->insert(['user_uid' => $uid, 'texture_tid' => $id, 'item_name' => $name.' Closet']);
    if (!$legacy) {
        $blobs->reconcile($digest);
    }
}

function assertTexture(string $kind, int $uid, bool $public, bool $online): string
{
    [$id, $type, $name, $bytes] = fixture($kind);
    $digest = hash('sha256', $bytes);
    $texture = DB::table('textures')->where('tid', $id)->first();
    check($texture && $texture->hash === $digest && $texture->name === $name && $texture->type === $type
        && (int) $texture->uploader === $uid && (bool) $texture->public === $public, "$kind row contents");
    check(DB::table('user_closet')->where(['user_uid' => $uid, 'texture_tid' => $id, 'item_name' => $name.' Closet'])->count() === 1, "$kind closet link");
    $file = rtrim((string) getenv('TEXTURES_DIR'), '/\\').DIRECTORY_SEPARATOR.$digest;
    check(is_file($file) && hash_file('sha256', $file) === $digest, "$kind disk bytes");
    if ($online) {
        $result = response('/textures/'.$digest);
        check($result->getStatusCode() === ($public ? 200 : 404), "$kind HTTP visibility");
        if ($public) {
            // The raw route intentionally drops ancillary PNG chunks; the disk digest above must still match exactly.
            $body = $result->getContent();
            $expected = app(TextureImages::class)->inspect($bytes);
            check($result->headers->get('Content-Type') === 'image/png' && $body === $expected->decodeBytes,
                "$kind HTTP sanitized bytes");
            $original = imagecreatefromstring($bytes);
            $served = imagecreatefromstring($body);
            check($original && $served && imagesx($served) === imagesx($original) && imagesy($served) === imagesy($original),
                "$kind decoded image dimensions");
            for ($y = 0; $y < imagesy($original); $y++) {
                for ($x = 0; $x < imagesx($original); $x++) {
                    check(imagecolorat($served, $x, $y) === imagecolorat($original, $x, $y), "$kind decoded image pixels");
                }
            }
        }
    }
    emit(str_replace('-', '_', $kind).'_sha256', $digest);

    return $digest;
}

function seedLegacy(): void
{
    check(Schema::getTables(Schema::getCurrentSchemaName()) === [], 'legacy fixture needs an empty disposable database');
    $old = base_path('db-lab-baseline');
    $count = count(glob($old.'/migrations/*.php'));
    check($count === 20, 'baseline migration count');
    $migrator = app(Migrator::class);
    $migrator->getRepository()->createRepository();
    $passport = require __DIR__.'/LegacyPassport11.php';
    $passport($migrator->getRepository());
    $candidateOptions = config('options');
    $candidateVersion = config('app.version');
    config(['options' => require $old.'/options.php', 'app.version' => '6.0.2']);
    try {
        $migrator->run([$old.'/migrations']);
    } finally {
        config(['options' => $candidateOptions, 'app.version' => $candidateVersion]);
    }
    check(DB::table('migrations')->count() === $count + 5, 'legacy ledger count');
    Option::set(['site_name' => 'DB Lab Legacy', 'site_url' => 'https://db-lab.example.test']);
    foreach (LEGACY_IDS as $uid) {
        $admin = $uid === LEGACY_IDS[0];
        DB::table('users')->insert([
            'uid' => $uid, 'email' => $admin ? 'legacy-admin@example.test' : 'Legacy-User@example.test',
            'nickname' => $admin ? 'Legacy Admin' : 'Legacy User', 'score' => $admin ? 99 : 37,
            'password' => $admin ? password_hash(secret('LEGACY_ADMIN'), PASSWORD_BCRYPT) : hash('sha256', secret('LEGACY_USER')),
            'permission' => $admin ? User::SUPER_ADMIN : User::NORMAL, 'verified' => $admin,
            // Markers are not used to build browser cookies or links.
            'remember_token' => 'remember-marker', 'verification_token' => 'verification-marker',
            'ip' => '127.0.0.1', 'last_sign_at' => STAMP, 'register_at' => STAMP,
        ]);
    }
    putTexture('legacy-skin', 12, true);
    putTexture('legacy-cape', 12, true);
    DB::table('players')->insert(['pid' => 21, 'uid' => 12, 'name' => 'LegacyPlayer', 'tid_skin' => 41, 'tid_cape' => 42, 'last_modified' => STAMP]);
    DB::table('oauth_clients')->insert([
        'id' => 71, 'user_id' => 12, 'name' => 'Synthetic OAuth App', 'redirect' => CALLBACK, 'secret' => secret('LEGACY_CLIENT'),
        'personal_access_client' => false, 'password_client' => false, 'revoked' => false,
    ]);
    foreach (oauthRows() as [$table, $id, $fields]) {
        // Inert database fixture rows. No auth code, bearer, refresh token or session is issued.
        DB::table($table)->insert(['id' => $id, 'revoked' => false, 'expires_at' => STAMP] + $fields);
    }
    emit('legacy_migrations', $count + 5);
}

function oauthRows(): array
{
    return [
        ['oauth_auth_codes', str_repeat('c', 80), ['user_id' => 12, 'client_id' => 71, 'scopes' => '[]']],
        ['oauth_access_tokens', str_repeat('a', 80), ['user_id' => 12, 'client_id' => 71, 'scopes' => '[]']],
        ['oauth_refresh_tokens', str_repeat('f', 80), ['access_token_id' => str_repeat('a', 80)]],
    ];
}

function assertState(): void
{
    $version = config('app.version');
    $state = DB::table(StateStore::TABLE)->where('id', 1)->first();
    check(DB::table(StateStore::TABLE)->count() === 1 && $state->status === StateStore::INSTALLED
        && $state->version === $version && $state->target_version === $version
        && hash_equals(app(KeyMaterial::class)->digest(), $state->keys_digest), 'installed identity and version');
    $expected = array_map(static fn (string $path): string => basename($path, '.php'), glob(database_path('migrations/*.php')));
    $actual = DB::table('migrations')->whereIn('migration', $expected)->orderBy('migration')->pluck('migration')->all();
    sort($expected);
    check($expected === $actual, 'candidate migration ledger');
    check(DB::table('options')->where(['option_name' => 'version', 'option_value' => $version])->count() === 1, 'single target version option');
    check(DB::table('users')->where('permission', User::SUPER_ADMIN)->count() === 1, 'super administrator count');
    check(DB::table('account_security_mutex')->where('id', 1)->count() === 1, 'account security lock row');
    emit('release', $version);
    emit('candidate_migrations', count($actual));
}

function assertProfile(string $name, string $model, string $skin, ?string $cape): void
{
    $response = response('/'.$name.'.json');
    check($response->getStatusCode() === 200, "$name profile HTTP status");
    $body = json_decode($response->getContent(), true, 32, JSON_THROW_ON_ERROR);
    check($body === ['username' => $name, 'skins' => [$model => $skin], 'cape' => $cape], "$name profile texture links");
}

function assertLegacy(bool $upgraded, bool $modern): void
{
    $admin = User::find(11);
    $user = User::find(12);
    check($admin && $admin->email === 'legacy-admin@example.test' && $admin->permission === User::SUPER_ADMIN
        && $admin->verified && $admin->score === 99 && $admin->verifyPassword(secret('LEGACY_ADMIN')), 'legacy Bcrypt administrator');
    check($user && $user->email === 'Legacy-User@example.test' && $user->permission === User::NORMAL
        && !$user->verified && $user->score === 37 && $user->verifyPassword(secret('LEGACY_USER')), 'legacy SHA256 user');
    check(DB::table('users')->count() === ($modern ? 3 : 2), 'account count across legacy upgrade');
    check(DB::table('players')->where(['pid' => 21, 'uid' => 12, 'name' => 'LegacyPlayer', 'tid_skin' => 41, 'tid_cape' => 42])->count() === 1, 'legacy player links');
    check(DB::table('textures')->count() === ($modern ? 4 : 2) && DB::table('user_closet')->count() === ($modern ? 4 : 2), 'texture and closet counts');
    $skin = assertTexture('legacy-skin', 12, true, $upgraded);
    $cape = assertTexture('legacy-cape', 12, true, $upgraded);
    check(option('site_name') === 'DB Lab Legacy', 'legacy site option retained');
    foreach (oauthRows() as [$table, $id]) {
        $row = DB::table($table)->where('id', $id)->first();
        check($row && (bool) $row->revoked === $upgraded, "$table row retained with expected revocation");
    }
    $client = DB::table('oauth_clients')->where('id', 71)->first();
    check($client && $client->name === 'Synthetic OAuth App' && !(bool) $client->revoked, 'legacy OAuth client ID and name');
    if ($upgraded) {
        assertState();
        check($user->account_version === 1 && $user->email_key === AccountIdentity::emailKey($user->email)
            && AccountIdentity::findByEmail('legacy-user@example.test')?->uid === 12, 'legacy normalized identity');
        check(DB::table('users')->whereIn('uid', LEGACY_IDS)->whereNull('remember_token')->where('verification_token', '')->count() === 2,
            'legacy remember and verification markers cleared');
        check((int) $client->owner_id === 12 && $client->owner_type === User::class && $client->provider === 'users'
            && json_decode($client->redirect_uris, true) === [CALLBACK]
            && json_decode($client->grant_types, true) === ['authorization_code', 'refresh_token']
            && password_verify(secret('LEGACY_CLIENT'), $client->secret), 'legacy OAuth client transformed');
        assertProfile('LegacyPlayer', 'default', $skin, $cape);
    } else {
        check(!Schema::hasTable(StateStore::TABLE) && option('version') === '6.0.2', 'legacy state untouched');
        check((int) $client->user_id === 12 && $client->redirect === CALLBACK && hash_equals($client->secret, secret('LEGACY_CLIENT')), 'old client schema intact');
    }
    if ($modern) {
        $new = AccountIdentity::findByEmail(MODERN_EMAIL);
        check($new && $new->uid > 12 && $new->permission === User::NORMAL && $new->score === 13
            && $new->account_version === 1 && $new->verifyPassword(secret('MODERN'))
            && password_get_info($new->password)['algoName'] === 'argon2id', 'modern account Argon2id retained');
        check(DB::table('players')->where(['uid' => $new->uid, 'name' => 'ModernPlayer', 'tid_skin' => 43, 'tid_cape' => 0])->count() === 1, 'modern player links');
        $modernSkin = assertTexture('modern-skin', $new->uid, true, true);
        assertTexture('modern-private', $new->uid, false, true);
        assertProfile('ModernPlayer', 'slim', $modernSkin, null);
        check(DB::table('texture_blobs')->whereNull('unreferenced_at')->count() === 2, 'modern blob ledger');
    }
}

function seedModern(): void
{
    DB::transaction(static function (): void {
        $user = new User();
        $user->email = MODERN_EMAIL;
        $user->nickname = 'Modern User';
        $user->score = 13;
        $user->ip = '127.0.0.1';
        $user->permission = User::NORMAL;
        $user->register_at = STAMP;
        $user->last_sign_at = STAMP;
        check($user->changePassword(secret('MODERN')), 'modern account creation via password service');
        putTexture('modern-skin', $user->uid, false);
        putTexture('modern-private', $user->uid, false, false);
        DB::table('players')->insert(['uid' => $user->uid, 'name' => 'ModernPlayer', 'tid_skin' => 43, 'tid_cape' => 0, 'last_modified' => STAMP]);
    });
}

function queueIntegrity(): void
{
    // Queue the real job without a worker or delivery; the address is an inert fixture.
    $queue = 'db-lab-queue';
    $address = 'queue-canary@example.test';
    $job = new SendAccountMail(AccountActions::RESET, $address);
    $id = Queue::connection('database')->push($job, '', $queue);
    $row = DB::table('jobs')->where(['id' => $id, 'queue' => $queue])->first();
    check($row && !str_contains($row->payload, $address), 'queued command hides fixture address');
    $payload = json_decode($row->payload, true, 32, JSON_THROW_ON_ERROR);
    check($payload['data']['commandName'] === SendAccountMail::class
        && app(Encrypter::class)->decrypt($payload['data']['command']) === serialize($job), 'queued command authenticates and decrypts');
    $failed = app('queue.failer');
    $uuid = $failed->log('database', $queue, $row->payload, new RuntimeException('Synthetic queue failure record'));
    check($uuid === $payload['uuid'] && $failed->find($uuid)?->payload === $row->payload, 'failed-job provider preserves encrypted command');
    check($failed->forget($uuid) && DB::table('jobs')->where('id', $id)->delete() === 1, 'queue fixture cleanup');
    emit('database_queue_ciphertext', 'OK (no worker or delivery)');
}

function assertPrefix(string $prefix): void
{
    $tables = Schema::getTables(Schema::getCurrentSchemaName());
    check($tables !== [], 'schema populated');
    foreach ($tables as $table) {
        check(str_starts_with($table['name'], $prefix), 'all tables use configured prefix');
        check(strcasecmp($table['engine'], 'InnoDB') === 0, 'InnoDB table engine');
    }
    foreach (['users' => ['email_key'], 'players' => ['name']] as $table => $columns) {
        check(Schema::hasIndex($table, $columns, 'unique'), "$table unique index");
    }
    emit('prefix', $prefix === '' ? '(none)' : $prefix);
    emit('innodb_tables', count($tables));
}

try {
    $root = dirname(__DIR__, 2);
    $schema = (string) getenv('DB_DATABASE');
    $prefix = (string) getenv('DB_PREFIX');
    check(PHP_OS_FAMILY === 'Windows' && PHP_VERSION_ID === 80600 && is_file($root.'/db-lab-baseline/marker')
        && getenv('DB_HOST') === '127.0.0.1' && preg_match('/\Abs_lab_[a-f0-9]{12}_(fresh|freshpref|legacy|rollback|restore)\z/', $schema) === 1
        && in_array($prefix, ['', 'bs86_'], true), 'lab isolation guard');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    $server = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS db', [], false);
    check($server->db === $schema && preg_match('/\A11\.8\.10-MariaDB(?:-|\z)/', $server->version) === 1, 'MariaDB server version and selected database');
    $mode = $argv[1] ?? '';
    emit('scenario', $mode);
    if (in_array($mode, ['empty', 'old', 'wrong-key', 'fresh', 'upgraded', 'modern'], true)) {
        $status = in_array($mode, ['empty', 'old', 'wrong-key'], true) ? 503 : 200;
        $health = response('/healthz');
        emit('healthz', $health->getStatusCode());
        check($health->getStatusCode() === $status && $health->headers->hasCacheControlDirective('no-store'), 'health status/cache control');
    }
    switch ($mode) {
        case 'empty':
            check(Schema::getTables(Schema::getCurrentSchemaName()) === [], 'fresh database has no tables');
            emit('mariadb', $server->version);
            emit('php', PHP_VERSION);
            foreach (['laravel/framework', 'laravel/passport'] as $package) {
                emit($package, InstalledVersions::getPrettyVersion($package));
            }
            break;
        case 'seed-old':
            seedLegacy();
            break;
        case 'old':
            assertLegacy(false, false);
            break;
        case 'wrong-key':
            break;
        case 'fresh':
            assertState();
            assertPrefix($prefix);
            $admin = AccountIdentity::findByEmail(FRESH_EMAIL);
            check(DB::table('users')->count() === 1 && $admin && $admin->verified && $admin->permission === User::SUPER_ADMIN
                && $admin->verifyPassword(secret('FRESH')) && password_get_info($admin->password)['algoName'] === 'argon2id', 'fresh verified Argon2id administrator');
            break;
        case 'upgraded':
        case 'modern':
            assertPrefix($prefix);
            assertLegacy(true, $mode === 'modern');
            break;
        case 'seed-modern':
            seedModern();
            break;
        case 'queue':
            assertPrefix($prefix);
            queueIntegrity();
            break;
        default:
            throw new LabFailure('unknown scenario');
    }
    emit('assertions', 'OK');
} catch (Throwable $e) {
    // Never log exception messages from dependencies (they can contain bindings or secrets).
    fwrite(STDERR, ($e instanceof LabFailure ? $e->getMessage() : get_class($e).' at '.basename($e->getFile()).':'.$e->getLine())."\n");
    exit(1);
}
