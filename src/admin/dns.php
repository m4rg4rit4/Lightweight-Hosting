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
    // Tomamos el primer servidor configurado como Master API
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

// ---------------------------------------------------------
// Manejo de Acciones POST
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $domain_to_redirect = $_GET['domain'] ?? '';
    $msg = '';
    $msg_type = 'error';

    if ($action === 'add_zone') {
        $newZone = trim($_POST['new_domain']);
        $targetIp = trim($_POST['target_ip']);
        
        $res = dnsApiRequest('/api-dns/add', 'POST', ['domain' => $newZone, 'ip' => $targetIp]);
        if ($res['code'] === 200) {
            $msg = "Zona '$newZone' añadida exitosamente (Pendiente de propagar en bind9).";
            $msg_type = 'success';
            $domain_to_redirect = $newZone;
        } else {
            $errData = @json_decode($res['response'], true);
            $msg = "Error al crear la zona: " . ($errData['message'] ?? $res['error'] ?? "Cód {$res['code']}");
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
            $msg = "Fallo borrando registro: " . ($errData['message'] ?? $res['error'] ?? "Cód {$res['code']}");
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

$localSites = [];
try {
    $stmt = $pdo->query("SELECT domain FROM sys_sites ORDER BY domain ASC");
    $localSites = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Obtener todas las zonas gestionadas por la API DNS
$apiZones = [];
$resZones = dnsApiRequest('/api-dns/zones', 'GET');
if ($resZones['code'] === 200) {
    $dataZones = json_decode($resZones['response'], true);
    // Asumimos que la API devuelve una lista de dominios o un objeto con ellos
    $apiZones = $dataZones['zones'] ?? $dataZones['data'] ?? [];
    // Si es un array de objetos, extraer el nombre del dominio
    if (!empty($apiZones) && isset($apiZones[0]['domain'])) {
        $apiZones = array_column($apiZones, 'domain');
    }
}

// Combinar y eliminar duplicados
$allDomains = array_unique(array_merge($localSites, $apiZones));
sort($allDomains);

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

// Manejo de exportación (BIND9)
$exportContent = '';
if ($activeDomain && isset($_GET['export'])) {
    $resExport = dnsApiRequest("/api-dns/zone/" . urlencode($activeDomain) . "/export", 'GET');
    if ($resExport['code'] === 200) {
        $exportContent = $resExport['response'];
    } else {
        $apiError = "No se pudo exportar la zona.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Hosting Admin | Gestión DNS</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        /* Misma CSS abstracta que en index.php, omito bloques redundantes con fines de estilización rápida o podrías extraerla. */
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
            max-width: 1000px; 
            background: var(--card-bg); 
            padding: 32px; 
            border-radius: 16px; 
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3); 
            margin: auto; 
            border: 1px solid var(--border);
        }
        nav { 
            margin-bottom: 32px; 
            padding-bottom: 16px; 
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 20px;
        }
        nav strong { font-size: 1.25rem; color: var(--primary); }
        nav a { text-decoration: none; color: var(--text-dim); transition: color 0.2s; font-weight: 500; }
        nav a:hover, nav a.active { color: var(--text); }
        h1, h2, h3 { color: var(--text); font-weight: 600; margin-top: 0; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; font-size: 0.9rem; border: 1px solid transparent; }
        .alert-success { background: rgba(16, 185, 129, 0.1); color: var(--success); border-color: rgba(16, 185, 129, 0.2); }
        .alert-error { background: rgba(239, 68, 68, 0.1); color: var(--error); border-color: rgba(239, 68, 68, 0.2); }
        
        .btn { 
            padding: 8px 16px; 
            border-radius: 6px; 
            cursor: pointer; 
            font-weight: 600; 
            font-family: inherit;
            border: none;
            font-size: 0.85rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .btn-outline:hover { background: var(--border); }
        .btn-danger { color: var(--error); background: transparent; border: 1px solid rgba(239, 68, 68, 0.3); }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.1); border-color: var(--error); }
        
        .form-row { display: flex; gap: 15px; margin-bottom: 15px; align-items: flex-end; }
        .form-group { flex: 1; }
        label { display: block; margin-bottom: 6px; color: var(--text-dim); font-size: 0.85rem; }
        input[type="text"], input[type="number"], select { 
            width: 100%; box-sizing: border-box;
            padding: 10px 14px; 
            background: var(--bg); 
            border: 1px solid var(--border); 
            border-radius: 6px; 
            color: var(--text); 
            font-family: inherit;
        }
        input:focus, select:focus { outline: none; border-color: var(--primary); }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { text-align: left; padding: 12px; border-bottom: 2px solid var(--border); color: var(--text-dim); font-size: 0.75rem; text-transform: uppercase; }
        td { padding: 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255,255,255,0.02); }
        
        .panel {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .domain-selector {
            display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;
        }
        .domain-tag {
            padding: 6px 12px;
            border-radius: 6px;
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text-dim);
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .domain-tag:hover { background: var(--border); color: var(--text); }
        .domain-tag.active { background: var(--primary); color: white; border-color: var(--primary); }

        .type-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 3px 6px;
            border-radius: 4px;
            background: rgba(255,255,255,0.1);
        }
        .type-A { color: #3b82f6; background: rgba(59, 130, 246, 0.1); }
        .type-CNAME { color: #8b5cf6; background: rgba(139, 92, 246, 0.1); }
        .type-MX { color: #f59e0b; background: rgba(245, 158, 11, 0.1); }
        .type-TXT { color: #10b981; background: rgba(16, 185, 129, 0.1); }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <?php if ($msg): ?>
            <div class='alert alert-<?php echo $msg_type; ?>'><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <!-- Sección de Selección de Dominio / Alta de Zona -->
        <div class="panel">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
                <h3>Selector de Zonas DNS</h3>
                <button onclick="document.getElementById('add-zone-form').style.display='block'" class="btn btn-outline">+ Nueva Zona Manual</button>
            </div>
            
            <form id="add-zone-form" method="POST" style="display: none; background: var(--bg); padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid var(--border);">
                <input type="hidden" name="action" value="add_zone">
                <div class="form-row" style="margin-bottom: 0;">
                    <div class="form-group" style="flex: 2;">
                        <label>Dominio</label>
                        <input type="text" name="new_domain" placeholder="dominio.com" required>
                    </div>
                    <div class="form-group" style="flex: 2;">
                        <label>Target IP (Opcional, predeterminada tuya)</label>
                        <input type="text" name="target_ip" placeholder="1.2.3.4">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <button type="submit" class="btn btn-primary" style="width: 100%; height: 42px;">Crear</button>
                    </div>
                </div>
            </form>

            <div class="domain-selector">
                <?php foreach ($allDomains as $s): ?>
                    <a href="?domain=<?php echo urlencode($s); ?>" class="domain-tag <?php echo ($activeDomain === $s) ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($s); ?>
                        <?php if (!in_array($s, $localSites)): ?> <small style="opacity: 0.6; font-size: 0.6rem;">(API)</small><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <?php if (!$activeDomain): ?>
                <p style="color: var(--text-dim); text-align: center; margin-top: 30px;">Selecciona un dominio arriba para visualizar o administrar sus registros DNS.</p>
            <?php endif; ?>
        </div>

        <?php if ($activeDomain): ?>
        
        <!-- Formulario Nuevo Registro y Exportación -->
        <div class="panel" style="border-left: 3px solid var(--primary);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0;">Gestionar <?php echo htmlspecialchars($activeDomain); ?></h3>
                <div>
                    <a href="?domain=<?php echo urlencode($activeDomain); ?>&export=1" class="btn btn-outline" style="text-decoration: none;">Ver Exportación BIND9</a>
                </div>
            </div>

            <?php if ($exportContent): ?>
                <div style="background: #000; color: #0f0; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 0.8rem; overflow-x: auto; margin-bottom: 25px; white-space: pre;">
                    <?php echo htmlspecialchars($exportContent); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="add_record">
                <input type="hidden" name="domain" value="<?php echo htmlspecialchars($activeDomain); ?>">
                
                <div class="form-row">
                    <div class="form-group" style="flex: 1.5;">
                        <label>Tipo</label>
                        <select name="type" id="record_type" onchange="togglePriority()" required>
                            <option value="A">A (IPv4)</option>
                            <option value="AAAA">AAAA (IPv6)</option>
                            <option value="CNAME">CNAME</option>
                            <option value="MX">MX (Correo)</option>
                            <option value="TXT">TXT (Texto / SPF)</option>
                            <option value="SRV">SRV</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 2;">
                        <label>Nombre</label>
                        <input type="text" name="name" placeholder="@, www, mail..." value="@" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group" style="flex: 4;">
                        <label>Contenido</label>
                        <input type="text" name="content" required placeholder="IP, dominio destino, o texto (ej. 1.1.1.1 o v=spf1...)">
                    </div>
                    <div class="form-group" style="flex: 1;" id="priority_group" style="display: none;">
                        <label>Prioridad</label>
                        <input type="number" name="priority" placeholder="10">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>TTL</label>
                        <input type="number" name="ttl" value="3600" required>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary" style="height: 42px;">Añadir</button>
                    </div>
                </div>
            </form>
            <script>
                function togglePriority() {
                    const type = document.getElementById('record_type').value;
                    const group = document.getElementById('priority_group');
                    if (type === 'MX' || type === 'SRV') {
                        group.style.display = 'block';
                    } else {
                        group.style.display = 'none';
                    }
                }
                togglePriority();
            </script>
        </div>

        <!-- Listado de Registros -->
        <?php if ($apiError): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($apiError); ?></div>
        <?php else: ?>
            <div style="background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; overflow: hidden;">
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Nombre</th>
                            <th>Contenido</th>
                            <th>TTL</th>
                            <th>Prioridad</th>
                            <th style="width: 80px; text-align: right;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-dim); padding: 30px;">Sin registros para mostrar.</td></tr>
                        <?php else: ?>
                            <?php foreach ($records as $r): ?>
                                <tr>
                                    <td><span class="type-badge type-<?php echo htmlspecialchars($r['type']); ?>"><?php echo htmlspecialchars($r['type']); ?></span></td>
                                    <td style="font-weight: 500; font-family: monospace; color: #cbd5e1;"><?php echo htmlspecialchars($r['name'] === '@' ? $activeDomain : $r['name'] . '.' . $activeDomain); ?></td>
                                    <td style="font-family: monospace; color: var(--text-dim); max-width: 300px; overflow-wrap: break-word;">
                                        <?php echo htmlspecialchars($r['content']); ?>
                                    </td>
                                    <td style="color: var(--text-dim);"><?php echo $r['ttl']; ?></td>
                                    <td style="color: var(--text-dim);"><?php echo $r['priority'] !== null ? $r['priority'] : '-'; ?></td>
                                    <td style="text-align: right;">
                                        <form method="POST" onsubmit="return confirm('¿Borrar registro <?php echo htmlspecialchars($r['type'] . ' ' . $r['name']); ?>?');">
                                            <input type="hidden" name="action" value="delete_record">
                                            <input type="hidden" name="record_id" value="<?php echo $r['id']; ?>">
                                            <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size: 0.75rem;">Borrar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <?php endif; ?>
    </div>
</body>
</html>
