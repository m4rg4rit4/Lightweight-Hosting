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
        // 'id' es imprescindible: el monitor periódico de server.php indexa por $srv['id']
        // y hace UPDATE ... WHERE id = ?. Sin esta columna todo aquello opera sobre NULL
        // y sys_dns_servers.sync_status no se actualiza nunca.
        $stmt = $pdo->query("SELECT id, url, token, name FROM sys_dns_servers WHERE is_active = 1 ORDER BY id ASC");
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
        // Sin tabla no hay id real. Damos ids negativos: sirven para indexar sin colapsar
        // todos los servidores bajo la misma clave NULL, y nunca colisionan con un
        // AUTO_INCREMENT real ni provocan conversiones raras en un WHERE id = ?.
        $i = 0;
        foreach ($urls as $u) {
            $cache[] = ['id' => -(++$i), 'url' => $u, 'token' => DNS_TOKEN, 'name' => parse_url($u, PHP_URL_HOST) ?? $u];
        }
        return $cache;
    }

    $cache = [];
    return $cache;
}

/**
 * Slug que identifica a ESTE servidor de hosting dentro del cluster DNS.
 *
 * Se reutiliza DNS_HOSTNAME, que install.sh ya escribe en config.php de todas las
 * instalaciones (el hostname corto, p.ej. 'srv1') y que hasta ahora no usaba nadie:
 * así no hay que configurar nada en los servidores que ya están en producción.
 * sys_settings.dns_node_slug lo sobreescribe si hace falta.
 */
function getDnsNodeSlug() {
    static $slug = null;
    if ($slug !== null) return $slug;

    try {
        $v = getPDO()->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'dns_node_slug'")->fetchColumn();
        if (!empty($v)) return $slug = strtolower(trim($v));
    } catch (Exception $e) {
        // sys_settings puede no existir todavía
    }

    if (defined('DNS_HOSTNAME') && !empty(DNS_HOSTNAME)) {
        return $slug = strtolower(trim(DNS_HOSTNAME));
    }

    $host = gethostname();
    return $slug = $host ? strtolower(explode('.', $host)[0]) : '';
}

/**
 * Si el filtrado por servidor está activo. Poniendo dns_filter_enabled a 0 el panel
 * vuelve a comportarse exactamente como antes: todas las zonas, sin selector.
 */
function isDnsFilterEnabled() {
    static $enabled = null;
    if ($enabled !== null) return $enabled;

    try {
        $v = getPDO()->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'dns_filter_enabled'")->fetchColumn();
        if ($v !== false && $v !== null && $v !== '') return $enabled = ((int)$v === 1);
    } catch (Exception $e) {
        // sys_settings puede no existir todavía
    }
    return $enabled = true;
}

/**
 * Zonas del cluster, cacheadas por petición.
 *
 * Se pide la lista COMPLETA una sola vez y se filtra en PHP. Así el selector de
 * servidor, los badges y los contadores salen gratis, y sobre todo se deja de pedir
 * /api-dns/zones tres veces por carga de página (header.php lo pedía a cada nodo
 * para el punto de salud, y encima dns.php o index.php lo volvían a pedir).
 *
 * Devuelve:
 *   ok           => si el primario respondió
 *   zones        => lista de zonas tal cual las da la API
 *   domains      => solo los nombres
 *   servers      => slugs en uso
 *   has_server   => si la API reporta server_slug. Si es false hay un nodo con
 *                   binario antiguo y NO se debe filtrar, o la lista saldría vacía.
 */
function getDnsZonesData() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $res = dnsApiRequest('/api-dns/zones', 'GET');
    if ($res['code'] !== 200) {
        return $cache = ['ok' => false, 'zones' => [], 'domains' => [], 'servers' => [], 'has_server' => false];
    }

    $data = json_decode($res['response'], true);
    $raw = $data['zones'] ?? $data['data'] ?? [];

    $zones = [];
    $hasServer = true;
    foreach ($raw as $z) {
        // La API antigua devolvía la lista como strings sueltos.
        if (!is_array($z)) {
            $zones[] = ['domain' => $z, 'server_slug' => ''];
            $hasServer = false;
            continue;
        }
        if (!array_key_exists('server_slug', $z)) $hasServer = false;
        $z['server_slug'] = strtolower(trim($z['server_slug'] ?? ''));
        $zones[] = $z;
    }

    return $cache = [
        'ok' => true,
        'zones' => $zones,
        'domains' => array_column($zones, 'domain'),
        'servers' => $data['servers'] ?? [],
        'has_server' => $hasServer && !empty($zones),
    ];
}

