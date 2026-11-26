"""Apply an operator-supplied hosting backup time without changing backup policy."""

import os
import re
import sys

from configure_collector import configure_host
from db_deploy import configuration, settings, verify_env_protection


def run():
    backup_time = os.environ.get('DB_DEPLOY_BACKUP_UTC', '')
    if not backup_time:
        print('No backup schedule supplied; the hosting schedule is preserved.')
        return
    if not re.fullmatch(r'(?:[01][0-9]|2[0-3]):[0-5][0-9]', backup_time):
        raise ValueError('Supply the actual hosting backup time as HH:MM in UTC.')
    configuration()
    _, base, _ = settings()
    verify_env_protection(base)
    configure_host({'DB_DEPLOY_BACKUP_UTC': backup_time})
    print('Hosting backup schedule configured from the operator-supplied UTC time.')


if __name__ == '__main__':
    try:
        run()
    except Exception:
        print('Backup schedule configuration failed. Check the actual UTC time and verified FTPS access; credentials were not printed.', file=sys.stderr)
        sys.exit(1)
