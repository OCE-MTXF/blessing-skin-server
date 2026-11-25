#!/usr/bin/env python3
"""Offline Windows MariaDB acceptance lab. No input database, services or project secrets."""

import argparse
import base64
import csv
from concurrent.futures import ThreadPoolExecutor
import hashlib
import io
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import time
import zipfile

BASELINE = '14035c50621b4495c7543d7fda4a194395f3aaea'
BASELINE_FIXTURE_BYTES = 10924
BASELINE_FIXTURE_SHA256 = 'c11ade58cebfb3fc4b5b64a96b7dfde139b9e9900c1f0d380b949ba1f685f0ff'
BASELINE_FILE = re.compile(r'config/options\.php|database/migrations/[a-z0-9_]+\.php')
ZIP_SHA256 = '05d04b46763ea3dc9111bfc82dc061e0a158c682a73d31ba34b13f2653c88d17'
PREFIX = 'bs86_'
SCENARIOS = ('fresh', 'freshpref', 'legacy', 'rollback', 'restore')
SOURCE = Path(__file__).resolve().parents[2]
WINDOWS = Path(os.environ.get('SystemRoot', r'C:\Windows')) / 'System32'


class LabError(RuntimeError):
    pass


def require(condition, message):
    if not condition:
        raise LabError(message)


def digest(path):
    with path.open('rb') as f:
        return hashlib.file_digest(f, 'sha256').hexdigest()


def baseline_fixture_files(path):
    with path.open('rb') as stream:
        data = stream.read(BASELINE_FIXTURE_BYTES + 1)
    require(len(data) == BASELINE_FIXTURE_BYTES and hashlib.sha256(data).hexdigest() == BASELINE_FIXTURE_SHA256,
            'Legacy schema fixture does not match the verified export')
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        entries = archive.infolist()
        require(len(entries) == 24 and len({entry.filename for entry in entries}) == 24,
                'Unexpected legacy schema fixture inventory')
        require({entry.filename for entry in entries if entry.is_dir()} == {'config/', 'database/', 'database/migrations/'},
                'Unexpected legacy schema fixture directories')
        files = {entry.filename: archive.read(entry) for entry in entries if not entry.is_dir()}
    require('config/options.php' in files and all(BASELINE_FILE.fullmatch(name) for name in files),
            'Unexpected legacy schema fixture path')
    return files


def reparse(path):
    return bool(path.lstat().st_file_attributes & stat.FILE_ATTRIBUTE_REPARSE_POINT)


def file_manifest(root):
    paths = sorted(Path(folder) / name for folder, dirs, files in os.walk(root) for name in files)
    with ThreadPoolExecutor(max_workers=12) as pool:
        return ''.join(f'{sha}  {path.relative_to(root).as_posix()}\n' for path, sha in zip(paths, pool.map(digest, paths)))


def verify_plain_tree(root):
    require(root.is_dir() and not reparse(root), 'Source folder missing or redirected')
    pending = [root]
    while pending:
        with os.scandir(pending.pop()) as entries:
            for entry in entries:
                if entry.name == '.git':
                    continue
                require(not entry.stat(follow_symlinks=False).st_file_attributes & stat.FILE_ATTRIBUTE_REPARSE_POINT,
                        'Source allowlist contains a Windows reparse point')
                if entry.is_dir(follow_symlinks=False):
                    pending.append(entry.path)


def copy_file(src, dst):
    require(not reparse(src), 'Source allowlist contains a Windows reparse point')
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(src, dst)


def copy_tree(src, dst):
    require(src.is_dir() and not reparse(src), 'Source folder missing or redirected')
    dst.mkdir(parents=True, exist_ok=False)
    for path in src.iterdir():
        if path.name == '.git':
            continue
        require(not reparse(path), 'Source allowlist contains a Windows reparse point')
        if path.is_dir():
            copy_tree(path, dst / path.name)
        else:
            copy_file(path, dst / path.name)


