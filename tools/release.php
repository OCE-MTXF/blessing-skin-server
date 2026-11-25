<?php

// Local allowlisted release builder. Does not publish or deploy.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (PHP_VERSION !== '8.6.0' || !extension_loaded('zip')) {
    fwrite(STDERR, "Use PHP 8.6.0 with ext-zip.\n");
    exit(1);
}
$action = $argv[1] ?? null;
$buildArguments = $action === 'build' && ($argc === 6 || ($argc === 7 && $argv[6] === '--validate-only'));
if (!$buildArguments && (!in_array($action, ['stage', 'archive', 'validate'], true) || $argc !== 5)) {
    fwrite(STDERR, "Usage: php tools/release.php build <source> <output-directory> <version> <composer.phar> [--validate-only]\n");
    exit(1);
}
[, , $root, $destination, $version] = $argv;

function execute(array $command, string $directory, array $environment, bool $capture = false): string
{
    $process = proc_open($command, [STDIN, $capture ? ['pipe', 'w'] : STDOUT, STDERR], $pipes, $directory, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start release build step.');
    }
    $output = '';
    if ($capture) {
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Release build step failed: '.basename($command[0]));
    }

    return trim($output);
}

function cleanStage(string $stage): void
{
    if (!is_dir($stage)) {
        return;
    }
    if (is_link($stage) || realpath($stage) !== $stage || !preg_match('/^blessing-release-[a-f0-9]{32}$/D', basename($stage))) {
        throw new RuntimeException('Unexpected release staging path; not removing it.');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $path = $file->getPathname();
        if ($file->isLink() || !$file->isDir()) {
            unlink($path);
        } else {
            rmdir($path);
        }
    }
    rmdir($stage);
}

function build(string $root, string $destination, string $version, string $composerPath, bool $validateOnly, array $inventory): void
{
    $composer = realpath($composerPath);
    if ($composer === false || !is_file($composer)
        || hash_file('sha256', $composer) !== '04f8b003da4e3a2459056591fbfebd02b4dc3e5adeb158616b90cf55a65113ec') {
        throw new RuntimeException('Expected verified Composer 2.11.3 PHAR.');
    }
    if (!$validateOnly) {
        if ((!is_dir($destination) && !mkdir($destination, 0700, true)) || !($destination = realpath($destination))) {
            throw new RuntimeException('Invalid release output directory.');
        }
        $name = $destination.'/blessing-skin-server-'.$version.'.zip';
        if (file_exists($name) || file_exists($name.'.sha256')) {
            throw new RuntimeException('Refusing to overwrite an existing release archive or checksum.');
        }
    }

    $php = [PHP_BINARY];
    if (($ini = php_ini_loaded_file()) && (PHP_OS_FAMILY !== 'Windows' || !preg_match('/[^\x20-\x7e]/', $ini))) {
        array_push($php, '-c', $ini);
    }
    array_push($php, '-d', 'variables_order=EGPCS');
    $temp = realpath(sys_get_temp_dir()) ?: throw new RuntimeException('No private builder temporary directory.');
    if (PHP_OS_FAMILY === 'Windows' && preg_match('/[^\x20-\x7e]/', $temp)) {
        throw new RuntimeException('Use a private local ASCII-only TEMP directory on Windows for native build tools.');
    }
    $stage = $temp.DIRECTORY_SEPARATOR.'blessing-release-'.bin2hex(random_bytes(16));
    // Preserve only host tool and locale inputs, never deployment variables or user registries.
    $host = (array) getenv();
    $passthrough = preg_grep('/^(?:PATH|PATHEXT|SystemRoot|WINDIR|ComSpec|HOME|USERPROFILE|APPDATA|LOCALAPPDATA|TMP|TEMP|TMPDIR|LANG|LC_ALL)$/iD', array_keys($host));
    $environment = array_intersect_key($host, array_flip($passthrough));
    $environment += [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://localhost',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
        'COMPOSER_ALLOW_SUPERUSER' => '1', 'COMPOSER_HOME' => $stage.'/.composer-home',
        'NPM_CONFIG_USERCONFIG' => $stage.'/.npmrc.empty', 'NPM_CONFIG_GLOBALCONFIG' => $stage.'/.npmrc.global.empty',
        'NPM_CONFIG_CACHE' => $stage.'/.npm-cache', 'TZ' => 'UTC',
    ];
    $node = PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node';
    if (execute([$node, '--version'], $root, $environment, true) !== 'v24.21.0') {
        throw new RuntimeException('Expected Node v24.21.0.');
    }
    $npm = ['npm'];
    if (PHP_OS_FAMILY === 'Windows') {
        $nodePath = execute([$node, '-p', 'process.execPath'], $root, $environment, true);
        $npm = [$nodePath, dirname($nodePath).'/node_modules/npm/bin/npm-cli.js'];
    }
    if (execute([...$npm, '--version'], $root, $environment, true) !== '11.19.0') {
        throw new RuntimeException('Expected npm 11.19.0.');
    }
    try {
        stage($root, $stage, $inventory);
        // Avoid passing Unicode shell parameters to Windows children (PHP CLI applies the ANSI code page).
        if (!copy($composer, $stage.'/composer.phar') || hash_file('sha256', $stage.'/composer.phar') !== hash_file('sha256', $composer)) {
            throw new RuntimeException('Could not copy the verified Composer PHAR.');
        }
        execute([...$php, 'composer.phar', 'validate', '--strict', '--no-check-publish'], $stage, $environment);
        execute([...$php, 'composer.phar', 'install', '--prefer-dist', '--no-dev', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts', '--optimize-autoloader'], $stage, $environment);
        execute([...$php, 'composer.phar', 'check-platform-reqs', '--no-dev'], $stage, $environment);
        execute([...$php, 'artisan', 'package:discover', '--no-interaction', '--no-ansi'], $stage, $environment);
        execute([...$npm, 'ci', '--registry=https://registry.npmjs.org', '--ignore-scripts', '--no-audit', '--no-fund'], $stage, $environment);
        execute([...$npm, 'run', 'build'], $stage, $environment);
        archive($stage, $destination, $version, $inventory, $validateOnly);
    } finally {
        cleanStage($stage);
    }
}

function checkedFile(string $root, string $path): string
{
    $normalized = str_replace('\\', '/', $root);
    $parent = $root;
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '\\') || str_contains($part, ':')) {
            throw new RuntimeException('Invalid package path.');
        }
        $parent .= '/'.$part;
        $real = realpath($parent);
        if (is_link($parent) || !$real || str_replace('\\', '/', $real) !== $normalized.substr($parent, strlen($root))) {
            throw new RuntimeException("Missing or redirected package path: $path");
        }
    }
    if (!is_file($parent)) {
        throw new RuntimeException("Not a regular package file: $path");
    }

    return $parent;
}

