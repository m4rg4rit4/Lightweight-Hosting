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

// 1. Identificar la zona
// Buscamos cuál de nuestras zonas es sufijo de CERTBOT_DOMAIN
$res = dnsApiRequestOnServer($servers[0]['url'], '/api-dns/zones', 'GET', null, $servers[0]['token']);
$data = json_decode($res['response'], true);
$zones = array_column($data['zones'] ?? [], 'domain');

$foundZone = findLongestZoneSuffix($domain, $zones);

if (!$foundZone) exit(1);

$name = ($domain === $foundZone) ? '_acme-challenge' : '_acme-challenge.' . substr($domain, 0, -(strlen($foundZone) + 1));

// 2. Añadir el registro TXT
$queued = dnsApiRequest('/api-dns/record/add', 'POST', [
    'domain' => $foundZone,
    'name' => $name,
    'type' => 'TXT',
    'content' => $validation,
    'ttl' => 60
]);

// Certbot must wait for publication on every authoritative node, not just
// acceptance by the API. The normal engine can process the same durable jobs.
if ($queued['code'] !== 200) exit(1);
$ids = array_column($queued['nodes'], 'task_id');
$pdo = getPDO();
$deadline = time() + 180;
do {
    dnsOutboxProcess($pdo, 50);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sys_dns_outbox WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND status <> 'completed'");
    $stmt->execute($ids);
    if ((int)$stmt->fetchColumn() === 0) { sleep(5); exit(0); }
    sleep(2);
} while (time() < $deadline);
fwrite(STDERR, "DNS: no se confirmó la publicación en todos los nodos. Consulta Tareas.\n");
exit(1);
