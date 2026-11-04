"""Run as root on the Debian test host: python3 htaccess_live_test.py DOMAIN ROOT.

Creates a unique temporary directory in the selected site and removes it even
on failure. No real application files or credentials are changed.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import uuid


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('domain')
    parser.add_argument('document_root', type=Path)
    parser.add_argument('--foreign-socket', default='/run/php/php8.4-fpm.sock')
    args = parser.parse_args()
    user = pwd.getpwnam('lwh_' + hashlib.sha256(args.domain.encode()).hexdigest()[:16])
    directory = args.document_root / ('lwhcheck-' + uuid.uuid4().hex)
    directory.mkdir(mode=0o2770)
    os.chown(directory, user.pw_uid, user.pw_gid)

    def write(name, text):
        path = directory / name
        path.write_text(text)
        os.chown(path, user.pw_uid, user.pw_gid)
        path.chmod(0o660)

    def get(path):
        result = subprocess.run([
            'curl', '-sS', '--max-time', '10', '--resolve', args.domain + ':443:127.0.0.1',
            '-w', '\n%{http_code}',
            'https://' + args.domain + '/' + directory.name + '/' + path,
        ], capture_output=True, text=True, check=True)
        body, status = result.stdout.rsplit('\n', 1)
        return int(status), body

    try:
        write('first.txt', 'FIRST')
        write('second.txt', 'SECOND')
        write('probe.php', '<?php echo json_encode(["uid"=>posix_geteuid(),"panel_readable"=>is_readable("/var/www/admin_panel/config.php")]);')
        write('.htaccess', 'RewriteEngine On\nRewriteRule ^live$ first.txt [L]\n')
        assert get('live') == (200, 'FIRST')
        write('.htaccess', 'RewriteEngine On\nRewriteRule ^live$ second.txt [L]\n')
        assert get('live') == (200, 'SECOND')
        write('.htaccess', f'SetHandler "proxy:unix:{args.foreign_socket}|fcgi://localhost/"\n')
        status, body = get('probe.php')
        assert status == 200, (status, body)
        result = json.loads(body)
        assert result['uid'] == user.pw_uid and not result['panel_readable'], result
        write('.htaccess', f'RewriteEngine On\nRewriteRule ^attack$ "unix:{args.foreign_socket}|fcgi://localhost{directory}/probe.php" [P,L]\n')
        assert get('attack')[0] == 403
        write('.htaccess', f'RewriteEngine On\nRewriteRule ^probe.php$ - [H=proxy:unix:{args.foreign_socket}|fcgi://localhost/,L]\n')
        status, body = get('probe.php')
        if status == 200:
            # The root-managed Location handler may replace H before the guard.
            result = json.loads(body)
            assert result['uid'] == user.pw_uid and not result['panel_readable'], result
        else:
            assert status == 403, (status, body)
        print('Live .htaccess edits, site UID, [P] and foreign [H] isolation passed')
    finally:
        shutil.rmtree(directory)


if __name__ == '__main__':
    main()
