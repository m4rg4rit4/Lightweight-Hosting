<?php
session_start();
require 'config.php';
require_once 'dns_utils.php';
$pdo = getPDO();

// Manejo de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = '';
    $msg_type = 'error';
    // 1. Crear nuevo sitio
    if (isset($_POST['domain'])) {
        $domain = strtolower(trim($_POST['domain']));
        $php = isset($_POST['php']) ? 1 : 0;
        
        if (!preg_match('/^[a-z0-9.-]+$/', $domain) || empty($domain) || strlen($domain) > 255) {
            $_SESSION['flash_msg'] = "Error: El nombre de dominio contiene caracteres no válidos.";
            $_SESSION['flash_type'] = "error";
            header("Location: " . $_SERVER['PHP_SELF'] . '?new=1');
            exit;
        }
        
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM sys_sites WHERE domain = ?");
        $stmt_check->execute([$domain]);
        if ($stmt_check->fetchColumn() > 0) {
            $_SESSION['flash_msg'] = "Error: El dominio '$domain' ya está dado de alta en el sistema.";
            $_SESSION['flash_type'] = "error";
            header("Location: " . $_SERVER['PHP_SELF'] . '?new=1');
            exit;
        }
        
        if ($domain) {
            try {
                $stmt = $pdo->prepare("INSERT INTO sys_sites (domain, document_root, php_enabled) VALUES (?, ?, ?)");
                $doc_root = "/var/www/vhosts/" . $domain;
                $stmt->execute([$domain, $doc_root, $php]);
                
                $payload = json_encode(['domain' => $domain, 'php_enabled' => $php, 'path' => $doc_root]);
                $stmt = $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('SITE_CREATE', ?)");
                $stmt->execute([$payload]);
                
                $msg = "Sitio '$domain' añadido a la cola de procesamiento.";
                $msg_type = 'success';
            } catch (Exception $e) {
                $msg = "Error: " . $e->getMessage();
                $msg_type = 'error';
            }
        }
    } 
    // 2. Acciones sobre sitios existentes
    elseif (isset($_POST['action']) && isset($_POST['site_id'])) {
        $siteId = (int)$_POST['site_id'];
        $action = $_POST['action'];
        
        $site = $pdo->prepare("SELECT * FROM sys_sites WHERE id = ?");
        $site->execute([$siteId]);
        $site = $site->fetch();

        if ($site) {
            $taskType = '';
            $payload = ['domain' => $site['domain'], 'id' => $siteId];

            switch ($action) {
                case 'toggle_php':
                    $taskType = 'SITE_TOGGLE_PHP';
                    $payload['new_value'] = $site['php_enabled'] ? 0 : 1;
                    break;
                case 'toggle_status':
                    if ($siteId == 1 && $site['status'] === 'active') {
                        $msg = "El sitio principal no puede ser desactivado.";
                        $msg_type = 'error';
                    } else {
                        $taskType = 'SITE_TOGGLE_STATUS';
                        $payload['new_status'] = ($site['status'] === 'active') ? 'inactive' : 'active';
                    }
                    break;
                case 'toggle_ssl':
                    $taskType = 'SSL_LETSENCRYPT';
                    break;
                case 'toggle_ssl_wildcard':
                    $taskType = 'SSL_ISSUE_WILDCARD';
                    break;
                case 'delete':
                    if ($siteId == 1) {
                        $msg = "El sitio principal no puede ser eliminado.";
                        $msg_type = 'error';
                    } else {
                        $taskType = 'SITE_DELETE';
                    }
                    break;
            }

            if ($taskType) {
                $stmt = $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES (?, ?)");
                $stmt->execute([$taskType, json_encode($payload)]);
                $msg = "Tarea de " . strtolower($action) . " para '" . $site['domain'] . "' encolada.";
                $msg_type = 'success';
            }
        }
    }

    if ($msg) {
        $_SESSION['flash_msg'] = $msg;
        $_SESSION['flash_type'] = $msg_type;
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . (isset($_POST['domain']) ? '?new=1' : ''));
    exit;
}

