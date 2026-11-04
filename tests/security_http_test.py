"""Isolated HTTP regressions; no production DB, services or credentials required."""
from pathlib import Path
import http.cookiejar
import json
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request


def main():
    project = Path(__file__).resolve().parents[1]
    source = project / 'src' / 'admin'
    temporary = project / 'temp'
    temporary.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='lwh-http-', dir=temporary) as directory:
        base = Path(directory)
        panel = base / 'panel'
        shutil.copytree(source, panel)
        site = base / 'site'
        site.mkdir()
        (site / 'hello.txt').write_text('safe')
        (base / 'outside.txt').write_text('private')
        config = '''<?php
define('ADMIN_USER', 'admin');
define('ADMIN_PASS_HASH', password_hash('test-password', PASSWORD_DEFAULT));
class TestStatement {
    function execute($args = []) {}
    function fetch() { return ['id'=>1, 'domain'=>'test.example', 'document_root'=>SITE_ROOT]; }
}
class TestPDO { function prepare($sql) { return new TestStatement; } }
function getPDO() { return new TestPDO; }
'''
        config += 'define("LOGIN_RATE_DIR", ' + json.dumps((base/'rate').as_posix()) + ');\n'
        config += 'define("SITE_ROOT", ' + json.dumps(site.as_posix()) + ');\n'
        (panel / 'config.php').write_text(config, encoding='utf-8')
        # A diagnostic route exercises the real CSRF guard in the isolated panel.
        (panel / 'probe.php').write_text("<?php require 'auth.php'; checkAuth(); checkCsrf(); echo 'allowed';")
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        process = subprocess.Popen(['php', '-d', f'session.save_path={base.as_posix()}', '-S', f'127.0.0.1:{port}', '-t', str(panel)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        jar = http.cookiejar.CookieJar()
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, *args): return None
        client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())

        def request(path, data=None, headers=None):
            payload = urllib.parse.urlencode(data).encode() if data is not None else None
            req = urllib.request.Request(f'http://127.0.0.1:{port}/{path}', data=payload, headers=headers or {})
            try:
                result = client.open(req, timeout=5)
            except urllib.error.HTTPError as error:
                result = error
            return result.code, result.read().decode(), result.headers

        try:
            for _ in range(50):
                try:
                    status, page, _ = request('login.php')
                    break
                except urllib.error.URLError:
                    time.sleep(.1)
            else:
                raise AssertionError('PHP HTTP server did not start')
            assert status == 200
            token = re.search(r'name="csrf_token" value="([a-f0-9]+)"', page).group(1)
            for endpoint in ['index', 'dns', 'dns_servers', 'databases', 'backups', 'tasks', 'filemanager']:
                status, _, headers = request(endpoint + '.php', {'action':'delete','site_id':1,'path':'hello.txt'})
                assert status == 302 and 'login.php' in headers['Location'], endpoint
            assert (site / 'hello.txt').read_text() == 'safe'
            assert request('login.php', {'user':'admin','pass':'test-password'})[0] == 403
            status, body, headers = request('login.php?redirect=https://evil.example', {'user':'admin','pass':'test-password','csrf_token':token})
            assert status == 302 and headers['Location'] == 'index.php', (status, body)
            _, page, _ = request('login.php')
            token = re.search(r'name="csrf_token" value="([a-f0-9]+)"', page).group(1)
            assert request('probe.php', {'action':'write'})[0] == 403
            assert request('probe.php', {'csrf_token':token})[:2] == (200, 'allowed')
            assert request('probe.php', {}, {'X-CSRF-Token':token})[:2] == (200, 'allowed')
            for path in ['../outside.txt', '../missing/path', '/../../outside.txt']:
                status, body, _ = request('filemanager.php', {'action':'read','site_id':1,'path':path,'csrf_token':token})
                assert status == 200 and json.loads(body)['success'] is False, body
            status, body, _ = request('filemanager.php', {'action':'upload','site_id':1,'path':'../new-outside','csrf_token':token})
            assert not json.loads(body)['success'] and not (base/'new-outside').exists()
            status, body, _ = request('filemanager.php', {'action':'write','site_id':1,'path':'hello.txt','content':'updated','csrf_token':token})
            assert json.loads(body)['success'] and (site/'hello.txt').read_text() == 'updated', body
            # Parse the actual rendered JS with Node, catching PHP/template damage.
            _, html, _ = request('filemanager.php?site_id=1')
            scripts = '\n'.join(re.findall(r'<script[^>]*>(.*?)</script>', html, re.S))
            subprocess.run(['node','--check'],input=scripts,encoding='utf-8',check=True)
            print('Security HTTP tests passed: unauthenticated actions, CSRF, login, redirect, path escape, valid edit, rendered JS')
        finally:
            process.terminate()
            process.wait(timeout=5)


if __name__ == '__main__':
    main()
