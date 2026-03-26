<?php
/**
 * Utilidades para verificación de salud del cluster DNS
 */

function getDnsClusterHealth() {
    if (!defined('DNS_SERVER') || !defined('DNS_TOKEN') || empty(DNS_SERVER)) {
        return ['status' => 'disabled', 'message' => 'DNS no configurado'];
    }

    $servers = array_filter(array_map('trim', explode(',', DNS_SERVER)));
    if (empty($servers)) return ['status' => 'disabled', 'message' => 'Sin servidores'];

    $results = [];
    $allOk = true;
    $errors = 0;

    foreach ($servers as $idx => $sUrl) {
        $res = dnsApiRequestOnServerSimplified($sUrl, '/api-dns/zones', 'GET');
        $host = parse_url($sUrl, PHP_URL_HOST);
        
        if ($res['code'] === 200) {
            $results[] = ['host' => $host, 'ok' => true];
        } else {
            $results[] = ['host' => $host, 'ok' => false, 'error' => $res['code'] ?: 'Timeout'];
            $allOk = false;
            $errors++;
        }
    }

    if ($allOk) {
        return ['status' => 'ok', 'message' => 'Cluster OK (' . count($results) . ' nodos)', 'nodes' => $results];
    } elseif ($errors < count($results)) {
        return ['status' => 'warning', 'message' => "$errors nodos caídos", 'nodes' => $results];
    } else {
        return ['status' => 'error', 'message' => "Cluster desconectado", 'nodes' => $results];
    }
}

/**
 * Realiza una petición a todos los servidores DNS configurados (para POST/PUT/DELETE)
 * o solo al primero (para GET).
 */
function dnsApiRequest($endpoint, $method = 'GET', $data = null) {
    if (!defined('DNS_TOKEN') || !defined('DNS_SERVER')) return ['code' => 0, 'response' => ''];
    $servers = array_filter(array_map('trim', explode(',', DNS_SERVER)));
    if (empty($servers)) return ['code' => 0, 'response' => ''];
    
    if ($method === 'GET') {
        return dnsApiRequestOnServerSimplified($servers[0], $endpoint, $method, $data);
    }
    
    $mainRes = null;
    foreach ($servers as $idx => $sUrl) {
        $res = dnsApiRequestOnServerSimplified($sUrl, $endpoint, $method, $data);
        if ($idx === 0) $mainRes = $res;
    }
    return $mainRes;
}

function dnsApiRequestOnServerSimplified($serverUrl, $endpoint, $method = 'GET', $data = null) {
    $baseUrl = (strpos($serverUrl, 'http') === 0) ? rtrim($serverUrl, '/') : "http://" . rtrim($serverUrl, '/');
    $url = $baseUrl . $endpoint;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $headers = ["Authorization: Bearer " . DNS_TOKEN, "Accept: application/json"];
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = "Content-Type: application/json";
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6); 
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['code' => $httpCode, 'response' => $response];
}