class Lab:
    def __init__(self, args, root, log):
        self.args, self.root, self.log = args, root, log
        self.id = secrets.token_hex(6)
        self.stage = root / 'source'
        self.runtime = root / 'mariadb'
        self.data = root / 'data'
        self.port = None
        self.server = None
        self.owner_verified = False
        self.db_password = secrets.token_hex(24)
        self.root_password = secrets.token_hex(24)
        self.app_secrets = {key: secrets.token_hex(24) for key in
                            ('FRESH', 'LEGACY_ADMIN', 'LEGACY_USER', 'LEGACY_CLIENT', 'MODERN')}
        self.redact = [self.db_password, self.root_password, *self.app_secrets.values()]
        # Native paths only; do not inherit any app, DB, PHP, Git, proxy, mail or cloud config.
        self.env = {key: os.environ[key] for key in
                    ('SYSTEMROOT', 'PATH', 'SYSTEMDRIVE', 'COMSPEC', 'WINDIR') if key in os.environ}
        self.env.update(TEMP=str(root), TMP=str(root), HOME=str(root), USERPROFILE=str(root),
                        GIT_CONFIG_NOSYSTEM='1', GIT_CONFIG_GLOBAL='NUL', PHP_INI_SCAN_DIR='', MSYS2_ARG_CONV_EXCL='*')

    def text(self, message):
        for value in self.redact:
            message = message.replace(value, '[redacted]')
        # PEMs and large/interactive output must never be retained in evidence.
        message = re.sub(r'-----BEGIN [A-Z ]+-----.*?-----END [A-Z ]+-----', '[PEM redacted]', message, flags=re.S)
        self.log.write(message + '\n')
        self.log.flush()
        print(message, flush=True)

    def run(self, args, *, cwd=None, env=None, data=None, input_file=None, expected=0, contains=None,
            record=True, show=False, timeout=90):
        args = [str(arg) for arg in args]
        if record:
            self.text(f'+ [{cwd or self.root}] {subprocess.list2cmdline(args)}')
        with input_file.open('rb') if input_file else open(os.devnull, 'rb') as input_handle:
            options = {'stdin': input_handle} if data is None else {'input': data}
            try:
                p = subprocess.run(args, cwd=cwd or self.root, env=env or self.env,
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout,
                                   creationflags=subprocess.CREATE_NO_WINDOW, **options)
            except subprocess.TimeoutExpired as e:
                if record:
                    self.text('exit=timeout')
                raise LabError(f'Timed out: {Path(args[0]).name}') from e
        if record:
            self.text(f'exit={p.returncode}')
        output = (p.stdout + p.stderr).decode('utf-8', 'replace').strip()
        if show and output:
            self.text(output[:4096])
        require(p.returncode == expected, f'{Path(args[0]).name} returned {p.returncode}, expected {expected}')
        if contains:
            require(contains in output, f'{Path(args[0]).name} did not report the expected outcome')
        return p.stdout

    def restrict(self):
        # This changes only the newly created temp directory, before any secrets are written.
        user = self.run([WINDOWS / 'whoami.exe', '/user', '/fo', 'csv', '/nh'], record=False)
        sid = next(csv.reader(io.StringIO(user.decode('ascii', 'replace').strip())))[1]
        require(re.fullmatch(r'S-[0-9-]+', sid), 'Could not identify the local Windows user SID')
        self.run([WINDOWS / 'icacls.exe', self.root, '/inheritance:r', '/grant:r',
                  f'*{sid}:(OI)(CI)F', '*S-1-5-18:(OI)(CI)F'], record=False)
        self.text('workspace ACL: current identity and SYSTEM; newly created outside source tree')

    def stage_source(self):
        for folder in ('app', 'config', 'routes', 'database/migrations', 'public/app', 'resources/lang',
                       'resources/views', 'resources/misc', 'tests/DatabaseLab'):
            copy_tree(SOURCE / folder, self.stage / folder)
        vendor = SOURCE / 'vendor'
        verify_plain_tree(vendor)
        self.run([WINDOWS / 'robocopy.exe', vendor, self.stage / 'vendor', '/E', '/XJ', '/XD', '.git', '/XF', '.git',
                  '/COPY:DAT', '/DCOPY:D', '/R:0', '/W:0', '/MT:24', '/NFL', '/NDL', '/NP', '/NJH', '/NJS'],
                 expected=1, timeout=900)
        for name in ('artisan', 'bootstrap/app.php', 'composer.json', 'composer.lock', 'public/index.php'):
            copy_file(SOURCE / name, self.stage / name)
        if self.args.legacy_fixture is None:
            self.text(f'candidate HEAD={self.git("rev-parse", "HEAD").decode().strip()} (working-tree bytes below)')
        else:
            self.text('candidate source snapshot (working-tree bytes below); no Git objects required')
        manifest = file_manifest(self.stage)
        manifest_path = self.args.evidence.with_suffix('.manifest.sha256')
        with manifest_path.open('x', encoding='utf-8', newline='\n') as f:
            f.write(manifest)
        self.text(f'candidate staged files={manifest.count(chr(10))}; manifest sha256={digest(manifest_path)}')
        self.text(f'composer.lock sha256={digest(self.stage / "composer.lock")}')
        self.text(f'runner sha256={digest(Path(__file__))}')
        (self.stage / 'bootstrap' / 'cache').mkdir()
        old = self.stage / 'db-lab-baseline'
        (old / 'migrations').mkdir(parents=True)
        (old / 'marker').write_text(self.id, encoding='ascii')
        self.text(f'legacy migration Git baseline={BASELINE}')
        if self.args.legacy_fixture is not None:
            for name, content in baseline_fixture_files(self.args.legacy_fixture).items():
                target = old / ('options.php' if name == 'config/options.php' else f'migrations/{PurePosixPath(name).name}')
                target.write_bytes(content)
            self.text(f'legacy schema export SHA-256={BASELINE_FIXTURE_SHA256}')
        else:
            self.stage_git_baseline(old)
        self.text(f'legacy Git fixture sha256={hashlib.sha256(file_manifest(old / "migrations").encode()).hexdigest()}')
        self.text(f'legacy options sha256={digest(old / "options.php")}')

    def stage_git_baseline(self, old):
        names = self.git('ls-tree', '-r', BASELINE, 'config/options.php', 'database/migrations').decode('ascii').splitlines()
        require(len(names) == 21, 'Expected 20 baseline application migrations and one options file')
        archive = self.git('archive', '--format=zip', BASELINE, 'config/options.php', 'database/migrations')
        with zipfile.ZipFile(io.BytesIO(archive)) as files:
            for line in names:
                match = re.fullmatch(r'100644 blob ([0-9a-f]{40})\t(config/options\.php|database/migrations/[a-z0-9_]+\.php)', line)
                require(match is not None, 'Unexpected baseline file path or mode')
                sha, name = match.groups()
                content = files.read(name)
                require(hashlib.sha1(f'blob {len(content)}\0'.encode('ascii') + content).hexdigest() == sha,
                        'Baseline file differs from the Git blob')
                target = old / ('options.php' if name == 'config/options.php' else f'migrations/{PurePosixPath(name).name}')
                target.write_bytes(content)

    def git(self, *args):
        git = shutil.which('git.exe')
        require(git is not None, 'Git for Windows is required to read the baseline')
        return self.run([git, '-C', SOURCE, *args], record=False)

    def extract_runtime(self):
        # The bytes hashed are the same archive handle used for extraction; don't execute an unverified tree.
        with self.args.archive.open('rb') as f:
            sha = hashlib.file_digest(f, 'sha256').hexdigest()
            require(sha == ZIP_SHA256, f'MariaDB ZIP digest mismatch: {sha}')
            self.text(f'MariaDB 11.8.10 Windows x64 archive sha256={sha}')
            f.seek(0)
            with zipfile.ZipFile(f) as z:
                for entry in z.infolist():
                    parts = PurePosixPath(entry.filename).parts
                    require(parts and parts[0] == 'mariadb-11.8.10-winx64' and '..' not in parts
                            and not any(':' in part or '\\' in part for part in parts)
                            and not stat.S_ISLNK(entry.external_attr >> 16), 'Unexpected archive path')
                z.extractall(self.runtime)
        self.runtime = self.runtime / 'mariadb-11.8.10-winx64'
        for name in ('mariadbd', 'mariadb', 'mariadb-dump', 'mariadb-install-db'):
            self.text(f'{name}.exe sha256={digest(self.exe(name))}')
        self.run([self.exe('mariadbd'), '--no-defaults', '--version'], show=True, contains='11.8.10-MariaDB')

    def exe(self, name):
        return self.runtime / 'bin' / f'{name}.exe'

    def name(self, scenario):
        require(scenario in SCENARIOS, 'Unexpected lab schema name')
        return f'bs_lab_{self.id}_{scenario}'

    def sockets(self):
        out = self.run([WINDOWS / 'netstat.exe', '-ano'], record=False, timeout=15)
        # Compare endpoints and the actual spawned PID; don't depend on localized state names.
        rows = [line.split() for line in out.decode('ascii', 'replace').splitlines()]
        return [(local, int(pid)) for protocol, local, remote, _, pid in (row for row in rows if len(row) == 5)
                if protocol == 'TCP' and remote in ('0.0.0.0:0', '[::]:0') and pid.isdecimal()]

    def assert_owner(self):
        require(self.server is not None and self.server.poll() is None and self.owner_verified,
                'No owned loopback MariaDB process')

    def start(self):
        self.run([self.exe('mariadb-install-db'), f'--datadir={self.data}', '--skip-networking', '--verbose-bootstrap'], show=True)
        # Install utility was offline. Provision only a disposable local admin before opening any listener.
        bootstrap = (
            "INSERT INTO mysql.global_priv (Host,User,Priv) VALUES ('127.0.0.1','lab_admin',"
            "JSON_OBJECT('access',18446744073709551615,'plugin','mysql_native_password',"
            f"'authentication_string',PASSWORD('{self.root_password}'),'password_last_changed',UNIX_TIMESTAMP()));\n"
            "DELETE FROM mysql.global_priv WHERE User IN ('root','');\n-- end"
        )
        self.run([self.exe('mariadbd'), '--no-defaults', '--bootstrap', '--skip-networking', '--console',
                  f'--basedir={self.runtime}', f'--datadir={self.data}', f'--tmpdir={self.root}'],
                 data=bootstrap.encode('ascii'))
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as reservation:
            reservation.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
            reservation.bind(('127.0.0.1', 0))
            self.port = reservation.getsockname()[1]
        self.root_options = self.root / 'admin.cnf'
        self.app_options = self.root / 'app.cnf'
        for file, user, password in ((self.root_options, 'lab_admin', self.root_password),
                                     (self.app_options, 'lab_app', self.db_password)):
            file.write_text(f'[client]\nprotocol=tcp\nhost=127.0.0.1\nport={self.port}\nuser={user}\npassword={password}\n', encoding='ascii')
        server_log = self.root / 'server.log'
        cmd = [self.exe('mariadbd'), '--no-defaults', '--console', f'--basedir={self.runtime}',
               f'--datadir={self.data}', f'--tmpdir={self.root}', '--bind-address=127.0.0.1', f'--port={self.port}',
               '--skip-named-pipe', '--skip-name-resolve', '--skip-log-bin',
               '--general-log=0', '--slow-query-log=0', '--innodb-buffer-pool-size=64M']
        self.text('+ ' + subprocess.list2cmdline([str(arg) for arg in cmd]))
        with server_log.open('wb') as f:
            self.server = subprocess.Popen(cmd, cwd=self.root, env=self.env, stdin=subprocess.DEVNULL,
                                           stdout=f, stderr=subprocess.STDOUT, creationflags=subprocess.CREATE_NO_WINDOW)
        deadline = time.monotonic() + 30
        endpoint = f'127.0.0.1:{self.port}'
        while time.monotonic() < deadline:
            if self.server.poll() is not None:
                self.text('server startup output (before client credentials were sent):\n'
                          + server_log.read_text(encoding='utf-8', errors='replace')[-3000:])
                raise LabError(f'Owned MariaDB exited at startup ({self.server.returncode})')
            owned = [local for local, pid in self.sockets() if pid == self.server.pid]
            if owned:
                require(owned == [endpoint], 'Owned MariaDB did not bind exactly the requested loopback endpoint')
                self.owner_verified = True
                break
            time.sleep(0.3)
        if not self.owner_verified:
            self.text('server startup output (before client credentials were sent):\n' + server_log.read_text(encoding='utf-8', errors='replace')[-2500:])
        self.assert_owner()
        self.text(f'exclusive LISTEN verified: {endpoint}, spawned pid={self.server.pid}')
        self.run([self.exe('mariadb'), f'--defaults-file={self.root_options}', '--batch', '--skip-column-names',
                  '--execute=SELECT VERSION(), @@port, @@bind_address'], show=True, contains=f'{self.port}\t127.0.0.1')
        sql = f"CREATE USER 'lab_app'@'127.0.0.1' IDENTIFIED BY '{self.db_password}';\n"
        for scenario in SCENARIOS:
            name = self.name(scenario)
            sql += f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n'
            sql += f"GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,DROP,INDEX,REFERENCES ON `{name}`.* TO 'lab_app'@'127.0.0.1';\n"
        self.run([self.exe('mariadb'), f'--defaults-file={self.root_options}', '--batch'], data=sql.encode('ascii'))

    def stop(self):
        if self.server is None:
            return
        started = self.owner_verified
        if self.server.poll() is None:
            try:
                if self.owner_verified and (f'127.0.0.1:{self.port}', self.server.pid) in self.sockets():
                    self.run([self.exe('mariadb'), f'--defaults-file={self.root_options}', '--batch', '--execute=SHUTDOWN'], timeout=15)
                    self.server.wait(timeout=15)
            finally:
                if self.server.poll() is None:
                    # A process handle created by this exact Popen, never a name/PID-wide kill.
                    self.server.terminate()
                    self.server.wait(timeout=15)
                self.text(f'owned mariadbd pid={self.server.pid} exit={self.server.returncode}')
        else:
            self.text(f'owned mariadbd pid={self.server.pid} exit={self.server.returncode}')
        if started:
            require(self.server.returncode == 0, 'MariaDB did not shut down cleanly')

    def make_identity(self, path):
        path.mkdir()
        key = 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode('ascii')
        self.redact.append(key)
        (path / 'app-key').write_text(key, encoding='ascii')
        openssl = self.args.openssl
        self.run([openssl, 'genrsa', '-out', 'oauth-private.key', '2048'], cwd=path)
        self.run([openssl, 'pkey', '-in', 'oauth-private.key', '-pubout', '-out', 'oauth-public.key'], cwd=path)

    def app_env(self, scenario, *, wrong_key=False):
        self.assert_owner()
        state = self.root / scenario
        for sub in ('app', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'textures', 'plugins'):
            (state / sub).mkdir(parents=True, exist_ok=True)
        identity = state / 'identity'
        if not identity.exists():
            self.make_identity(identity)
        app_key = (identity / 'app-key').read_text(encoding='ascii')
        if wrong_key:
            app_key = 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode('ascii')
            self.redact.append(app_key)
        return {
            **self.env, **{f'BS_DB_LAB_{k}': v for k, v in self.app_secrets.items()},
            'APP_ENV': 'production', 'APP_DEBUG': 'false', 'APP_URL': 'https://db-lab.example.test',
            'APP_KEY': app_key, 'APP_TRUSTED_PROXIES': '',
            'PASSPORT_PRIVATE_KEY': (identity / 'oauth-private.key').read_text(encoding='ascii'),
            'PASSPORT_PUBLIC_KEY': (identity / 'oauth-public.key').read_text(encoding='ascii'),
            'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1', 'DB_PORT': str(self.port),
            'DB_DATABASE': self.name(scenario), 'DB_USERNAME': 'lab_app', 'DB_PASSWORD': self.db_password,
            'DB_PREFIX': '' if scenario == 'fresh' else PREFIX, 'LARAVEL_STORAGE_PATH': str(state),
            'LOG_CHANNEL': 'null', 'CACHE_DRIVER': 'file', 'SESSION_DRIVER': 'file', 'MAIL_MAILER': 'array',
            'QUEUE_CONNECTION': 'database', 'FILESYSTEM_DRIVER': 'local', 'FS_DRIVER': 'local',
            'TEXTURES_DIR': str(state / 'textures'), 'PLUGINS_DIR': str(state / 'plugins'),
            'PWD_METHOD': 'SHA256', 'SALT': '',
        }

    def php(self, scenario, *args, wrong_key=False, **kwargs):
        self.text(f'schema={self.name(scenario)}; prefix={"(none)" if scenario == "fresh" else PREFIX}')
        self.run([self.args.php, '-c', self.args.php.with_name('php.ini'), '-d', f'extension_dir={self.args.php.parent / "ext"}',
                  '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'zend.exception_ignore_args=1', *args],
                 cwd=self.stage, env=self.app_env(scenario, wrong_key=wrong_key), show=True, **kwargs)

    def probe(self, scenario, mode, **kwargs):
        self.php(scenario, 'tests/DatabaseLab/Probe.php', mode, contains='assertions=OK', **kwargs)

    def install(self, scenario, duplicate=False):
        # Never pass a password as a shell argument or log stdin.
        self.php(scenario, 'artisan', 'bs:install', 'fresh-admin@example.test', 'Fresh Admin', '--password-stdin',
                 '--no-interaction', '--no-ansi', data=(self.app_secrets['FRESH'] + '\n').encode('ascii'),
                 expected=1 if duplicate else 0, contains='Database is already installed' if duplicate else 'Installation completed.')

    def upgrade(self, scenario, mode='adopt'):
        expected = {'adopt': (0, 'Upgrade completed.'), 'reject': (1, 'requires an intact Blessing Skin 6.0.2'),
                    'noop': (0, 'Already at the current release.'),
                    'wrong-key': (1, 'Installation identity or recorded version does not match')}
        code, text = expected[mode]
        opts = ['--adopt-legacy'] if mode == 'adopt' else []
        self.php(scenario, 'artisan', 'bs:upgrade', *opts, '--no-interaction', '--no-ansi',
                 wrong_key=mode == 'wrong-key', expected=code, contains=text)

    def dump(self, scenario, target):
        self.assert_owner()
        self.run([self.exe('mariadb-dump'), f'--defaults-file={self.app_options}', '--single-transaction', '--quick',
                  '--skip-lock-tables', '--skip-add-locks', '--no-tablespaces', '--skip-comments', '--skip-dump-date',
                   '--order-by-primary',
                  f'--result-file={target}', self.name(scenario)])
        sha = digest(target)
        self.text(f'{target.name} sha256={sha}; bytes={target.stat().st_size}')
        return sha

    def backup(self, scenario, label):
        # All app probes exit before backup; no HTTP listener or other lab writers exist.
        path = self.root / label
        path.mkdir()
        sha = self.dump(scenario, path / 'database.sql')
        for name in ('textures', 'identity'):
            copy_tree(self.root / scenario / name, path / name)
        assets = file_manifest(path / 'textures')
        self.text(f'{label} texture manifest sha256={hashlib.sha256(assets.encode()).hexdigest()}; files={assets.count(chr(10))}')
        return path, sha

    def restore(self, scenario, path, sha):
        self.assert_owner()
        require(digest(path / 'database.sql') == sha, 'SQL backup integrity')
        self.run([self.exe('mariadb'), f'--defaults-file={self.app_options}', '--batch', self.name(scenario)],
                 input_file=path / 'database.sql')
        target = self.root / scenario
        for name in ('identity', 'textures'):
            copy_tree(path / name, target / name)
            require(file_manifest(path / name) == file_manifest(target / name), f'{name} copy integrity')
        require(self.dump(scenario, self.root / f'{scenario}.sql') == sha, 'restored SQL matches original SQL digest')
        self.text(f'{scenario}: SQL, texture files and private identity restored into fresh schema/root')

    def exercise(self):
        for scenario in ('fresh', 'freshpref'):
            self.probe(scenario, 'empty')
            self.install(scenario)
            self.probe(scenario, 'fresh')
            self.install(scenario, duplicate=True)
            self.upgrade(scenario, 'noop')
            self.probe(scenario, 'fresh')
        self.probe('legacy', 'seed-old')
        self.probe('legacy', 'old')
        pre, pre_sha = self.backup('legacy', 'pre-upgrade')
        self.upgrade('legacy', 'reject')
        require(self.dump('legacy', self.root / 'legacy-rejected.sql') == pre_sha, 'rejected adoption did not change the database')
        self.probe('legacy', 'old')
        self.upgrade('legacy')
        self.probe('legacy', 'upgraded')
        self.probe('legacy', 'queue')
        self.probe('legacy', 'seed-modern')
        self.probe('legacy', 'modern')
        post, post_sha = self.backup('legacy', 'post-upgrade')
        self.upgrade('legacy', 'noop')
        require(self.dump('legacy', self.root / 'legacy-noop.sql') == post_sha, 'no-op upgrade did not change the database')
        self.restore('rollback', pre, pre_sha)
        self.probe('rollback', 'old')
        self.upgrade('rollback')
        self.probe('rollback', 'upgraded')
        self.restore('restore', post, post_sha)
        self.probe('restore', 'wrong-key', wrong_key=True)
        self.upgrade('restore', 'wrong-key')
        require(self.dump('restore', self.root / 'restore-rejected.sql') == post_sha, 'wrong key rejection did not change the database')
        self.probe('restore', 'modern')
        self.upgrade('restore', 'noop')


def outside(path):
    return not path.is_relative_to(SOURCE) and path != SOURCE


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True, type=Path, help='PHP 8.6.0 x64 executable alongside its php.ini')
    parser.add_argument('--openssl', required=True, type=Path, help='trusted OpenSSL executable')
    parser.add_argument('--archive', required=True, type=Path, help='official MariaDB 11.8.10 winx64 ZIP (hash pinned)')
    parser.add_argument('--evidence', required=True, type=Path, help='new text log outside repository')
    parser.add_argument('--legacy-fixture', type=Path, help='verified schema-only export for a source ZIP without Git history')
    args = parser.parse_args()
    require(os.name == 'nt' and sys.version_info >= (3, 12), 'Requires Windows and Python 3.12+')
    if args.legacy_fixture is not None:
        args.legacy_fixture = args.legacy_fixture.resolve(strict=True)
        require(args.legacy_fixture.is_file(), '--legacy-fixture must be a regular file')
        baseline_fixture_files(args.legacy_fixture)
    temp = Path(tempfile.gettempdir()).resolve(strict=True)
    for field in ('php', 'openssl', 'archive', 'evidence'):
        file = getattr(args, field).resolve(strict=field != 'evidence')
        require(outside(file), f'--{field} must be outside the repository')
        setattr(args, field, file)
    require(outside(temp) and str(temp).isascii(), 'Choose an ASCII local TEMP outside the repository')
    args.evidence.parent.mkdir(parents=True, exist_ok=True)
    with args.evidence.open('x', encoding='utf-8', newline='\n') as log:
        lab = None
        try:
            with tempfile.TemporaryDirectory(prefix='blessing-db-', dir=temp) as scratch:
                lab = Lab(args, Path(scratch).resolve(strict=True), log)
                try:
                    lab.text('run: ' + subprocess.list2cmdline([sys.executable, *sys.argv]))
                    lab.restrict()
                    lab.text(f'php.exe sha256={digest(args.php)}')
                    lab.text(f'php.ini sha256={digest(args.php.with_name("php.ini"))}')
                    lab.text(f'openssl.exe sha256={digest(args.openssl)}')
                    lab.run([args.openssl, 'version'], show=True)
                    lab.extract_runtime()
                    lab.stage_source()
                    lab.start()
                    lab.exercise()
                finally:
                    lab.stop()
        except (LabError, OSError, zipfile.BadZipFile) as error:
            if lab is not None:
                lab.text(f'RESULT=FAIL: {error}')
            return 1
        lab.text('cleanup: private temporary source, data, dumps, texture and identity roots removed')
        lab.text('RESULT=PASS')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (LabError, OSError, zipfile.BadZipFile) as error:
        # Paths/codes from OS failures are diagnostic; exceptions never include subprocess inputs.
        print(f'RESULT=FAIL: {error}', file=sys.stderr)
        sys.exit(1)
