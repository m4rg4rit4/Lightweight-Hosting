<?php
session_start();
require 'config.php';
require_once 'dns_utils.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$dnsServers = getDnsServers();
if (count($dnsServers) <= 1) {
    echo json_encode(['needed' => false, 'messages' => []]);
    exit;
}

$activeDomain = $_GET['domain'] ?? '';
$syncInfo = ['needed' => false, 'messages' => []];

// 1. Obtener zonas del servidor principal
$resZones = dnsApiRequest('/api-dns/zones', 'GET');
$apiZones = [];
if ($resZones['code'] === 200) {
    $dataZones = json_decode($resZones['response'], true);
    $apiZonesRaw = $dataZones['zones'] ?? $dataZones['data'] ?? [];
    if (!empty($apiZonesRaw) && isset($apiZonesRaw[0]['domain'])) {
        $apiZones = array_column($apiZonesRaw, 'domain');
    } else {
        $apiZones = (array)$apiZonesRaw;
    }
}

// 2. Verificar sincronización de zonas globales
foreach ($dnsServers as $idx => $srv) {
    if ($idx === 0) continue;
    $resOther = dnsApiRequestOnServer($srv['url'], '/api-dns/zones', 'GET', null, $srv['token']);
    if ($resOther['code'] !== 200) {
        $syncInfo['needed'] = true;
        $syncInfo['messages'][] = "Servidor " . (parse_url($srv['url'], PHP_URL_HOST) ?: $srv['url']) . " inaccesible.";
        continue;
    }
    $dataOther = json_decode($resOther['response'], true);
    $zORaw = $dataOther['zones'] ?? $dataOther['data'] ?? [];
    $zO = (!empty($zORaw) && isset($zORaw[0]['domain'])) ? array_column($zORaw, 'domain') : (array)$zORaw;
    
    if (count($apiZones) !== count($zO) || !empty(array_diff($apiZones, $zO)) || !empty(array_diff($zO, $apiZones))) {
        $syncInfo['needed'] = true;
        $syncInfo['messages'][] = "Diferencias en el listado de zonas globales.";
        break;
    }
}

// 3. Verificar sincronización de registros si hay dominio activo
if ($activeDomain && !$syncInfo['needed']) {
    $res = dnsApiRequest('/api-dns/records/' . urlencode($activeDomain), 'GET');
    if ($res['code'] === 200) {
        $data = json_decode($res['response'], true);
        $records = $data['records'] ?? [];
        
        if (!empty($records)) {
            $mH = [];
            foreach ((array)$records as $r) {
                if (($r['type'] ?? '') === 'SOA' || ($r['type'] ?? '') === 'NS') continue;
                $mH[] = strtolower(trim($r['name'] ?? '@')) . '|' . strtoupper(trim($r['type'] ?? '')) . '|' . trim($r['content'] ?? '');
            }

            foreach ($dnsServers as $idx => $srv) {
                if ($idx === 0) continue;
                $resOtherR = dnsApiRequestOnServer($srv['url'], '/api-dns/records/' . urlencode($activeDomain), 'GET', null, $srv['token']);
                if ($resOtherR['code'] !== 200) {
                    $syncInfo['needed'] = true;
                    $syncInfo['messages'][] = "Error al verificar registros de $activeDomain en " . (parse_url($srv['url'], PHP_URL_HOST) ?: $srv['url']);
                    break;
                }
                $rOtherData = json_decode($resOtherR['response'], true);
                $rOther = (array)($rOtherData['records'] ?? []);
                
                $oH = [];
                foreach ($rOther as $r) {
                    if (($r['type'] ?? '') === 'SOA' || ($r['type'] ?? '') === 'NS') continue;
                    $oH[] = strtolower(trim($r['name'] ?? '@')) . '|' . strtoupper(trim($r['type'] ?? '')) . '|' . trim($r['content'] ?? '');
                }

                if (count($mH) !== count($oH) || !empty(array_diff($mH, $oH)) || !empty(array_diff($oH, $mH))) {
                    $syncInfo['needed'] = true;
                    $syncInfo['messages'][] = "Registros de $activeDomain desincronizados.";
                    break;
                }
            }
        }
    }
}

echo json_encode($syncInfo);
