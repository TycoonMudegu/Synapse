#!/usr/bin/env python3
"""Stage an isolated FTP package or drive its authenticated HTTPS endpoint."""

import hashlib
import hmac
import base64
import json
import os
from pathlib import Path
import re
import shutil
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
PACKAGE = ROOT / '.migration-upload'
USER_AGENT = 'Synapse-Deploy/1.0'
PRODUCTION_FTP_ROOT = '/domains/jobflow.mystuffs.co.ke/public_html/'
RULES = '''Options -Indexes
Require all denied
<Files "endpoint.php">
    Require all granted
</Files>
'''


def release_id():
    release = os.environ.get('DB_DEPLOY_RELEASE', '')
    if not re.fullmatch(r'[a-f0-9]{40}', release):
        raise ValueError('DB_DEPLOY_RELEASE must be the 40-character Git commit ID.')
    return release


def build():
    release = release_id()
    inputs = [(ROOT / 'database/deploy.php', 'endpoint.php'),
              (ROOT / 'database/runtime_autoload.php', 'vendor/autoload.php'),
              (ROOT / 'App/Core/Env.php', 'App/Core/Env.php')]
    inputs += [(path, str(path.relative_to(ROOT)))
               for path in (ROOT / 'App/Database').glob('*.php')]
    inputs += [(path, str(path.relative_to(ROOT)))
               for path in (ROOT / 'database/migrations').glob('*.sql')]
    inputs += [(ROOT / 'database' / name, 'database/' + name)
               for name in ('schema.php', 'connection.php')]
    for source, relative in inputs:
        if not source.is_file() or any(path.is_symlink() for path in [source, *source.parents] if path != ROOT.parent):
            raise ValueError('Migration inputs must be regular files without symlinks: ' + relative)
    if PACKAGE.exists():
        shutil.rmtree(PACKAGE)
    package = PACKAGE / '_db-deploy' / 'releases' / release
    package.mkdir(parents=True)
    (PACKAGE / '_db-deploy' / '.htaccess').write_text(RULES)
    (package / '.htaccess').write_text(RULES)
    for source, relative in inputs:
        target = package / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, target)
    files = {str(path.relative_to(package)): hashlib.sha256(path.read_bytes()).hexdigest()
             for path in sorted(package.rglob('*')) if path.is_file()}
    (package / 'manifest.json').write_text(json.dumps({'release': release, 'files': files,
        'access_rules': hashlib.sha256(RULES.encode()).hexdigest()}, sort_keys=True))
    print(f'Prepared standalone Synapse migration package for {release} ({len(files)} files).')


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # Never forward signed requests to redirects or another host.


def reject_hosting_challenge(code, headers, context):
    captcha = headers.get('sg-captcha', '').strip().lower() == 'challenge'
    if code == 202 or captcha:
        evidence = ('SiteGround returned sg-captcha: challenge' if captcha
                    else 'HTTP 202 may be a hosting anti-bot/CAPTCHA response; it is not an expected application response')
        raise RuntimeError(f'{context} returned HTTP {code}. {evidence}. '
                           'Ask SiteGround support to allow authenticated GitHub Actions HTTPS requests to '
                           '/_db-deploy/releases/*/endpoint.php and the read-only HEAD /.env check. '
                           'Keep .env denied and migration authentication enabled. '
                           'Changing database secrets or .htaccess denial rules will not resolve a hosting CAPTCHA.')


