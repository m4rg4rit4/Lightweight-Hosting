<?php
// Exercise the real vhost generator and certificate-name selection without services.
namespace VhostAliasesRegression;
use PDO;
$source = file_get_contents(__DIR__ . '/../src/engine/server.php');
foreach ([
    ['function getMissingCertNames(', 'function siteApplicationRoot('],
    ['function generateVhost(', '// --- Inicialización de Tablas'],
] as [$from, $to]) {
    $start = strpos($source, $from);
    $end = strpos($source, $to, $start);
    if ($start === false || $end === false) throw new \RuntimeException('Missing function boundary');
    eval("namespace VhostAliasesRegression; use PDO;\n" . substr($source, $start, $end - $start));
}
function file_exists($path) { return true; }
function getCertNames($domain) { global $certNames; return $certNames; }
function checkExternalDNS($domain, $ip) {
    global $dnsQueries, $dnsReady;
    $dnsQueries[] = $domain;
    return $dnsReady;
}
function expect($ok, $message) { if (!$ok) throw new \RuntimeException($message); }
class SettingsStatement {
    function execute($args) {}
    function fetch($mode) { return ['php_memory_limit' => '128M']; }
}
$pdo = new class {
    function prepare($query) { return new SettingsStatement(); }
};
$php_socket = '/run/php/php8.4-fpm.sock';
foreach ([false, true] as $ssl) {
    foreach ([false, true] as $php) {
        $conf = generateVhost('example.test', '/var/www/vhosts/example.test/public', $php, '8.4', $ssl);
        expect(strpos($conf, '<VirtualHost *:' . ($ssl ? 443 : 80) . '>') !== false, 'Wrong listener');
        expect(strpos($conf, "ServerName example.test\n") !== false, 'Lost main hostname');
        preg_match_all('/^\s*ServerAlias\s+(.+)$/m', $conf, $aliases);
        expect($aliases[1] === ['www.example.test'], 'Unexpected aliases');
        expect(!preg_match('/pma\.|phpmyadmin\.|RewriteCond|RewriteRule/', $conf), 'Database hostname routing returned');
        foreach (['phpmyadmin', 'dbadmin'] as $manager) {
            expect(strpos($conf, "Alias /$manager /var/www/admin_panel/$manager") !== false, 'Lost database manager path');
        }
        expect((strpos($conf, 'SSLCertificateFile') !== false) === $ssl, 'Lost TLS settings');
        expect((strpos($conf, 'PHP_ADMIN_VALUE') !== false) === $php, 'Changed PHP settings');
    }
}
foreach ([
    [['example.test'], true, ['www.example.test'], ['www.example.test']],
    [['example.test'], false, [], ['www.example.test']],
    [['example.test', 'www.example.test'], true, [], []],
    [['*.example.test'], true, [], []],
] as [$certNames, $dnsReady, $missing, $queries]) {
    $dnsQueries = [];
    expect(getMissingCertNames('example.test', '192.0.2.10') === $missing, 'Wrong missing certificate names');
    expect($dnsQueries === $queries, 'Requested DNS for a removed alias');
}
// Run the same argument selection used by the SSL task (both DNS-ready cases).
$start = strpos($source, "            \$email = defined('ADMIN_EMAIL')");
$end = strpos($source, '            // Usamos --keep-until-expiring', $start);
$arguments = substr($source, $start, $end - $start);
$domain = 'example.test';
foreach ([false, true] as $dnsWwwOk) {
    eval($arguments);
    $expected = '-d ' . escapeshellarg($domain);
    if ($dnsWwwOk) $expected .= ' -d ' . escapeshellarg('www.' . $domain);
    expect($domainsArg === $expected, 'Unexpected certificate request names');
}
echo "Vhost aliases and certificate selection tests passed\n";