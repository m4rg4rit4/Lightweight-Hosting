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

function queueDnsAssignment($domain, $server, $revision) {
    $servers = getDnsServers();
    if (!$servers || !is_numeric($revision) || $revision < 0) {
        return ['code' => 400, 'response' => json_encode(['message' => 'Actualiza la página para obtener la asignación vigente.'])];
    }
    // All Hosting nodes must use the same first DNS as assignment authority.
    return dnsApiRequest('/api-dns/zone/assignment/decide', 'POST', [
        'domain' => $domain, 'server' => $server, 'expected_revision' => (int)$revision
    ], [$servers[0]]);
}

function getDnsAssignmentState($domain) {
    $nodes = [];
    foreach (getDnsServers() as $server) {
        $res = dnsApiRequestOnServer($server['url'], '/api-dns/zone/assignment?domain=' . urlencode($domain), 'GET', null, $server['token']);
        $data = json_decode($res['response'] ?: '', true);
        $ok = $res['code'] === 200 && isset($data['revision']) && array_key_exists('server', $data);
        $nodes[] = ['name' => $server['name'], 'ok' => $ok, 'server' => $ok ? $data['server'] : null, 'revision' => $ok ? $data['revision'] : null];
    }
    $pending = 0; $conflict = null; $latestDecisionSeen = false;
    try {
        $pdo = getPDO(); dnsOutboxEnsureTable($pdo);
        $stmt = $pdo->prepare("SELECT endpoint, status, last_error FROM sys_dns_outbox WHERE endpoint LIKE '/api-dns/zone/assignment/%' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.domain')) = ? ORDER BY id DESC");
        $stmt->execute([$domain]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
            if (!in_array($task['status'], ['completed', 'conflict'], true)) $pending++;
            if (!$latestDecisionSeen && $task['endpoint'] === '/api-dns/zone/assignment/decide') {
                $latestDecisionSeen = true;
                if ($task['status'] === 'conflict') $conflict = $task['last_error'];
            }
        }
    } catch (Exception $e) { error_log('Assignment status: ' . $e->getMessage()); }
    $primary = $nodes[0] ?? ['ok' => false];
    $consistent = !empty($nodes) && $primary['ok'];
    foreach ($nodes as $node) {
        if (!$node['ok'] || $node['server'] !== ($primary['server'] ?? null) || $node['revision'] !== ($primary['revision'] ?? null)) $consistent = false;
    }
    return ['nodes' => $nodes, 'primary' => $primary, 'consistent' => $consistent, 'pending' => $pending, 'conflict' => $conflict];
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
    $error = curl_error($ch);
    curl_close($ch);

    return ['code' => $httpCode, 'response' => $response, 'error' => $error];
}

/**
 * Realiza una petición a todos los servidores DNS configurados (para POST/PUT/DELETE)
 * o solo al primero (para GET).
 *
 * Para escrituras, code=200 confirma que las tareas están guardadas para todos
 * los nodos. Cada tarea se completa solo cuando el nodo confirma la publicación.
 */
