<?php
/**
 * Utilidades DNS para Lightweight-Hosting
 * Soporta servidores en BD (sys_dns_servers) y constantes legacy (DNS_TOKEN/DNS_SERVER)
 */

/**
 * Obtiene la lista de servidores DNS activos.
 * Prioriza la tabla sys_dns_servers. Si no hay, usa constantes legacy.
 * Retorna array de ['url' => ..., 'token' => ...]
 */
function getDnsServers() {
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $pdo = getPDO();
        $stmt = $pdo->query("SELECT url, token, name FROM sys_dns_servers WHERE is_active = 1 ORDER BY id ASC");
        $servers = $stmt->fetchAll();
        if (!empty($servers)) {
            $cache = $servers;
            return $cache;
        }
    } catch (Exception $e) {
        // Tabla no existe aún, fallback a constantes
    }

    // Fallback: constantes legacy
    if (defined('DNS_TOKEN') && defined('DNS_SERVER') && !empty(DNS_TOKEN) && !empty(DNS_SERVER)) {
        $urls = array_filter(array_map('trim', explode(',', DNS_SERVER)));
        $cache = [];
        foreach ($urls as $u) {
            $cache[] = ['url' => $u, 'token' => DNS_TOKEN, 'name' => parse_url($u, PHP_URL_HOST) ?? $u];
        }
        return $cache;
    }

    $cache = [];
    return $cache;
}

/**
 * Verifica si hay servidores DNS configurados (BD o constantes).
 */
function hasDnsServers() {
    return !empty(getDnsServers());
}

/**
 * Verifica salud del cluster DNS.
 */
function getDnsClusterHealth() {
    $servers = getDnsServers();
    if (empty($servers)) {
        return ['status' => 'disabled', 'message' => 'DNS no configurado'];
    }

    $results = [];
    $allOk = true;
    $errors = 0;

    foreach ($servers as $s) {
        $res = dnsApiRequestOnServer($s['url'], '/api-dns/zones', 'GET', null, $s['token']);
        $host = parse_url($s['url'], PHP_URL_HOST) ?? $s['url'];

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
 * Realiza una petición a UN servidor DNS concreto.
 * Acepta token como parámetro (ya no depende de constantes).
 */
function dnsApiRequestOnServer($serverUrl, $endpoint, $method = 'GET', $data = null, $token = null) {
    // Compatibilidad legacy: si no se pasa token, intentar usar constante
    if ($token === null && defined('DNS_TOKEN')) {
        $token = DNS_TOKEN;
    }
    if (empty($token)) return ['code' => 0, 'response' => '', 'error' => 'No token'];

    $baseUrl = (strpos($serverUrl, 'http') === 0) ? rtrim($serverUrl, '/') : "http://" . rtrim($serverUrl, '/');
    $url = $baseUrl . $endpoint;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $headers = ["Authorization: Bearer " . $token, "Accept: application/json"];
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = "Content-Type: application/json";
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $httpCode, 'response' => $response];
}

/**
 * Realiza una petición a todos los servidores DNS configurados (para POST/PUT/DELETE)
 * o solo al primero (para GET).
 */
function dnsApiRequest($endpoint, $method = 'GET', $data = null) {
    $servers = getDnsServers();
    if (empty($servers)) return ['code' => 0, 'response' => ''];

    if ($method === 'GET') {
        return dnsApiRequestOnServer($servers[0]['url'], $endpoint, $method, $data, $servers[0]['token']);
    }

    $mainRes = null;
    foreach ($servers as $idx => $s) {
        $res = dnsApiRequestOnServer($s['url'], $endpoint, $method, $data, $s['token']);
        if ($idx === 0) $mainRes = $res;
    }
    return $mainRes;
}

/**
 * Obtiene las URLs de los servidores (para compatibilidad con código existente que usa $servers como array de URLs).
 */
function getDnsServerUrls() {
    $servers = getDnsServers();
    return array_column($servers, 'url');
}