/**
 * Mapa dominio => slug del servidor propietario ('' si no está asignada).
 */
function getDnsZoneOwners() {
    $data = getDnsZonesData();
    $map = [];
    foreach ($data['zones'] as $z) {
        $map[$z['domain']] = $z['server_slug'];
    }
    return $map;
}

/**
 * Huella de un registro DNS, para comparar el mismo registro entre nodos.
 *
 * Mismo formato que usa la API para calcular records_hash (name|type|content|ttl|
 * priority), a propósito. Incluir ttl y priority es lo que hace que un TTL cambiado o
 * una prioridad de MX distinta entre nodos se detecte: comparando solo
 * name|type|content esas diferencias no se corregían nunca.
 */
function dnsRecordFingerprint($r) {
    $prio = (isset($r['priority']) && $r['priority'] !== null && $r['priority'] !== '')
        ? (string)(int)$r['priority']
        : '';

    return strtolower(trim($r['name'] ?? '@')) . '|'
         . strtoupper(trim($r['type'] ?? '')) . '|'
         . trim($r['content'] ?? '') . '|'
         . (string)(int)($r['ttl'] ?? 3600) . '|'
         . $prio;
}

/**
 * Devuelve, de una lista de zonas, la que es sufijo más largo de $domain.
 *
 * Coger la primera coincidencia no vale: si existen 'example.com' y
 * 'sub.example.com' como zonas separadas, un dominio 'x.sub.example.com' debe caer
 * en la más específica. La API devuelve las zonas ordenadas alfabéticamente, así que
 * la primera coincidencia sería justamente la equivocada.
 *
 * Retorna null si ninguna zona contiene el dominio.
 */
function findLongestZoneSuffix($domain, $zones) {
    $found = null;
    foreach ($zones as $z) {
        if ($domain !== $z && substr($domain, -strlen('.' . $z)) !== '.' . $z) continue;
        if ($found === null || strlen($z) > strlen($found)) {
            $found = $z;
        }
    }
    return $found;
}

/**
 * Verifica si hay servidores DNS configurados (BD o constantes).
 */
function hasDnsServers() {
    return !empty(getDnsServers());
}

/**
 * Verifica salud del cluster DNS.
 *
 * El resultado se cachea 60 segundos en sys_settings. header.php lo llama en TODAS
 * las páginas del panel y cada llamada hacía un GET /api-dns/zones por nodo, es
 * decir, el volcado completo de todas las zonas solo para pintar un punto de color.
 * Con muchos dominios eso se notaba en cada clic.
 */