function dnsApiRequest($endpoint, $method = 'GET', $data = null, $destinations = null) {
    $servers = $destinations ?? getDnsServers();
    if (empty($servers)) return ['code' => 0, 'response' => '', 'nodes' => []];

    if ($method === 'POST' && $endpoint === '/api-dns/add' && empty($data['nameservers'])) {
        $data['nameservers'] = [];
        foreach (getDnsServers() as $node) {
            $host = parse_url($node['url'], PHP_URL_HOST);
            if (!$host) $host = parse_url('http://' . $node['url'], PHP_URL_HOST);
            if ($host) $data['nameservers'][] = $host;
        }
    }

    if ($method === 'GET') {
        return dnsApiRequestOnServer($servers[0]['url'], $endpoint, $method, $data, $servers[0]['token']);
    }

    // Persist every destination before sending anything. A crash or timeout
    // leaves the same durable job key for the next engine run.
    $pdo = getPDO();
    $locked = false;
    try {
        dnsOutboxEnsureTable($pdo);
        $locked = (int)$pdo->query("SELECT GET_LOCK('lwh_dns_outbox_enqueue', 10)")->fetchColumn() === 1;
        if (!$locked) throw new RuntimeException('La cola DNS está ocupada; vuelve a intentarlo.');
        $pdo->beginTransaction();
        $ids = [];
        foreach ($servers as $server) {
            if ((int)$server['id'] <= 0) throw new RuntimeException('Registra los nodos DNS en el panel para utilizar tareas persistentes.');
            $key = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO sys_dns_outbox (dns_server_id, dns_server_name, endpoint, payload, job_key, status, next_retry_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())")
                ->execute([$server['id'], $server['name'], $endpoint, $data === null ? null : json_encode($data), $key]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['code' => 503, 'response' => json_encode(['message' => 'No se pudo guardar la tarea DNS: ' . $e->getMessage()]), 'nodes' => []];
    } finally {
        if ($locked) $pdo->query("SELECT RELEASE_LOCK('lwh_dns_outbox_enqueue')");
    }
    // Keep existing callers compatible. HTTP 200 here means durably accepted;
    // published state is reported separately and never inferred from acceptance.
    // The engine performs network I/O. Saving in the panel must not wait on
    // an unavailable node or an older job's publication.
    $nodes = [];
    foreach ($ids as $id) {
        $stmt = $pdo->prepare("SELECT id AS task_id, dns_server_id AS id, dns_server_name AS name, status, last_error FROM sys_dns_outbox WHERE id = ?");
        $stmt->execute([$id]);
        $nodes[] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return ['code' => 200, 'response' => json_encode(['success' => true, 'message' => 'Cambio encolado por servidor DNS']), 'nodes' => $nodes, 'queued' => true];
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
        job_key CHAR(64) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        completed_at DATETIME NULL,
        next_retry_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_retry (next_retry_at),
        INDEX idx_node_pending (dns_server_id, status, id)
    )");
    // Upgrade the previous retry-only outbox without dropping pending work.
    $columns = $pdo->query("SHOW COLUMNS FROM sys_dns_outbox")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'job_key' => 'CHAR(64) NULL',
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
        'completed_at' => 'DATETIME NULL'
    ] as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE sys_dns_outbox ADD COLUMN $column $definition");
        }
    }
    $done = true;
}

/**
 * Número de publicaciones pendientes, para avisar en la cabecera.
 */