def deployment_details(result):
    # Accept only schema identifiers and fixed log locations, never raw SQL/error text.
    identifier = r'[A-Za-z_][A-Za-z0-9_]{0,63}'
    schema_item = rf'(?:table {identifier}|{identifier}\.{identifier}|unique index {identifier}\({identifier}(?:, {identifier})*\))'
    details = []
    missing = result.get('schema_missing', [])
    if isinstance(missing, list):
        missing = [item for item in missing if isinstance(item, str) and re.fullmatch(schema_item, item)]
        if missing:
            details.append('Missing required schema:\n - ' + '\n - '.join(missing))
    state = result.get('sqlstate')
    if isinstance(state, str) and re.fullmatch(r'[A-Z0-9]{5}', state):
        detail = 'Database SQLSTATE: ' + state
        code = result.get('database_error_code')
        if type(code) is int and 0 < code < 100000:
            detail += f'; database error code: {code}'
        details.append(detail)
    if result.get('error_log') == '_db-deploy/deployment-error.log':
        details.append('Open _db-deploy/deployment-error.log through the hosting file manager for the full error. '
                       'This file is protected from public web access.')
    return ('\n' + '\n'.join(details)) if details else ''


def call_endpoint(url, secret, payload):
    body = json.dumps(payload, separators=(',', ':')).encode()
    timestamp = str(int(time.time()))
    signature = hmac.new(secret.encode(), timestamp.encode() + b'\n' + body, hashlib.sha256).hexdigest()
    request = urllib.request.Request(url, data=body, method='POST', headers={
        'Content-Type': 'application/json', 'X-Deploy-Timestamp': timestamp, 'X-Deploy-Signature': signature,
        'User-Agent': USER_AGENT,
    })
    try:
        with urllib.request.build_opener(NoRedirects).open(request, timeout=90) as response:
            code = response.status
            reject_hosting_challenge(code, response.headers, 'Migration endpoint')
            raw = response.read(65536)
    except urllib.error.HTTPError as error:
        with error:
            code = error.code
            reject_hosting_challenge(code, error.headers, 'Migration endpoint')
            raw = error.read(65536)
    if code == 404:
        release = payload.get('release', '')
        commit = release if isinstance(release, str) and re.fullmatch(r'[a-f0-9]{40}', release) else '<commit>'
        endpoint = '/_db-deploy/releases/' + commit + '/endpoint.php'
        raise RuntimeError('Migration endpoint returned HTTP 404: ' + endpoint + '. '
                           'The uploaded endpoint is not reachable at DB_DEPLOY_BASE_URL. '
                           'Check that this file exists inside the domain\'s document root, '
                           'then confirm FTP_SERVER_DIR maps to that same web root. '
                           'If the file exists there, check the hosting rewrite and access rules. '
                           'HEAD /.env returning 403 or 404 does not prove the FTP destination is correct. '
                           'Migration progress could not be verified; application upload stopped.')
    try:
        result = json.loads(raw)
    except (ValueError, UnicodeError):
        raise RuntimeError(f'Endpoint returned HTTP {code} without valid JSON; inspect hosting logs.') from None
    if not isinstance(result, dict):
        raise RuntimeError('Endpoint returned an invalid response.')
    if code == 409 and result.get('error') == 'Another migration process is running.':
        return {'status': 'busy'}
    if code != 200:
        # Do not print an arbitrary HTML response or server credentials.
        raise RuntimeError(f'Endpoint rejected deployment (HTTP {code}): {result.get("error", "unknown failure")}'
                           + deployment_details(result))
    if result.get('release') != payload['release']:
        raise RuntimeError('Endpoint returned the wrong release identifier.')
    return result


def verify_env_protection(base):
    target = base + '/.env'
    request = urllib.request.Request(target, method='HEAD', headers={
        'User-Agent': USER_AGENT, 'Cache-Control': 'no-cache',
    })
    try:
        with urllib.request.build_opener(NoRedirects).open(request, timeout=15) as response:
            code = response.status
            reject_hosting_challenge(code, response.headers, 'Environment access check')
    except urllib.error.HTTPError as error:
        with error:
            code = error.code
            reject_hosting_challenge(code, error.headers, 'Environment access check')
    if code not in [403, 404]:
        if 300 <= code < 400:
            detail = 'The access check does not follow redirects. Set DB_DEPLOY_BASE_URL to the final HTTPS application root.'
        elif code >= 500:
            detail = 'Hosting returned a server error. Check the hosting error log and .htaccess syntax, then retry.'
        else:
            detail = ('Check DB_DEPLOY_BASE_URL points to the application root. Add the .env denial rule to '
                      'the existing hosting .htaccess beside .env before the first deployment; '
                      'the application upload has not reached that file yet.')
        raise RuntimeError(f'Environment access check failed: HEAD {target} returned HTTP {code}; '
                           f'expected 403 or 404. {detail} See database/README.md.')
    print(f'Environment access check passed: HEAD {target} returned HTTP {code}.')
    return code


