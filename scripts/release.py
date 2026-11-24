#!/usr/bin/env python3
"""Prepare the PHP release from explicit runtime paths, never a whole checkout."""

import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import sys
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / '.release'
DIRECTORIES = ('App', 'Bootstrap', 'Public', 'Resources/Views', 'Routes', 'database', 'vendor')
FILES = ('.htaccess', 'index.php', 'composer.json', 'composer.lock')
SCRIPTS = ('cron.php', 'schedule.php', 'worker.php', 'migrate.php', 'db.php',
           'configure-cloud-storage.php', 'configure-site.php', 'fuzu-browser.mjs', 'collect-fuzu.php')
REQUIRED = ('vendor/autoload.php', 'Public/Assets/app.css', 'Public/Assets/alpine.js',
            'Public/Assets/lucide.js', 'Public/Assets/pdfjs/6.5.438/pdf.min.mjs',
            'Public/Assets/pdfjs/6.5.438/pdf.worker.min.mjs', 'Public/Assets/licenses/pdfjs.txt')


def validate_file(path):
    if path.is_symlink():
        raise ValueError('Release inputs cannot contain symlinks: ' + str(path.relative_to(ROOT)))
    if path.name.startswith('.env') or path.suffix in ('.log', '.key') or path.name.endswith('.sql.gz'):
        raise ValueError('Private runtime file found in release input.')
    if path.suffix == '.json':
        try:
            data = json.loads(path.read_text())
        except (ValueError, UnicodeError):
            data = None
        if isinstance(data, dict) and (data.get('type') == 'service_account' or data.get('private_key')):
            raise ValueError('A cloud credential was found in release input.')
    if path.suffix in ('.php', '.json', '.txt', '.pem'):
        if re.search(rb'-----BEGIN(?: RSA| EC| OPENSSH| ENCRYPTED)? PRIVATE KEY-----\s*\r?\n[A-Za-z0-9+/=]{32,}', path.read_bytes()):
            raise ValueError('A private key was found in release input.')


def copy_directory(relative, destination):
    source = ROOT / relative
    if source.is_symlink() or not source.is_dir():
        raise ValueError('Missing runtime directory: ' + relative)
    for path in source.rglob('*'):
        if path.is_symlink():
            raise ValueError('Release inputs cannot contain symlinks.')
        if not path.is_file() or any(part.startswith('.git') or part == '__pycache__' for part in path.relative_to(source).parts):
            continue
        if relative == 'vendor' and any(part.lower() in ('test', 'tests', 'testing', 'examples', 'docs')
                                        for part in path.relative_to(source).parts[:-1]):
            continue
        validate_file(path)
        target = destination / relative / path.relative_to(source)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(path, target)


def build():
    for name in REQUIRED:
        if not (ROOT / name).is_file():
            raise ValueError('Build dependencies/assets before packaging; missing ' + name)
    if OUTPUT.exists():
        shutil.rmtree(OUTPUT)
    application = OUTPUT / 'application'
    application.mkdir(parents=True)
    for relative in DIRECTORIES:
        copy_directory(relative, application)
    for relative in FILES:
        validate_file(ROOT / relative)
        shutil.copyfile(ROOT / relative, application / relative)
    (application / 'scripts').mkdir()
    for name in SCRIPTS:
        validate_file(ROOT / 'scripts' / name)
        shutil.copyfile(ROOT / 'scripts' / name, application / 'scripts' / name)
    bootstrap = OUTPUT / 'bootstrap'
    bootstrap.mkdir()
    shutil.copyfile(ROOT / '.htaccess', bootstrap / '.htaccess')
    for directory in ('private', 'logs', 'sessions', 'run'):
        target = bootstrap / 'storage' / directory
        target.mkdir(parents=True)
        (target / '.htaccess').write_text('Require all denied\n')
    manifest = {str(path.relative_to(application)): hashlib.sha256(path.read_bytes()).hexdigest()
                for path in sorted(application.rglob('*')) if path.is_file()}
    (OUTPUT / 'manifest.json').write_text(json.dumps({'files': manifest}, sort_keys=True))
    print(f'Release prepared: {len(manifest)} runtime files. Environment, credentials, local storage and tests excluded.')


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def smoke():
    base = os.environ.get('DB_DEPLOY_BASE_URL', '').rstrip('/')
    from db_deploy import settings, verify_env_protection
    settings()
    verify_env_protection(base)
    opener = urllib.request.build_opener(NoRedirects)
    checks = [('/login', b'Synapse'), ('/Assets/app.css', None),
              ('/Assets/pdfjs/6.5.438/pdf.worker.min.mjs', None)]
    for path, marker in checks:
        request = urllib.request.Request(base + path, headers={'User-Agent': 'Synapse-Deploy/1.0'})
        with opener.open(request, timeout=30) as response:
            body = response.read(2 * 1024 * 1024) if marker else b''
            if response.status != 200 or (marker and marker not in body):
                raise RuntimeError('Production smoke check failed: ' + path)
            if path.endswith('.mjs') and response.headers.get_content_type() not in ('text/javascript', 'application/javascript'):
                raise RuntimeError('PDF worker must be served as JavaScript.')
        print('PASS production ' + path)


if __name__ == '__main__':
    try:
        if sys.argv[1:] == ['build']:
            build()
        elif sys.argv[1:] == ['smoke']:
            smoke()
        else:
            raise ValueError('Usage: python3 scripts/release.py build|smoke')
    except Exception as error:
        print('Release failed: ' + str(error), file=sys.stderr)
        sys.exit(1)