function fileTree(string $root, string $directory, callable $include): array
{
    $initial = $root.'/'.$directory;
    if (is_link($initial) || !is_dir($initial)) {
        throw new RuntimeException("Missing or redirected package directory: $directory");
    }
    $paths = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($initial, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if ($file->isLink() || !$file->isFile()) {
            throw new RuntimeException("Refusing redirected or special file: $path");
        }
        if ($include($path)) {
            $paths[] = $path;
        }
    }

    return $paths;
}

function eligible(string $path): bool
{
    return !preg_match('~(?:^|/)(?:\.env(?:\.[^/]+)?|\.git|auth\.json|credentials(?:\.[^/]+)?)(?:/|$)|\.(?:pem|key|p12|pfx|crt|sqlite[\w.-]*|db)(?:$|/)~i', $path);
}

function tree(string $root, array $directories): array
{
    $paths = [];
    foreach ($directories as $directory) {
        $paths = array_merge($paths, fileTree($root, $directory, 'eligible'));
    }

    return $paths;
}

function requireVersion(string $root, string $version): void
{
    $code = file_get_contents(checkedFile($root, 'config/app.php'));
    $package = json_decode(file_get_contents(checkedFile($root, 'package.json')), true, 512, JSON_THROW_ON_ERROR);
    if (!preg_match('/^\d+\.\d+\.\d+$/D', $version) || ($package['version'] ?? null) !== $version
        || !str_contains($code, "'version' => '$version',")) {
        throw new RuntimeException('Version metadata does not match.');
    }
}

function stage(string $root, string $destination, array $inventory): void
{
    [$shared, $directories, $deployment] = $inventory;
    if (file_exists($destination) || !mkdir($destination, 0700)) {
        throw new RuntimeException('Expected a new private staging directory.');
    }
    $files = array_merge($shared, $deployment,
        ['package.json', 'package-lock.json', 'postcss.config.cjs', 'tsconfig.json', 'tsconfig.build.json', 'webpack.config.ts', 'tools/HtmlWebpackEnhancementPlugin.ts'],
        tree($root, [...$directories, 'database/factories', 'resources/assets/src']));
    foreach ($files as $path) {
        if (str_starts_with($path, 'resources/views/assets/') && str_ends_with($path, '.twig')) {
            continue; // Generated afresh by the locked frontend build.
        }
        $from = checkedFile($root, $path);
        $to = $destination.'/'.$path;
        if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0700, true)) {
            throw new RuntimeException("Could not create directory for $path");
        }
        if (!copy($from, $to)) {
            throw new RuntimeException("Could not stage $path");
        }
    }
    foreach (['bootstrap/cache', 'storage/app', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'storage/textures'] as $dir) {
        if (!mkdir($destination.'/'.$dir, 0700, true)) {
            throw new RuntimeException("Could not initialize directory $dir");
        }
    }
}

