#!/usr/bin/php
<?php
/**
 * Certbot DNS Authenticator for Lightweight-DNS
 * Variables de entorno proporcionadas por Certbot:
 * CERTBOT_DOMAIN      : El dominio para el que se solicita el certificado
 * CERTBOT_VALIDATION  : El valor del token de validación TXT
 */

require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../admin/dns_utils.php';

$domain = getenv('CERTBOT_DOMAIN');
$validation = getenv('CERTBOT_VALIDATION');

if (!$domain || !$validation) {
    exit(1);
}

// El registro debe ser _acme-challenge.dominio.com
// Pero nuestra API espera el 'name' relativo a la zona.
// Si el dominio es 'example.com', el name es '_acme-challenge'
// Certbot nos da el dominio completo, necesitamos encontrar la zona base.

// Los servidores salen de sys_dns_servers, con el fallback a constantes legacy que
// ya implementa getDnsServers(). Antes se leía DNS_SERVER/DNS_TOKEN directamente y el
// config.php actual ya no las define: en una instalación nueva esto moría con un
// fatal error, dejando la validación DNS-01 rota.
$servers = getDnsServers();
if (empty($servers)) exit(1);

// Helper para peticiones: escribe en TODOS los nodos, cada uno con su propio token.
function dnsApiRequestLocal($endpoint, $method = 'POST', $data = null) {
    global $servers;
    foreach ($servers as $s) {
        dnsApiRequestOnServer($s['url'], $endpoint, $method, $data, $s['token']);
    }
}

// 1. Identificar la zona
// Buscamos cuál de nuestras zonas es sufijo de CERTBOT_DOMAIN
$res = dnsApiRequestOnServer($servers[0]['url'], '/api-dns/zones', 'GET', null, $servers[0]['token']);
$data = json_decode($res['response'], true);
$zones = array_column($data['zones'] ?? [], 'domain');

$foundZone = findLongestZoneSuffix($domain, $zones);

if (!$foundZone) exit(1);

$name = ($domain === $foundZone) ? '_acme-challenge' : '_acme-challenge.' . substr($domain, 0, -(strlen($foundZone) + 1));

// 2. Añadir el registro TXT
dnsApiRequestLocal('/api-dns/record/add', 'POST', [
    'domain' => $foundZone,
    'name' => $name,
    'type' => 'TXT',
    'content' => $validation,
    'ttl' => 60
]);

// Esperar un poco para la propagación entre nodos si fuera necesario
sleep(5);