function dnsOutboxPendingCount() {
    try {
        $pdo = getPDO();
        dnsOutboxEnsureTable($pdo);
        return (int)$pdo->query("SELECT COUNT(*) FROM sys_dns_outbox WHERE status NOT IN ('completed', 'conflict')")->fetchColumn();
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

/** Process only each node's oldest outstanding job, under one DB worker lock.
 * Strict per-node FIFO is stronger than per-zone FIFO and also orders bulk jobs.
 * Completed receipts remain available for inspection; failures are never dropped.
 */
function dnsOutboxProcess($pdo, $limit = 50, $transport = null, $destinations = null) {
    dnsOutboxEnsureTable($pdo);
    if ((int)$pdo->query("SELECT GET_LOCK('lwh_dns_outbox_worker', 0)")->fetchColumn() !== 1) return;
    try {
        $servers = [];
        foreach ($destinations ?? getDnsServers() as $server) $servers[(int)$server['id']] = $server;
        for ($i = 0; $i < $limit; $i++) {
            // Wait for any batch being inserted to commit before selecting the
            // oldest job. Do not hold this lock during network requests.
            if ((int)$pdo->query("SELECT GET_LOCK('lwh_dns_outbox_enqueue', 10)")->fetchColumn() !== 1) break;
            try {
                $row = $pdo->query("SELECT q.* FROM sys_dns_outbox q
                WHERE q.status NOT IN ('completed', 'conflict') AND q.next_retry_at <= NOW()
                  AND NOT EXISTS (SELECT 1 FROM sys_dns_outbox older
                      WHERE older.dns_server_id = q.dns_server_id AND older.id < q.id AND older.status NOT IN ('completed', 'conflict'))
                ORDER BY q.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            } finally {
                $pdo->query("SELECT RELEASE_LOCK('lwh_dns_outbox_enqueue')");
            }
            if (!$row) break;
            $srv = $servers[(int)$row['dns_server_id']] ?? null;
            if (!$srv) {
                $pdo->prepare("UPDATE sys_dns_outbox SET status = 'error', last_error = 'Servidor eliminado o inactivo', next_retry_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?")->execute([$row['id']]);
                continue;
            }
            if (empty($row['job_key'])) {
                $row['job_key'] = bin2hex(random_bytes(32));
                $pdo->prepare("UPDATE sys_dns_outbox SET job_key = ? WHERE id = ?")->execute([$row['job_key'], $row['id']]);
            }
            $payload = $row['payload'] === null ? null : json_decode($row['payload'], true);
            $send = $transport ?? 'dnsApiRequestOnServer';
            $res = $send($srv['url'], '/api-dns/jobs?wait=1', 'POST', [
                'key' => $row['job_key'], 'endpoint' => $row['endpoint'], 'payload' => $payload
            ], $srv['token']);
            $body = json_decode($res['response'] ?: '', true);
            $complete = $res['code'] === 200 && ($body['status'] ?? '') === 'completed';
            $decision = $row['endpoint'] === '/api-dns/zone/assignment/decide';
            $assignmentTask = strpos($row['endpoint'], '/api-dns/zone/assignment/') === 0;
            $conflict = $assignmentTask && in_array($res['code'], [404, 409], true);
            $waiting = $res['code'] === 202 && ($body['success'] ?? false) && ($body['status'] ?? '') !== 'error';
            $attempts = (int)$row['attempts'] + 1;
            $delay = $waiting ? 15 : min(3600, 60 * (2 ** min($attempts, 6)));
            $error = $complete ? null : ($body['error'] ?? $body['message'] ?? $res['error'] ?? ('HTTP ' . $res['code']));
            if (!$complete && !$waiting && empty($error)) $error = 'HTTP ' . $res['code'];
            // Only an accepted decision is replicated. Save its secondary tasks
            // and completion atomically: recovery reuses the primary's receipt.
            if ($decision && $complete) {
                $accepted = $body['result'] ?? null;
                if (!is_array($accepted) || !isset($accepted['revision'], $accepted['domain'], $accepted['server'])) {
                    throw new RuntimeException('El DNS principal no devolvió una decisión de asignación válida.');
                }
                $pdo->beginTransaction();
                try {
                    foreach ($servers as $target) {
                        if ((int)$target['id'] === (int)$row['dns_server_id']) continue;
                        $replica = ['domain' => $accepted['domain'], 'server' => $accepted['server'], 'revision' => $accepted['revision']];
                        $key = hash('sha256', $row['job_key'] . ':assignment:' . $target['id']);
                        $pdo->prepare("INSERT INTO sys_dns_outbox (dns_server_id, dns_server_name, endpoint, payload, job_key, status, next_retry_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())")
                            ->execute([$target['id'], $target['name'], '/api-dns/zone/assignment/apply', json_encode($replica), $key]);
                    }
                    $pdo->prepare("UPDATE sys_dns_outbox SET status = 'completed', attempts = ?, last_code = ?, last_error = NULL, completed_at = NOW() WHERE id = ?")
                        ->execute([$attempts, $res['code'], $row['id']]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                continue;
            }
            $pdo->prepare("UPDATE sys_dns_outbox SET status = ?, attempts = ?, last_code = ?, last_error = ?, next_retry_at = ?, completed_at = ? WHERE id = ?")
                ->execute([$conflict ? 'conflict' : ($complete ? 'completed' : ($waiting ? 'publishing' : 'error')), $attempts, $res['code'], $error,
                    date('Y-m-d H:i:s', time() + $delay), $complete ? date('Y-m-d H:i:s') : null, $row['id']]);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('lwh_dns_outbox_worker')");
    }
}
