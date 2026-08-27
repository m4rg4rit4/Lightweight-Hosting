#!/usr/bin/php
<?php
/**
 * Certbot DNS Cleanup for Lightweight-DNS
 */

require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../admin/dns_utils.php';

$domain = getenv('CERTBOT_DOMAIN');
$validation = getenv('CERTBOT_VALIDATION');

if (!$domain || !$validation) {
    exit(1);
}

// Los servidores salen de sys_dns_servers, con el fallback a constantes legacy que
// ya implementa getDnsServers(). Antes se leía DNS_SERVER/DNS_TOKEN directamente y el
// config.php actual ya no las define: en una instalación nueva esto moría con un
// fatal error y el registro TXT de validación se quedaba en la zona para siempre.
$servers = getDnsServers();
if (empty($servers)) exit(1);

// 1. Identificar la zona
$res = dnsApiRequestOnServer($servers[0]['url'], '/api-dns/zones', 'GET', null, $servers[0]['token']);
$data = json_decode($res['response'], true);
$zones = array_column($data['zones'] ?? [], 'domain');

$foundZone = findLongestZoneSuffix($domain, $zones);

if (!$foundZone) exit(1);

$name = ($domain === $foundZone) ? '_acme-challenge' : '_acme-challenge.' . substr($domain, 0, -(strlen($foundZone) + 1));

// 2. Eliminar el registro TXT.
// Se recorre nodo a nodo a propósito: el id de sys_dns_records es de cada nodo, así
// que hay que leer los registros del mismo servidor al que luego se le pide el
// borrado. No se manda 'domain' para no chocar con la validación de pertenencia de
// la API, que aquí no aporta nada porque el id ya viene de ese mismo nodo.
foreach ($servers as $s) {
    $res = dnsApiRequestOnServer($s['url'], '/api-dns/records/' . urlencode($foundZone), 'GET', null, $s['token']);
    if ($res['code'] !== 200) continue;

    $recs = json_decode($res['response'], true)['records'] ?? [];

    foreach ($recs as $r) {
        if ($r['name'] === $name && $r['type'] === 'TXT' && trim($r['content'], '"') === $validation) {
            dnsApiRequestOnServer($s['url'], '/api-dns/record/del', 'POST', ['id' => $r['id']], $s['token']);
        }
    }
}
