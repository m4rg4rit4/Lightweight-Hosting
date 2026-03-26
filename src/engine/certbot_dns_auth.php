#!/usr/bin/php
<?php
/**
 * Certbot DNS Authenticator for Lightweight-DNS
 * Variables de entorno proporcionadas por Certbot:
 * CERTBOT_DOMAIN      : El dominio para el que se solicita el certificado
 * CERTBOT_VALIDATION  : El valor del token de validación TXT
 */

require_once __DIR__ . '/../admin/config.php';

$domain = getenv('CERTBOT_DOMAIN');
$validation = getenv('CERTBOT_VALIDATION');

if (!$domain || !$validation) {
    exit(1);
}

// El registro debe ser _acme-challenge.dominio.com
// Pero nuestra API espera el 'name' relativo a la zona.
// Si el dominio es 'example.com', el name es '_acme-challenge'
// Certbot nos da el dominio completo, necesitamos encontrar la zona base.

$servers = array_filter(array_map('trim', explode(',', DNS_SERVER)));
if (empty($servers)) exit(1);

// Helper para peticiones
function dnsApiRequestLocal($endpoint, $method = 'POST', $data = null) {
    global $servers;
    $results = [];
    foreach ($servers as $sUrl) {
        $baseUrl = (strpos($sUrl, 'http') === 0) ? rtrim($sUrl, '/') : "http://" . rtrim($sUrl, '/');
        $url = $baseUrl . $endpoint;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $headers = ["Authorization: Bearer " . DNS_TOKEN, "Accept: application/json"];
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = "Content-Type: application/json";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        curl_close($ch);
    }
}

// 1. Identificar la zona
// Buscamos cuál de nuestras zonas es sufijo de CERTBOT_DOMAIN
$resZones = [];
$baseUrl = (strpos($servers[0], 'http') === 0) ? rtrim($servers[0], '/') : "http://" . rtrim($servers[0], '/');
$ch = curl_init($baseUrl . '/api-dns/zones');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . DNS_TOKEN]);
$res = curl_exec($ch);
curl_close($ch);
$data = json_decode($res, true);
$zones = array_column($data['zones'] ?? [], 'domain');

$foundZone = null;
foreach ($zones as $z) {
    if ($domain === $z || (strpos($domain, '.' . $z) !== false && substr($domain, -strlen('.' . $z)) === '.' . $z)) {
        $foundZone = $z;
        break;
    }
}

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