function archive(string $root, string $destination, string $version, array $inventory, bool $validateOnly): void
{
    [$shared, $directories, $deployment] = $inventory;
    if (!$validateOnly && !is_dir($destination)) {
        throw new RuntimeException('Invalid release output directory.');
    }
    $vendor = json_decode(file_get_contents(checkedFile($root, 'vendor/composer/installed.json')), true, 512, JSON_THROW_ON_ERROR);
    if (($vendor['dev'] ?? true) !== false || ($vendor['dev-package-names'] ?? null) !== []) {
        throw new RuntimeException('Package only an install made with composer --no-dev.');
    }
    checkedFile($root, 'public/app/favicon.ico');
    checkedFile($root, 'resources/views/assets/app.twig');
    $paths = array_merge($shared, $deployment, ['bootstrap/cache/packages.php', 'bootstrap/cache/services.php'],
        tree($root, $directories),
        fileTree($root, 'vendor', static fn (string $path) => eligible($path) && !str_starts_with($path, 'vendor/bin/')),
        fileTree($root, 'public/app', static function (string $path): bool {
            if (!preg_match('~^public/app/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*\.(?:js|css|woff2?|ttf|eot|ico|png|webp|svg|wasm|jpe?g|gif|avif|js\.LICENSE\.txt)$~D', $path)) {
                throw new RuntimeException("Unexpected generated public asset: $path");
            }

            return true;
        }));
    $paths = array_values(array_unique($paths));
    sort($paths, SORT_STRING);
    if ($validateOnly) {
        $digest = hash_init('sha256');
        foreach ($paths as $path) {
            $hash = hash_file('sha256', checkedFile($root, $path)) ?: throw new RuntimeException("Could not read $path");
            hash_update($digest, "$hash  $path\n");
        }
        fwrite(STDOUT, 'Validated '.count($paths).' packaged files, payload manifest SHA-256 '.hash_final($digest)."; no archive generated.\n");
        return;
    }
    $name = "blessing-skin-server-$version.zip";
    $output = rtrim($destination, '/\\').'/'.$name;
    if (file_exists($output) || file_exists($output.'.sha256')) {
        throw new RuntimeException('Refusing to overwrite an existing release archive or checksum.');
    }
    $zip = new ZipArchive();
    if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Could not create release ZIP.');
    }
    try {
        // libzip's DOS timestamps use the process C timezone, not PHP's timezone.
        // UTC0 is understood by both the Windows CRT and Unix libc.
        if (!putenv('TZ=UTC0')) {
            throw new RuntimeException('Could not set the archive timezone.');
        }
        $timestamp = 946684800;
        date_default_timezone_set('UTC');
        foreach (['storage/app', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'storage/textures'] as $dir) {
            if (!$zip->addEmptyDir($dir) || !$zip->setMtimeName($dir.'/', $timestamp)
                || !$zip->setExternalAttributesName($dir.'/', ZipArchive::OPSYS_UNIX, 0040700 << 16)) {
                throw new RuntimeException("Could not archive empty directory $dir");
            }
        }
        foreach ($paths as $path) {
            if (!$zip->addFile(checkedFile($root, $path), $path) || !$zip->setMtimeName($path, $timestamp)
                || !$zip->setCompressionName($path, ZipArchive::CM_DEFLATE, 9)
                || !$zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
                throw new RuntimeException("Could not archive $path");
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('Could not finish release ZIP.');
        }
        $hash = hash_file('sha256', $output);
        $sum = fopen($output.'.sha256', 'xb');
        if (!$hash || !$sum) {
            throw new RuntimeException('Could not write release checksum.');
        }
        fwrite($sum, "$hash  $name\n");
        fclose($sum);
        fwrite(STDOUT, "Created $output and SHA-256 checksum (no publish).\n");
    } catch (Throwable $failure) {
        if ($zip->status === ZipArchive::ER_OK && isset($zip->filename) && $zip->filename !== '') {
            @$zip->close();
        }
        if (is_file($output)) {
            unlink($output);
        }
        throw $failure;
    }
}

$shared = ['artisan', 'composer.json', 'composer.lock', 'LICENSE', 'README.md', 'README-zh.md', '.env.example', 'bootstrap/app.php', 'public/index.php', 'public/.htaccess'];
$directories = ['app', 'config', 'routes', 'database/migrations', 'resources/lang', 'resources/views', 'resources/misc/textures', 'resources/misc/backgrounds'];
$deployment = ['docs/deployment/README.md', 'docs/deployment/release.md', 'docs/deployment/windows-lab.md', 'docs/deployment/linux-apache.md',
    'docs/plugin-policy.md', 'docs/texture-policy.md',
    'deploy/windows/Lab.ps1', 'deploy/windows/nginx.conf.template', 'deploy/apache/native-https.conf.example'];
try {
    $root = realpath($root) ?: throw new RuntimeException('Invalid source directory.');
    requireVersion($root, $version);
    $inventory = [$shared, $directories, $deployment];
    if ($action === 'build') {
        build($root, $destination, $version, $argv[5], isset($argv[6]), $inventory);
    } elseif ($action === 'stage') {
        stage($root, $destination, $inventory);
    } else {
        archive($root, $destination, $version, $inventory, $action === 'validate');
    }
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage()."\n");
    exit(1);
}
