<?php
session_start();
require 'config.php';

// Verificación estricta de configuración DNS
if (!defined('DNS_TOKEN') || !defined('DNS_SERVER') || empty(DNS_TOKEN) || empty(DNS_SERVER)) {
    $_SESSION['flash_msg'] = "La gestión DNS no está configurada. Introduce un DNS_TOKEN y DNS_SERVER en config.php.";
    $_SESSION['flash_type'] = "error";
    header("Location: index.php");
    exit;
}

$pdo = getPDO();
$servers = array_filter(array_map('trim', explode(',', DNS_SERVER)));
$baseUrl = '';
if (!empty($servers)) {
    $serverUrl = $servers[0];
    $baseUrl = (strpos($serverUrl, 'http') === 0) ? rtrim($serverUrl, '/') : "http://" . rtrim($serverUrl, '/');
}

// Helper genérico para peticiones cURL a la API DNS
function dnsApiRequest($endpoint, $method = 'GET', $data = null) {
    global $baseUrl;
    $url = $baseUrl . $endpoint;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $headers = [
        "Authorization: Bearer " . DNS_TOKEN,
        "Accept: application/json"
    ];

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            $jsonData = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            $headers[] = "Content-Type: application/json";
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'response' => $response,
        'error' => $error
    ];
}

// Lógica de agrupación jerárquica
function getDomainHierarchy($allDomains) {
    $hierarchy = [];
    $sortedDomains = $allDomains;
    // Ordenar por longitud para procesar raíces primero
    usort($sortedDomains, function($a, $b) {
        $partsA = substr_count($a, '.');
        $partsB = substr_count($b, '.');
        if ($partsA == $partsB) return strlen($a) - strlen($b);
        return $partsA - $partsB;
    });

    foreach ($sortedDomains as $domain) {
        $foundParent = false;
        foreach (array_keys($hierarchy) as $root) {
            if ($domain === $root) continue;
            // Comprobar si el dominio termina en .root
            if (strpos($domain, '.' . $root) !== false && substr($domain, -strlen('.' . $root)) === '.' . $root) {
                $hierarchy[$root]['subs'][] = $domain;
                $foundParent = true;
                break;
            }
        }
        if (!$foundParent) {
            if (!isset($hierarchy[$domain])) {
                $hierarchy[$domain] = ['subs' => []];
            }
        }
    }
    ksort($hierarchy);
    return $hierarchy;
}