function getDnsClusterHealth() {
    $servers = getDnsServers();
    if (empty($servers)) {
        return ['status' => 'disabled', 'message' => 'DNS no configurado'];
    }

    $cacheTtl = 60;
    try {
        $pdo = getPDO();
        $row = $pdo->query("SELECT setting_value, UNIX_TIMESTAMP(updated_at) AS ts FROM sys_settings WHERE setting_key = 'dns_cluster_health'")->fetch();
        if ($row && (time() - (int)$row['ts']) < $cacheTtl) {
            $cached = json_decode($row['setting_value'], true);
            if (is_array($cached)) return $cached;
        }
    } catch (Exception $e) {
        // Sin caché disponible: se comprueba en vivo.
    }

    $results = [];
    $allOk = true;
    $errors = 0;

    foreach ($servers as $s) {
        // /status/pending en vez de /zones: prueba lo mismo (nodo vivo, token válido,
        // BD accesible) devolviendo un contador en vez de todas las zonas.
        $res = dnsApiRequestOnServer($s['url'], '/api-dns/status/pending', 'GET', null, $s['token']);
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
        $health = ['status' => 'ok', 'message' => 'Cluster OK (' . count($results) . ' nodos)', 'nodes' => $results];
    } elseif ($errors < count($results)) {
        $health = ['status' => 'warning', 'message' => "$errors nodos caídos", 'nodes' => $results];
    } else {
        $health = ['status' => 'error', 'message' => "Cluster desconectado", 'nodes' => $results];
    }

    try {
        getPDO()->prepare("REPLACE INTO sys_settings (setting_key, setting_value) VALUES ('dns_cluster_health', ?)")
                ->execute([json_encode($health)]);
    } catch (Exception $e) {
        // Si no se puede cachear, simplemente se recomprobará en la siguiente página.
    }

    return $health;
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
 *
 * Para POST devuelve el resultado del primer nodo (compatibilidad: todo el panel lee
 * ['code']) y añade ['nodes'] con el detalle por servidor. Los nodos que fallan se
 * encolan en sys_dns_outbox para reenviarlos después: antes se descartaba el
 * resultado de todos menos el primero, así que si un secundario daba timeout el panel decía
 * "guardado correctamente" y la divergencia no la detectaba nadie.
 */
function dnsApiRequest($endpoint, $method = 'GET', $data = null) {
    $servers = getDnsServers();
    if (empty($servers)) return ['code' => 0, 'response' => '', 'nodes' => []];

    if ($method === 'GET') {
        return dnsApiRequestOnServer($servers[0]['url'], $endpoint, $method, $data, $servers[0]['token']);
    }

    $mainRes = null;
    $nodes = [];
    foreach ($servers as $idx => $s) {
        $res = dnsApiRequestOnServer($s['url'], $endpoint, $method, $data, $s['token']);
        $ok = ($res['code'] >= 200 && $res['code'] < 300);

        $nodes[] = ['id' => $s['id'], 'name' => $s['name'], 'url' => $s['url'], 'code' => $res['code'], 'ok' => $ok];

        if (!$ok) {
            dnsOutboxEnqueue($s['id'], $s['name'], $endpoint, $data, $res['code']);
        }
        if ($idx === 0) $mainRes = $res;
    }

    $mainRes['nodes'] = $nodes;
    return $mainRes;
}

/**
 * Crea la tabla de reenvíos pendientes si no existe. Barata e idempotente.
 */
function dnsOutboxEnsureTable($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_dns_outbox (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dns_server_id INT NOT NULL,
        dns_server_name VARCHAR(100) NOT NULL,
        endpoint VARCHAR(255) NOT NULL,
        payload TEXT NULL,
        attempts INT NOT NULL DEFAULT 0,
        last_code INT NULL,
        last_error TEXT NULL,
        next_retry_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_retry (next_retry_at)
    )");
    $done = true;
}

/**
 * Encola un POST que un nodo no aceptó, para que el motor lo reintente.
 *
 * Sin id de servidor (rama legacy con ids negativos) no se encola: no habría forma
 * fiable de volver a resolver a qué nodo reenviarlo.
 */
function dnsOutboxEnqueue($serverId, $serverName, $endpoint, $data, $code) {
    // PDO puede devolver el id como string según versión, así que se castea. Los ids
    // de la rama legacy son negativos y quedan descartados por el mismo check.
    $serverId = (int)$serverId;
    if ($serverId <= 0) return;

    try {
        $pdo = getPDO();
        dnsOutboxEnsureTable($pdo);
        $pdo->prepare("INSERT INTO sys_dns_outbox (dns_server_id, dns_server_name, endpoint, payload, last_code, next_retry_at)
                       VALUES (?, ?, ?, ?, ?, NOW())")
            ->execute([$serverId, $serverName, $endpoint, $data === null ? null : json_encode($data), $code ?: null]);
    } catch (Exception $e) {
        // Si ni siquiera se puede encolar, no bloqueamos la acción del usuario:
        // el monitor periódico seguirá detectando la divergencia por hash.
    }
}

/**
 * Número de reenvíos pendientes, para avisar en la cabecera.
 */
function dnsOutboxPendingCount() {
    try {
        $pdo = getPDO();
        return (int)$pdo->query("SELECT COUNT(*) FROM sys_dns_outbox")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Obtiene las URLs de los servidores (para compatibilidad con código existente que usa $servers como array de URLs).
 */
function getDnsServerUrls() {
    $servers = getDnsServers();
    return array_column($servers, 'url');
}
