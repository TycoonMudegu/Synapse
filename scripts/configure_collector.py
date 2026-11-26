"""Configure only the collector's hosting environment fields through verified FTPS."""

import ftplib
import io
import json
import os
import re
import ssl
import sys
import urllib.request

from db_deploy import NoRedirects, configuration, settings, verify_env_protection


def collector_values():
    values = {'COLLECTOR_ENABLED': 'true'}
    for key in ('COLLECTOR_PROJECT', 'COLLECTOR_REGION', 'COLLECTOR_JOB'):
        value = os.environ.get(key, '')
        if not re.fullmatch(r'[a-z][a-z0-9-]{0,62}', value):
            raise ValueError('Set a valid ' + key + '.')
        values[key] = value
    token = os.environ.get('COLLECTOR_TOKEN', '')
    if not re.fullmatch(r'[A-Za-z0-9_-]{32,256}', token):
        raise ValueError('Set a collector token with 32–256 letters, digits, underscores or dashes.')
    values['COLLECTOR_TOKEN'] = token
    return values


def patch_environment(contents, values):
    if not contents or len(contents) > 1048576:
        raise ValueError('The existing hosting environment must be present and bounded.')
    lines = contents.decode('utf-8').splitlines()
    kept = []
    for line in lines:
        key = line.split('=', 1)[0].strip() if '=' in line and not line.lstrip().startswith('#') else ''
        if key not in values:
            kept.append(line)
    return ('\n'.join(kept) + '\n' + '\n'.join(key + '=' + value for key, value in values.items()) + '\n').encode()


def configure_host(values):
    if os.environ.get('FTP_PROTOCOL', 'ftps') != 'ftps':
        raise ValueError('Collector credentials require verified FTPS.')
    staged = '.env.collector'
    with ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=30) as client:
        client.connect(os.environ['FTP_SERVER'], int(os.environ.get('FTP_PORT', '21')))
        client.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
        client.prot_p()
        client.cwd(os.environ['FTP_SERVER_DIR'])
        contents = bytearray()

        def receive(block):
            if len(contents) + len(block) > 1048576:
                raise ValueError('The hosting environment exceeds its size limit.')
            contents.extend(block)

        client.retrbinary('RETR .env', receive)
        updated = patch_environment(bytes(contents), values)
        try:
            # Both names are covered by the existing .env denial rule; rename switches atomically.
            client.storbinary('STOR ' + staged, io.BytesIO(updated))
            client.sendcmd('SITE CHMOD 600 ' + staged)
            client.rename(staged, '.env')
        except Exception:
            try:
                client.delete(staged)
            except ftplib.all_errors:
                pass
            raise


def verify_collector(base, token):
    request = urllib.request.Request(base + '/api/collector/status', headers={
        'Authorization': 'Bearer ' + token, 'Accept': 'application/json',
    })
    with urllib.request.build_opener(NoRedirects).open(request, timeout=30) as response:
        data = json.loads(response.read(65536))
        if response.status != 200 or data.get('ok') is not True or data.get('catalogue_enabled') is not True:
            raise ValueError('The deployed collector API did not confirm shared discovery.')
        if not any(int(source.get('enabled', 0)) == 1 for source in data.get('sources', [])):
            raise ValueError('The deployed catalogue has no enabled collection sources.')
    print('Verified authenticated collector callbacks, shared discovery and enabled sources.')


def run():
    if not os.environ.get('COLLECTOR_TOKEN'):
        print('Collector settings are managed separately; existing hosting configuration preserved.')
        return
    configuration()
    _, base, _ = settings()
    values = collector_values()
    verify_env_protection(base)
    configure_host(values)
    verify_collector(base, values['COLLECTOR_TOKEN'])
    print('Collector hosting configuration updated; unrelated environment values preserved.')


if __name__ == '__main__':
    try:
        run()
    except Exception:
        print('Collector configuration failed. Check collector settings, verified FTPS access and hosting logs; credentials were not printed.', file=sys.stderr)
        sys.exit(1)