// ---------------------------------------------------------
// Modo Lectura GET
// ---------------------------------------------------------
$msg = $_SESSION['flash_msg'] ?? '';
$msg_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// Obtener sitios ya registrados
$sites = $pdo->query("
    SELECT s.*, 
    (SELECT COUNT(*) FROM sys_tasks t 
     WHERE t.status IN ('pending', 'running') 
     AND (JSON_EXTRACT(t.payload, '$.domain') = s.domain OR JSON_EXTRACT(t.payload, '$.domain') = CONCAT('\"', s.domain, '\"'))) as is_processing
    FROM sys_sites s 
    ORDER BY s.id ASC
")->fetchAll();
$localDomains = array_column($sites, 'domain');

// Obtener zonas DNS disponibles si están habilitadas
$apiZones = [];
$apiAvailableZones = [];
if (defined('DNS_TOKEN') && !empty(DNS_TOKEN)) {
    $resZones = dnsApiRequest('/api-dns/zones', 'GET');
    if ($resZones['code'] === 200) {
        $dataZones = json_decode($resZones['response'], true);
        $rawZones = $dataZones['zones'] ?? $dataZones['data'] ?? [];
        foreach ($rawZones as $z) {
            $domain = is_array($z) ? ($z['domain'] ?? '') : $z;
            if ($domain) {
                $apiZones[] = $domain;
                if (!in_array($domain, $localDomains)) {
                    $apiAvailableZones[] = $domain;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <!-- El refresco se maneja por AJAX ahora -->
    <title>Hosting Admin | Control Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <?php if ($msg): ?>
            <div class='alert alert-<?php echo $msg_type; ?>'>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>
        
        <div class="section-header">
            <h1>Sitios Configurados</h1>
            <button onclick="toggleNewSiteForm()" class="btn btn-primary" id="toggle-btn">
                <span style="font-size: 1.2rem; margin-right: 8px;">+</span> Nuevo Sitio
            </button>
        </div>

        <div id="new-site-form-container" class="<?php echo (isset($_GET['new']) || isset($_GET['from_dns'])) ? 'show' : ''; ?>">
            <h2 style="margin-top: 0; font-size: 1.2rem; margin-bottom: 20px; color: var(--primary);">Añadir Nuevo Dominio</h2>
            
            <form method="POST">
                <div class="form-group">
                    <label>Dominio (ej: misitio.com)</label>
                    <input type="text" name="domain" id="input_domain" required placeholder="example.com" value="<?php echo htmlspecialchars($_GET['domain'] ?? ''); ?>">
                    
                    <?php if (!empty($apiAvailableZones)): ?>
                        <div style="margin-top: 12px; font-size: 0.85rem; color: var(--text-dim);">
                            O elige de tus zonas DNS:
                            <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px;">
                                <?php foreach ($apiAvailableZones as $az): ?>
                                    <button type="button" class="badge badge-api" style="cursor: pointer; border: none;" onclick="document.getElementById('input_domain').value='<?php echo htmlspecialchars($az); ?>';">
                                        + <?php echo htmlspecialchars($az); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="form-group" style="display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" name="php" id="php_enabled" checked style="width: 18px; height: 18px;">
                    <label for="php_enabled" style="margin-bottom: 0;">Habilitar soporte PHP</label>
                </div>
                
                <div id="dns-warning" style="display: none; background: rgba(245, 158, 11, 0.1); border: 1px solid var(--warning); padding: 12px; border-radius: 8px; margin-bottom: 15px; font-size: 0.9rem;">
                    ⚠️ <span id="dns-warning-msg">Este dominio no gestionado por tus servidores DNS.</span>
                    <br><small style="opacity: 0.8;">Deberás configurar las DNS manualmente o añadir la zona en la sección DNS.</small>
                </div>

                <div style="display: flex; gap: 12px; align-items: center;">
                    <button type="submit" class="btn btn-primary">Crear Sitio</button>
                    <button type="button" onclick="toggleNewSiteForm()" class="btn btn-outline">Cancelar</button>
                </div>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Dominio</th>
                    <th>PHP</th>
                    <th>SSL</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $s): ?>
                <tr>
                    <td style="color: var(--text-dim); font-weight: 600; font-family: monospace;">#<?php echo $s['id']; ?></td>
                    <td style="font-weight: 500;">
                        <?php echo htmlspecialchars($s['domain']); ?>
                        <div style="font-size: 0.75rem; color: var(--text-dim); font-weight: 300;"><?php echo $s['created_at']; ?></div>
                    </td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="site_id" value="<?php echo $s['id']; ?>">
                            <input type="hidden" name="action" value="toggle_php">
                            <button type="submit" class="btn btn-outline btn-sm" <?php echo ($s['is_processing'] > 0) ? 'disabled' : ''; ?>>
                                <?php if ($s['php_enabled'] == 1): ?>
                                    <span style="color: var(--success);">●</span> Activo
                                <?php else: ?>
                                    <span style="color: var(--text-dim);">○</span> Inactivo
                                <?php endif; ?>
                            </button>
                        </form>
                    </td>
                    <td>
                        <?php 
                        $isManaged = false;
                        foreach ($apiZones as $z) {
                            if ($s['domain'] === $z || str_ends_with($s['domain'], '.' . $z)) {
                                $isManaged = true;
                                break;
                            }
                        }
                        ?>
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="site_id" value="<?php echo $s['id']; ?>">
                                <input type="hidden" name="action" value="toggle_ssl">
                                <button type="submit" class="btn btn-outline btn-sm" style="width: 100%; text-align: left;" <?php echo ($s['status'] !== 'active' || $s['is_processing'] > 0) ? 'disabled' : ''; ?>>
                                    <?php if ($s['ssl_enabled'] == 1): ?>
                                        <span style="color: var(--success);">🔒</span> Estándar
                                    <?php else: ?>
                                        <span style="color: var(--text-dim);">🔓</span> Sin SSL
                                    <?php endif; ?>
                                </button>
                            </form>
                            <?php if ($isManaged): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="site_id" value="<?php echo $s['id']; ?>">
                                <input type="hidden" name="action" value="toggle_ssl_wildcard">
                                <button type="submit" class="btn btn-outline btn-sm" style="width: 100%; text-align: left;" <?php echo ($s['status'] !== 'active' || $s['is_processing'] > 0) ? 'disabled' : ''; ?>>
                                    <span style="color: var(--info);">✨</span> Wildcard (*.)
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($s['is_processing'] > 0): ?>
                            <span class="badge badge-running">Procesando...</span>
                        <?php else: ?>
                            <span class="badge badge-<?php echo $s['status']; ?>"><?php echo $s['status']; ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="actions">
                            <?php if ($s['id'] != 1): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="site_id" value="<?php echo $s['id']; ?>">
                                <input type="hidden" name="action" value="toggle_status">
                                <button type="submit" class="btn btn-outline btn-sm" <?php echo ($s['is_processing'] > 0) ? 'disabled' : ''; ?>>
                                    <?php echo ($s['status'] === 'active') ? 'Desactivar' : 'Activar'; ?>
                                </button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Estás seguro de eliminar el dominio <?php echo $s['domain']; ?>?');">
                                <input type="hidden" name="site_id" value="<?php echo $s['id']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-outline btn-sm btn-danger" <?php echo ($s['is_processing'] > 0) ? 'disabled' : ''; ?>>Eliminar</button>
                            </form>
                            <?php else: ?>
                                <button class="btn btn-outline btn-sm" style="opacity: 0.5; cursor: not-allowed;" title="El sitio principal no se puede desactivar" disabled>Desactivar</button>
                                <button class="btn btn-outline btn-sm" style="opacity: 0.5; cursor: not-allowed;" title="El sitio principal no se puede eliminar" disabled>Eliminar</button>
                            <?php endif; ?>
                            <a href="databases.php?site_id=<?php echo $s['id']; ?>" class="btn btn-outline btn-sm" style="color: var(--info);">BBDD</a>
                            <a href="filemanager.php?site_id=<?php echo $s['id']; ?>" class="btn btn-outline btn-sm" style="color: var(--warning);">Archivos</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sites)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; color: var(--text-dim); padding: 40px;">No hay sitios configurados aún.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

    <script>
        let lastPendingCount = -1;

        function toggleNewSiteForm() {
            const container = document.getElementById('new-site-form-container');
            const toggleBtn = document.getElementById('toggle-btn');
            
            container.classList.toggle('show');

            if (container.classList.contains('show')) {
                toggleBtn.innerHTML = '<span style="font-size: 1.2rem; margin-right: 8px;">−</span> Cancelar';
                toggleBtn.classList.remove('btn-primary');
                toggleBtn.classList.add('btn-outline');
                setTimeout(() => {
                    container.querySelector('input[name="domain"]').focus();
                }, 100);
            } else {
                toggleBtn.innerHTML = '<span style="font-size: 1.2rem; margin-right: 8px;">+</span> Nuevo Sitio';
                toggleBtn.classList.remove('btn-outline');
                toggleBtn.classList.add('btn-primary');
            }
        }

        // Script para validación dinámica de DNS
        const apiZonesList = <?php echo json_encode($apiZones); ?>;
        const inputDomain = document.getElementById('input_domain');
        const dnsWarning = document.getElementById('dns-warning');

        inputDomain.addEventListener('input', function() {
            const domain = this.value.toLowerCase().trim();
            if (domain === '') {
                dnsWarning.style.display = 'none';
                return;
            }
            
            // Comprobar si es un subdominio de alguna zona DNS existente
            let isManaged = false;
            for (const zone of apiZonesList) {
                if (domain === zone || domain.endsWith('.' + zone)) {
                    isManaged = true;
                    break;
                }
            }
            
            dnsWarning.style.display = (isManaged || apiZonesList.length === 0) ? 'none' : 'block';
        });

        // Check if the form should be shown initially due to a message
        document.addEventListener('DOMContentLoaded', () => {
            const container = document.getElementById('new-site-form-container');
            const toggleBtn = document.getElementById('toggle-btn');
            if (container.classList.contains('show')) {
                toggleBtn.innerHTML = '<span style="font-size: 1.2rem; margin-right: 8px;">−</span> Cancelar';
            }
            // Trigger input check on load if there's a domain
            if (inputDomain.value) inputDomain.dispatchEvent(new Event('input'));
        });

        async function checkTasks() {
            try {
                const response = await fetch('tasks_status.php?t=' + Date.now());
                if (!response.ok) throw new Error('Network response was not ok');
                
                const data = await response.json();
                const currentCount = data.pending_count;

                const notification = document.getElementById('task-notification');
                if (currentCount > 0) {
                    notification.style.display = 'flex';
                } else {
                    notification.style.display = 'none';
                }

                // Si antes había tareas y ahora no, refrescamos con un GET limpio para mostrar los cambios
                if (lastPendingCount > 0 && currentCount === 0) {
                    window.location.href = window.location.pathname;
                }
                
                lastPendingCount = currentCount;
            } catch (error) {
                console.error('Error checking tasks:', error);
            }
        }

        // Comprobar cada 5 segundos
        setInterval(checkTasks, 5000);
        // Comprobación inicial
        checkTasks();
    </script>
</body>
</html>