// ---------------------------------------------------------
// Manejo de Acciones POST
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $domain_to_redirect = $_GET['domain'] ?? '';
    $msg = '';
    $msg_type = 'error';

    if ($action === 'add_zone') {
        $newZone = strtolower(trim($_POST['new_domain']));
        $targetIp = trim($_POST['target_ip']);
        
        $res = dnsApiRequest('/api-dns/add', 'POST', ['domain' => $newZone, 'ip' => $targetIp]);
        if ($res['code'] === 200) {
            $msg = "Zona '$newZone' añadida exitosamente.";
            $msg_type = 'success';
            $domain_to_redirect = $newZone;
        } else {
            $errData = @json_decode($res['response'], true);
            $detail = $errData['message'] ?? $res['error'] ?? "Cód {$res['code']}";
            
            // Depuración de token (solo para diagnóstico)
            $tk = defined('DNS_TOKEN') ? DNS_TOKEN : 'NO DEFINIDO';
            $debugTk = " [Len: " . strlen($tk) . ", Star: " . substr($tk, 0, 4) . "...]";
            
            if (empty($errData['message']) && !empty($res['response'])) {
                $detail .= " | " . substr(strip_tags($res['response']), 0, 150);
            }
            $msg = "Error al crear la zona: " . $detail . $debugTk;
        }
    } 
    elseif ($action === 'add_record') {
        $res = dnsApiRequest('/api-dns/record/add', 'POST', [
            'domain' => trim($_POST['domain']),
            'name' => trim($_POST['name']),
            'type' => trim($_POST['type']),
            'content' => trim($_POST['content']),
            'ttl' => (int)trim($_POST['ttl']),
            'priority' => !empty($_POST['priority']) ? (int)trim($_POST['priority']) : null
        ]);
        
        if ($res['code'] === 200) {
            $msg = "Registro " . $_POST['type'] . " insertado correctamente.";
            $msg_type = 'success';
        } else {
            $errData = @json_decode($res['response'], true);
            $msg = "No se pudo añadir el registro: " . ($errData['message'] ?? $res['error'] ?? "Cód {$res['code']}");
        }
    } 
    elseif ($action === 'delete_record') {
        $res = dnsApiRequest('/api-dns/record/del', 'POST', [
            'id' => (int)$_POST['record_id']
        ]);
        
        if ($res['code'] === 200) {
            $msg = "Registro borrado correctamente.";
            $msg_type = 'success';
        } else {
            $errData = @json_decode($res['response'], true);
            $detail = $errData['message'] ?? $res['error'] ?? "Cód {$res['code']}";
            if (empty($errData['message']) && !empty($res['response'])) {
                $detail .= " | " . substr(strip_tags($res['response']), 0, 150);
            }
            $msg = "Error al borrar el registro: " . $detail;
        }
    }
    
    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_type'] = $msg_type;
    
    header("Location: " . $_SERVER['PHP_SELF'] . ($domain_to_redirect ? "?domain=" . urlencode($domain_to_redirect) : ''));
    exit;
}

// ---------------------------------------------------------
// Modo Lectura GET
// ---------------------------------------------------------
$msg = $_SESSION['flash_msg'] ?? '';
$msg_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$activeDomain = $_GET['domain'] ?? '';
$records = [];
$apiError = '';

// Obtener sitios locales
$allLocalSitesData = [];
try {
    $stmt = $pdo->query("SELECT * FROM sys_sites ORDER BY domain ASC");
    $allLocalSitesData = $stmt->fetchAll();
    $localSites = array_column($allLocalSitesData, 'domain');
} catch (Exception $e) {
    $localSites = [];
}

// Obtener todas las zonas gestionadas por la API DNS
$apiZones = [];
$resZones = dnsApiRequest('/api-dns/zones', 'GET');
if ($resZones['code'] === 200) {
    $dataZones = json_decode($resZones['response'], true);
    $apiZonesRaw = $dataZones['zones'] ?? $dataZones['data'] ?? [];
    if (!empty($apiZonesRaw) && isset($apiZonesRaw[0]['domain'])) {
        $apiZones = array_column($apiZonesRaw, 'domain');
    } else {
        $apiZones = $apiZonesRaw;
    }
}

// Combinar y agrupar
$allDomains = array_unique(array_merge($localSites, $apiZones));
$hierarchy = getDomainHierarchy($allDomains);

// Si hay un dominio activo, traer sus registros de la API
if ($activeDomain) {
    $res = dnsApiRequest('/api-dns/records/' . urlencode($activeDomain), 'GET');
    if ($res['code'] === 200) {
        $data = json_decode($res['response'], true);
        if ($data['success']) {
            $records = $data['data']['records'] ?? [];
        } else {
            $apiError = $data['message'] ?? "Error desconocido en API";
        }
    } elseif ($res['code'] === 404) {
        $apiError = "El dominio no existe en el servidor DNS o no hay registros.";
    } else {
        $apiError = "No se pudo conectar al servidor DNS (Code: {$res['code']})";
    }
}

