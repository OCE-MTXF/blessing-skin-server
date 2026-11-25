#!/usr/bin/env python3
"""Fetch the pinned lab ZIP in bounded ranges when a single archive transfer times out."""

import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import hashlib
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

SIZE = 99_027_722
SHA256 = '05d04b46763ea3dc9111bfc82dc061e0a158c682a73d31ba34b13f2653c88d17'
PART = '/mariadb-11.8.10/winx64-packages/mariadb-11.8.10-winx64.zip'
MIRRORS = (
    'https://mirrors.aliyun.com/mariadb',
    'https://mirrors.ustc.edu.cn/mariadb',
    'https://archive.mariadb.org',
)
CHUNK_SIZE = 4 * 1024**2


def fetch(start, end, root):
    path = root / f'{start:09d}.part'
    expected = f'content-range: bytes {start}-{end}/{SIZE}'.encode('ascii')
    for mirror in MIRRORS * 2:
        url = mirror + PART
        args = ['curl.exe', '--disable', '--fail', '--silent', '--show-error', '--location',
                '--proto', '=https', '--proto-redir', '=https', '--connect-timeout', '8', '--max-time', '90',
                '--range', f'{start}-{end}', '--output', str(path), '--dump-header', '-', '--', url]
        p = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        matched = any(re.fullmatch(rb'HTTP/\S+ 206(?: .*|)', line) for line in p.stdout.splitlines())
        if p.returncode == 0 and matched and expected in p.stdout.lower().splitlines() and path.stat().st_size == end - start + 1:
            print(f'curl exit=0 HTTP 206 bytes={start}-{end}/{SIZE} {url}', flush=True)
            return path
        print(f'retry curl exit={p.returncode} bytes={start}-{end}/{SIZE} {url}', flush=True)
    raise RuntimeError(f'Could not obtain exact byte range {start}-{end}')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('destination', type=Path, help='new ZIP file outside the repository')
    args = parser.parse_args()
    destination = args.destination.resolve()
    source = Path(__file__).resolve().parents[2]
    if destination.exists() or destination.is_relative_to(source):
        raise RuntimeError('Only a new ZIP outside the checked-out source may be created')
    print('metadata=https://downloads.mariadb.org/rest-api/mariadb/11.8/', flush=True)
    with tempfile.TemporaryDirectory(prefix='mariadb-ranges-', dir=destination.parent) as folder:
        root = Path(folder)
        with ThreadPoolExecutor(max_workers=8) as pool:
            futures = [pool.submit(fetch, i, min(i + CHUNK_SIZE, SIZE) - 1, root) for i in range(0, SIZE, CHUNK_SIZE)]
            chunks = sorted(future.result() for future in as_completed(futures))
        assembled = root / 'assembled.zip'
        with assembled.open('xb') as out:
            for chunk in chunks:
                with chunk.open('rb') as part:
                    shutil.copyfileobj(part, out)
        with assembled.open('rb') as archive:
            computed = hashlib.file_digest(archive, 'sha256').hexdigest()
        if computed != SHA256 or assembled.stat().st_size != SIZE:
            raise RuntimeError(f'ZIP integrity check failed: sha256={computed}')
        os.rename(assembled, destination)  # Windows refuses to overwrite an existing destination.
        print(f'archive={destination} bytes={SIZE} sha256={computed}', flush=True)


if __name__ == '__main__':
    main()