def access_receipt(release, base, secret, status):
    data = {'release': release, 'base_hash': hashlib.sha256(base.encode()).hexdigest(),
            'verified_at': int(time.time()), 'status': status}
    encoded = base64.urlsafe_b64encode(json.dumps(data, sort_keys=True, separators=(',', ':')).encode()).decode()
    signature = hmac.new(secret.encode(), ('env-access-v1\n' + encoded).encode(), hashlib.sha256).hexdigest()
    return encoded + '.' + signature


def reuse_access_receipt(token, release, base, secret):
    # Reuse only this release's verified protection check, for at most one hour.
    # A changed URL/key/release, expired receipt or malformed token forces a new check.
    try:
        if not token or len(token) > 1024:
            return False
        encoded, signature = token.split('.')
        expected = hmac.new(secret.encode(), ('env-access-v1\n' + encoded).encode(), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(signature, expected):
            return False
        data = json.loads(base64.b64decode(encoded, altchars=b'-_', validate=True))
        return (data['release'] == release and data['base_hash'] == hashlib.sha256(base.encode()).hexdigest()
                and data['status'] in [403, 404] and type(data['verified_at']) is int
                and 0 <= time.time() - data['verified_at'] <= 3600)
    except (ValueError, KeyError, TypeError):
        return False


def settings():
    release = release_id()
    base = os.environ.get('DB_DEPLOY_BASE_URL', '').strip().rstrip('/')
    if not base:
        raise ValueError('DB_DEPLOY_BASE_URL is empty. Set the GitHub Actions variable (or secret) '
                         'to the HTTPS application root, for example https://synapse.example.com.')
    try:
        parsed = urllib.parse.urlsplit(base)
        valid = (parsed.scheme == 'https' and parsed.hostname and not parsed.username
                 and not parsed.password and not parsed.query and not parsed.fragment
                 and not any(character.isspace() for character in base)
                 and (parsed.port is None or 1 <= parsed.port <= 65535))
    except ValueError:
        valid = False
    if not valid:
        raise ValueError('Invalid DB_DEPLOY_BASE_URL. Use the HTTPS application root, for example '
                         'https://synapse.example.com, without credentials, query or fragment.')
    secret = os.environ.get('DB_DEPLOY_SECRET', '').strip()
    if not secret:
        raise ValueError('DB_DEPLOY_SECRET is empty. Add it as a GitHub Actions secret, '
                         'with the same value as DB_DEPLOY_SECRET in hosting .env.')
    if not re.fullmatch(r'[a-f0-9]{64}', secret):
        raise ValueError('DB_DEPLOY_SECRET must be a 64-character lowercase hex secret, matching hosting .env.')
    return release, base, secret


def configuration():
    settings()
    folder = os.environ.get('FTP_SERVER_DIR', '')
    if folder != PRODUCTION_FTP_ROOT:
        raise ValueError('FTP_SERVER_DIR must be ' + PRODUCTION_FTP_ROOT + '; '
                         'refusing uploads to the hosting root or another directory.')
    if os.environ.get('FTP_PROTOCOL', 'ftps') not in ('ftps', 'ftps-legacy', 'ftp'):
        raise ValueError('FTP_PROTOCOL must be ftps, ftps-legacy or ftp.')
    for name in ('FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD'):
        if not os.environ.get(name):
            raise ValueError('Missing GitHub Actions secret: ' + name)
    print('Production FTP web root: ' + folder)
    print('Migration FTP destination: ' + folder + '_db-deploy/')
    print('Production destination, deployment authentication and FTP settings are configured.')


def check():
    release, base, secret = settings()
    status = verify_env_protection(base)
    output = os.environ.get('GITHUB_OUTPUT')
    if output:
        with open(output, 'a') as file:
            file.write('access_receipt=' + access_receipt(release, base, secret, status) + '\n')
    print('Deployment settings and hosting environment-file protection verified; no database changes made.')


def verify_complete_history(result, expected):
    applied = result.get('applied')
    if (result.get('pending') != [] or not isinstance(applied, list)
            or not all(isinstance(version, str) for version in applied)
            or len(applied) != len(expected) or set(applied) != set(expected)):
        raise RuntimeError('Endpoint reported completion without matching this release\'s migration history; application upload stopped.')


def write_migration_summary(release, expected, result, complete):
    summary = os.environ.get('GITHUB_STEP_SUMMARY')
    if not summary:
        return
    # Use only validated identifiers, packaged filenames and fixed labels here.
    # Never put hosting errors, secrets, database credentials or business data in the report.
    applied = result.get('applied', []) if result else []
    pending = result.get('pending', []) if result else []
    applied = applied if isinstance(applied, list) else []
    pending = pending if isinstance(pending, list) else []
    recorded = sum(version in applied for version in expected) if result and isinstance(result.get('applied'), list) else 'Unknown'
    lines = ['## Production database migrations', '',
             '**VERIFIED — ready for application upload**' if complete else '**NOT VERIFIED — application upload blocked**', '',
             f'Commit: `{release}`' if re.fullmatch(r'[a-f0-9]{40}', release) else 'Commit: unavailable',
             'Checked at: ' + time.strftime('%Y-%m-%d %H:%M:%S UTC', time.gmtime()), '',
             '| Check | Result |', '| --- | --- |',
             f'| Recorded release migrations | {recorded} / {len(expected) if expected else "Unknown"} |',
             f'| Pending migrations | {len(pending) if result and isinstance(result.get("pending"), list) else "Unknown"} |',
             '| Required tables, columns and unique indexes | ' + ('PASS' if complete else 'Not verified') + ' |', '',
             '| Migration in this commit | Last observed production status |', '| --- | --- |']
    for version, name in sorted(expected.items()):
        state = 'APPLIED' if version in applied else 'PENDING' if version in pending else 'Unknown'
        lines.append(f'| `{name}` | {state} |')
    lines += ['', 'Source: production `migrations`, compared with this commit\'s migration package.',
              'This is a check at the time shown. Application FTP upload has its own subsequent step.',
              'Your local database is checked separately with `php scripts/db.php status` and `php scripts/db.php check`.',
              'This checks migration history and required schema; it does not compare customer data or prove every application flow works.', '']
    try:
        with open(summary, 'a') as file:
            file.write('\n'.join(lines))
    except OSError:
        # An unavailable presentation file must not change the migration outcome.
        print('Could not write the GitHub database summary; use the migration step log.', file=sys.stderr)


def run():
    report = {'expected': {}, 'result': None}
    complete = False
    try:
        run_migrations(report)
        complete = True
    finally:
        write_migration_summary(os.environ.get('DB_DEPLOY_RELEASE', ''), report['expected'], report['result'], complete)


def run_migrations(report):
    expected = report['expected']
    release, base, secret = settings()
    if reuse_access_receipt(os.environ.get('DB_DEPLOY_ACCESS_RECEIPT', ''), release, base, secret):
        print('Using the environment access check verified earlier in this deployment.')
    else:
        verify_env_protection(base)
    manifest = PACKAGE / '_db-deploy' / 'releases' / release / 'manifest.json'
    raw = manifest.read_bytes()
    for path in json.loads(raw).get('files', {}):
        match = re.fullmatch(r'database/migrations/(\d{3,14}_[a-z0-9_]+\.sql)', path)
        if match:
            expected[match[1]] = path.rsplit('/', 1)[1]
    if not expected:
        raise RuntimeError('Migration package contains no versioned migrations.')
    payload = {'release': release, 'package': hashlib.sha256(raw).hexdigest(),
               'retry_failed': os.environ.get('DB_DEPLOY_RETRY_FAILED', 'false') == 'true'}
    override = os.environ.get('DB_DEPLOY_BACKUP_WINDOW_OVERRIDE', 'false')
    if override not in ('true', 'false'):
        raise ValueError('DB_DEPLOY_BACKUP_WINDOW_OVERRIDE must be true or false.')
    if override == 'true':
        reason = os.environ.get('DB_DEPLOY_BACKUP_WINDOW_OVERRIDE_REASON', '').strip()
        if not 10 <= len(reason.encode()) <= 500 or any(ord(char) < 32 or ord(char) == 127 for char in reason):
            raise ValueError('An emergency backup-window override requires a reason of 10 to 500 characters on one line.')
        payload.update(backup_window_override=True, backup_window_override_reason=reason)
    url = base + '/_db-deploy/releases/' + release + '/endpoint.php'
    deadline = time.monotonic() + 1200
    waiting = 0
    while time.monotonic() < deadline:
        try:
            result = call_endpoint(url, secret, dict(payload, action='status'))
        except (urllib.error.URLError, TimeoutError):
            waiting += 1
            if waiting > 12:
                raise RuntimeError('Cannot read deployment status; no application files will be uploaded.') from None
            time.sleep(5)
            continue
        if result['status'] != 'busy':
            report['result'] = result
        if result['status'] == 'complete':
            verify_complete_history(result, expected)
            print(f'Production migrations complete; {len(expected)}/{len(expected)} recorded, 0 pending; release schema verified.')
            return
        if result['status'] == 'busy':
            time.sleep(5)
            continue
        if result['status'] == 'needs_review' and not payload['retry_failed']:
            raise RuntimeError('An interrupted migration requires review before using retry_failed.' + deployment_details(result))
        override_active = payload.get('backup_window_override') is True and result.get('backup_window_override_active') is True
        if not result.get('backup_window_open') and not override_active:
            detail = result.get('backup_window', {}).get('message')
            raise RuntimeError(detail or 'Pending migrations require the configured hosting backup window. '
                               'Check DB_DEPLOY_BACKUP_POLICY and DB_DEPLOY_BACKUP_UTC in hosting .env, using UTC.')
        if result['status'] not in ['pending', 'needs_review']:
            raise RuntimeError('Unknown migration status.')
        waiting = 0
        if override_active:
            print('Emergency backup-window override for this release: ' + payload['backup_window_override_reason'])
        print(f'Applying migration {result["pending"][0]}...')
        try:
            call_endpoint(url, secret, dict(payload, action='step'))
        except (urllib.error.URLError, TimeoutError):
            # A lost response is not a rollback. Read durable status before deciding.
            print('Migration response interrupted; checking recorded progress.')
        # Status decides whether it completed, is still locked, or requires review.
    raise RuntimeError('Migration stage exceeded its deadline; application upload stopped.')


if __name__ == '__main__':
    try:
        if sys.argv[1:] == ['config']:
            configuration()
        elif sys.argv[1:] in (['stage'], ['build']):
            build()
        elif sys.argv[1:] == ['check']:
            check()
        elif sys.argv[1:] == ['run']:
            run()
        else:
            raise ValueError('Usage: python3 scripts/db_deploy.py config|check|stage|build|run')
    except Exception as error:
        print(f'Database deployment failed: {error}', file=sys.stderr)
        sys.exit(1)