// Manejo de exportación
$exportContent = '';
if ($activeDomain && isset($_GET['export'])) {
    $resExport = dnsApiRequest("/api-dns/zone/" . urlencode($activeDomain) . "/export", 'GET');
    if ($resExport['code'] === 200) {
        $exportContent = $resExport['response'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Hosting Admin | Gestión DNS y Dominios</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --bg: #0f172a;
            --card-bg: #1e293b;
            --text: #f8fafc;
            --text-dim: #94a3b8;
            --border: #334155;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --info: #0ea5e9;
        }
        body { 
            font-family: 'Outfit', sans-serif; 
            background: var(--bg); 
            color: var(--text);
            margin: 0;
            padding: 40px 20px;
            line-height: 1.5;
        }
        .container { 
            max-width: 1200px; 
            margin: auto; 
        }
        .main-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 24px;
            margin-top: 32px;
        }
        .sidebar {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            height: fit-content;
            position: sticky;
            top: 20px;
        }
        .content {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .panel {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; font-size: 0.9rem; border: 1px solid transparent; }
        .alert-success { background: rgba(16, 185, 129, 0.1); color: var(--success); border-color: rgba(16, 185, 129, 0.2); }
        .alert-error { background: rgba(239, 68, 68, 0.1); color: var(--error); border-color: rgba(239, 68, 68, 0.2); }
        
        .btn { 
            padding: 10px 18px; 
            border-radius: 8px; 
            cursor: pointer; 
            font-weight: 600; 
            font-family: inherit;
            border: none;
            font-size: 0.85rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            gap: 8px;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .btn-outline:hover { background: var(--border); }
        .btn-danger { color: var(--error); background: transparent; border: 1px solid rgba(239, 68, 68, 0.2); }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.1); border-color: var(--error); }
        
        .domain-card {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            transition: all 0.2s;
            cursor: pointer;
            display: block;
            text-decoration: none;
            color: inherit;
        }
        .domain-card:hover { border-color: var(--primary); background: rgba(79, 70, 229, 0.05); }
        .domain-card.active { border-color: var(--primary); border-left: 4px solid var(--primary); background: rgba(79, 70, 229, 0.1); }
        .domain-card h4 { margin: 0; font-size: 1rem; color: var(--text); }
        .domain-card .meta { font-size: 0.8rem; color: var(--text-dim); margin-top: 4px; display: flex; gap: 8px; }

        .form-row { display: flex; gap: 15px; margin-bottom: 15px; align-items: flex-end; }
        .form-group { flex: 1; }
        label { display: block; margin-bottom: 6px; color: var(--text-dim); font-size: 0.85rem; }
        input, select { 
            width: 100%; box-sizing: border-box;
            padding: 10px 14px; 
            background: var(--bg); 
            border: 1px solid var(--border); 
            border-radius: 8px; 
            color: var(--text); 
            font-family: inherit;
        }
        input:focus, select:focus { outline: none; border-color: var(--primary); }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; border-bottom: 2px solid var(--border); color: var(--text-dim); font-size: 0.75rem; text-transform: uppercase; }
        td { padding: 16px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:hover td { background: rgba(255,255,255,0.02); }
        
        .badge { font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; font-weight: 600; text-transform: uppercase; }
        .badge-api { background: rgba(14, 165, 233, 0.1); color: var(--info); }
        .badge-local { background: rgba(16, 185, 129, 0.1); color: var(--success); }

        .type-badge { font-family: monospace; font-size: 0.75rem; font-weight: 700; padding: 4px 8px; border-radius: 4px; }
        .type-A { color: #3b82f6; background: rgba(59, 130, 246, 0.1); }
        .type-CNAME { color: #8b5cf6; background: rgba(139, 92, 246, 0.1); }
        .type-MX { color: #f59e0b; background: rgba(245, 158, 11, 0.1); }
        .type-TXT { color: #10b981; background: rgba(16, 185, 129, 0.1); }

        .sites-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; margin-top: 12px; }
        .site-item { 
            background: var(--bg); border: 1px solid var(--border); padding: 16px; border-radius: 12px;
            display: flex; flex-direction: column; gap: 12px;
        }
        .site-item .domain { font-weight: 600; font-size: 1rem; word-break: break-all; }
        .site-item .actions { display: flex; gap: 8px; margin-top: auto; }

        .empty-state { text-align: center; padding: 80px 40px; color: var(--text-dim); }
        .empty-state svg { width: 80px; height: 80px; margin-bottom: 20px; opacity: 0.2; }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <?php if ($msg): ?>
            <div class='alert alert-<?php echo $msg_type; ?>'><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <div class="main-layout">
            <!-- Sidebar: Lista de Dominios Raíz -->
            <div class="sidebar">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                    <h3 style="margin:0; font-size: 1.2rem;">Dominios Raíz</h3>
                    <button onclick="document.getElementById('modal-add').style.display='flex'" class="btn btn-primary" style="padding: 6px 12px; border-radius: 8px; font-size: 1.1rem;">+</button>
                </div>
                
                <div style="max-height: calc(100vh - 250px); overflow-y: auto; padding-right: 5px;">
                    <?php if (empty($hierarchy)): ?>
                        <div class="empty-state" style="padding: 20px;">
                            <p style="font-size: 0.9rem;">No hay dominios registrados.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($hierarchy as $domain => $data): ?>
                            <a href="?domain=<?php echo urlencode($domain); ?>" class="domain-card <?php echo ($activeDomain === $domain) ? 'active' : ''; ?>">
                                <h4><?php echo htmlspecialchars($domain); ?></h4>
                                <div class="meta">
                                    <span class="badge <?php echo in_array($domain, $localSites) ? 'badge-local' : 'badge-api'; ?>">
                                        <?php echo in_array($domain, $localSites) ? 'Local' : 'DNS'; ?>
                                    </span>
                                    <?php if (!empty($data['subs'])): ?>
                                        <span>• <?php echo count($data['subs']); ?> subs</span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Content Area -->
            <div class="content">
                <?php if (!$activeDomain): ?>
                    <div class="panel empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                        <h2 style="color: var(--text);">Administrador de Hosting</h2>
                        <p>Selecciona un dominio de la barra lateral para ver su infraestructura, servicios web y gestión DNS unificada.</p>
                        <div style="margin-top: 24px;">
                            <button onclick="document.getElementById('modal-add').style.display='flex'" class="btn btn-primary">Añadir Nueva Zona DNS</button>
                        </div>
                    </div>
                <?php else: ?>
                    
                    <!-- Información y Sitios Web -->
                    <div class="panel">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <h1 style="margin:0; font-size: 1.8rem;"><?php echo htmlspecialchars($activeDomain); ?></h1>
                                    <span class="badge <?php echo in_array($activeDomain, $localSites) ? 'badge-local' : 'badge-api'; ?>" style="padding: 4px 10px; font-size: 0.8rem;">
                                        <?php echo in_array($activeDomain, $localSites) ? 'Hosting Local' : 'Zona DNS'; ?>
                                    </span>
                                </div>
                                <p style="color: var(--text-dim); margin: 8px 0 0 0;">Gestión jerárquica de subdominios y servicios asociados.</p>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <a href="?domain=<?php echo urlencode($activeDomain); ?>&export=1" class="btn btn-outline">
                                    <span>💾</span> BIND9
                                </a>
                            </div>
                        </div>

                        <?php if ($exportContent): ?>
                            <div style="background: #0b0f19; color: #10b981; padding: 20px; border-radius: 12px; font-family: 'Fira Code', monospace; font-size: 0.85rem; overflow-x: auto; margin-bottom: 25px; white-space: pre; border: 1px solid var(--border); box-shadow: inset 0 2px 10px rgba(0,0,0,0.5);">
                                <?php echo htmlspecialchars($exportContent); ?>
                            </div>
                        <?php endif; ?>

                        <h3 style="font-size: 1.1rem; margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
                            <span>🌐</span> Sitios Web y Subdominios Vinculados
                        </h3>
                        <div class="sites-grid">
                            <?php 
                            $relatedSites = array_unique(array_merge([$activeDomain], $hierarchy[$activeDomain]['subs'] ?? []));
                            sort($relatedSites);
                            foreach ($relatedSites as $site): 
                                $siteData = null;
                                foreach ($allLocalSitesData as $ls) { if ($ls['domain'] === $site) { $siteData = $ls; break; } }
                            ?>
                                <div class="site-item">
                                    <div class="domain"><?php echo htmlspecialchars($site); ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-dim); display: flex; align-items: center; gap: 6px;">
                                        <?php if ($siteData): ?>
                                            <span style="color: var(--success);">●</span> Alojado Localmente
                                        <?php else: ?>
                                            <span style="color: var(--info);">○</span> Registro DNS Externo
                                        <?php endif; ?>
                                    </div>
                                    <div class="actions">
                                        <?php if ($siteData): ?>
                                            <a href="filemanager.php?site_id=<?php echo $siteData['id']; ?>" class="btn btn-outline" style="flex: 1; justify-content: center; padding: 6px;" title="Gestor de Archivos">📂 Archivos</a>
                                            <a href="databases.php?site_id=<?php echo $siteData['id']; ?>" class="btn btn-outline" style="flex: 1; justify-content: center; padding: 6px;" title="Bases de Datos">🗄️ BBDD</a>
                                        <?php else: ?>
                                            <a href="index.php?new=1&domain=<?php echo urlencode($site); ?>" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 6px; font-size: 0.75rem;">+ Crear Hosting</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Gestión DNS -->
                    <div class="panel">
                        <section style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                            <h3 style="margin:0; font-size: 1.1rem; display: flex; align-items: center; gap: 10px;">
                                <span>⚡</span> Registros DNS de la Zona
                            </h3>
                            <button onclick="document.getElementById('row-add-record').style.display = (document.getElementById('row-add-record').style.display === 'none' ? 'block' : 'none')" class="btn btn-primary btn-sm">
                                <span>+</span> Añadir Registro
                            </button>
                        </section>

                        <div id="row-add-record" style="display: none; background: var(--bg); padding: 24px; border-radius: 12px; margin-bottom: 24px; border: 1px solid var(--primary); box-shadow: 0 0 15px rgba(79, 70, 229, 0.1);">
                            <form method="POST">
                                <input type="hidden" name="action" value="add_record">
                                <input type="hidden" name="domain" value="<?php echo htmlspecialchars($activeDomain); ?>">
                                
                                <div class="form-row">
                                    <div class="form-group" style="flex: 1;">
                                        <label>Tipo de Registro</label>
                                        <select name="type" id="record_type" onchange="togglePriority()" required>
                                            <option value="A">A (Dirección IPv4)</option>
                                            <option value="AAAA">AAAA (Dirección IPv6)</option>
                                            <option value="CNAME">CNAME (Alias)</option>
                                            <option value="MX">MX (Correo)</option>
                                            <option value="TXT">TXT (Texto)</option>
                                            <option value="SRV">SRV (Servicio)</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex: 2;">
                                        <label>Nombre del Host (ej: @, www, mail)</label>
                                        <input type="text" name="name" placeholder="@" value="@" required>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group" style="flex: 3;">
                                        <label>Valor / Contenido</label>
                                        <input type="text" name="content" required placeholder="1.2.3.4 o hostname.com">
                                    </div>
                                    <div class="form-group" style="flex: 0.8; display: none;" id="priority_group">
                                        <label>Prioridad</label>
                                        <input type="number" name="priority" placeholder="10">
                                    </div>
                                    <div class="form-group" style="flex: 0.8;">
                                        <label>TTL</label>
                                        <input type="number" name="ttl" value="3600" required title="Time To Live en segundos">
                                    </div>
                                </div>
                                <div style="display: flex; gap: 12px; margin-top: 15px; justify-content: flex-end;">
                                    <button type="button" onclick="document.getElementById('row-add-record').style.display='none'" class="btn btn-outline" style="min-width: 100px;">Cancelar</button>
                                    <button type="submit" class="btn btn-primary" style="min-width: 150px;">Guardar Registro</button>
                                </div>
                            </form>
                        </div>

                        <div style="overflow-x: auto;">
                            <?php if ($apiError): ?>
                                <div class="alert alert-error" style="margin: 0;"><?php echo htmlspecialchars($apiError); ?></div>
                            <?php elseif (empty($records)): ?>
                                <div class="empty-state" style="padding: 40px;">
                                    <p>No se encontraron registros activos en esta zona DNS.</p>
                                </div>
                            <?php else: ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Tipo</th>
                                            <th>Hostname</th>
                                            <th>Valor / Destino</th>
                                            <th>TTL</th>
                                            <th style="width: 50px; text-align: right;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($records as $r): ?>
                                            <tr>
                                                <td><span class="type-badge type-<?php echo htmlspecialchars($r['type']); ?>"><?php echo htmlspecialchars($r['type']); ?></span></td>
                                                <td style="font-weight: 500; font-family: monospace; color: var(--text);"><?php echo htmlspecialchars($r['name']); ?></td>
                                                <td style="color: var(--text-dim); font-family: monospace; font-size: 0.9rem; max-width: 350px; overflow-wrap: break-word;"><?php echo htmlspecialchars($r['content']); ?></td>
                                                <td style="color: var(--text-dim); font-size: 0.8rem;"><?php echo $r['ttl']; ?>s</td>
                                                <td style="text-align: right;">
                                                    <form method="POST" onsubmit="return confirm('¿Eliminar de forma permanente este registro DNS?');">
                                                        <input type="hidden" name="action" value="delete_record">
                                                        <input type="hidden" name="record_id" value="<?php echo $r['id']; ?>">
                                                        <button type="submit" class="btn btn-danger" style="padding: 6px; border-radius: 6px;" title="Eliminar Registro">
                                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal: Nueva Zona -->
    <div id="modal-add" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
        <div class="panel" style="width: 100%; max-width: 500px; border-color: var(--primary);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <h3 style="margin-top:0; font-size: 1.3rem;">Añadir Nueva Zona DNS</h3>
                <button onclick="document.getElementById('modal-add').style.display='none'" style="background:none; border:none; color: var(--text-dim); cursor:pointer; font-size: 1.5rem;">&times;</button>
            </div>
            <p style="color: var(--text-dim); font-size: 0.9rem; margin-bottom: 24px;">Esto creará una nueva zona autoritativa en el servidor DNS para el dominio indicado.</p>
            <form method="POST">
                <input type="hidden" name="action" value="add_zone">
                <div class="form-group" style="margin-bottom: 18px;">
                    <label>Nombre de Dominio (FQDN)</label>
                    <input type="text" name="new_domain" placeholder="ejemplo.com" required autofocus style="font-size: 1.1rem; padding: 12px;">
                </div>
                <div class="form-group" style="margin-bottom: 24px;">
                    <label>IP de Destino (Opcional)</label>
                    <input type="text" name="target_ip" placeholder="Se usará la IP de este servidor por defecto" style="padding: 12px;">
                </div>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="document.getElementById('modal-add').style.display='none'" class="btn btn-outline" style="min-width: 100px;">Cancelar</button>
                    <button type="submit" class="btn btn-primary" style="min-width: 150px;">Crear Nueva Zona</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function togglePriority() {
            const type = document.getElementById('record_type').value;
            const group = document.getElementById('priority_group');
            group.style.display = (type === 'MX' || type === 'SRV') ? 'block' : 'none';
        }

        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            const modal = document.getElementById('modal-add');
            if (event.target == modal) modal.style.display = "none";
        }
        
        // Atajos de teclado
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.getElementById('modal-add').style.display = 'none';
                document.getElementById('row-add-record').style.display = 'none';
            }
        });
    </script>
</body>
</html>
