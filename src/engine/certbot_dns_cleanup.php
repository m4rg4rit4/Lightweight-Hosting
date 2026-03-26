#!/usr/bin/php
<?php
/**
 * Certbot DNS Cleanup for Lightweight-DNS
 */

require_once __DIR__ . '/../admin/config.php';

$domain = getenv('CERTBOT_DOMAIN');
$validation = getenv('CERTBOT_VALIDATION');

if (!$domain || !$validation) {
    exit(1);
}

$servers = array_filter(array_map('trim', explode(',', DNS_SERVER)));
if (empty($servers)) exit(1);

// Helpers
function dnsApiRequestLocal($endpoint, $method = 'POST', $data = null) {
    global $servers;
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
        curl_exec($ch);
        curl_close($ch);
    }
}

// 1. Identificar la zona
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

// 2. Eliminar el registro TXT
// Primero necesitamos el ID del registro
foreach ($servers as $sUrl) {
    $baseUrl = (strpos($sUrl, 'http') === 0) ? rtrim($sUrl, '/') : "http://" . rtrim($sUrl, '/');
    $ch = curl_init($baseUrl . '/api-dns/records/' . urlencode($foundZone));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . DNS_TOKEN]);
    $res = curl_exec($ch);
    curl_close($ch);
    $recsData = json_decode($res, true);
    $recs = $recsData['records'] ?? [];
    
    foreach ($recs as $r) {
        if ($r['name'] === $name && $r['type'] === 'TXT' && trim($r['content'], '"') === $validation) {
           // Si lo eliminamos en uno, ya se replica (en teoría si usamos la función dns.php, pero aquí estamos en el CLI)
           // Usamos una llamada POST directa para eliminar
           $ch = curl_init($baseUrl . '/api-dns/record/del');
           curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
           curl_setopt($ch, CURLOPT_POST, true);
           curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['id' => $r['id']]));
           curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . DNS_TOKEN, "Accept: application/json", "Content-Type: application/json"]);
           curl_exec($ch);
           curl_close($ch);
        }
    }
}
