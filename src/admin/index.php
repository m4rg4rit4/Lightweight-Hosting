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
                case 'export':
                    $taskType = 'SITE_EXPORT';
                    $payload['site_id'] = $siteId;
                    $payload['token'] = bin2hex(random_bytes(16));
                    break;
                case 'delete':
                    if ($siteId == 1) {
                        $msg = "El sitio principal no puede ser eliminado.";
                        $msg_type = 'error';
                    } else {
                        $taskType = 'SITE_DELETE';
                    }
                    break;
                case 'update_php_config':
                    $upload_max = strtoupper(trim($_POST['php_upload_max_filesize'] ?? '20M'));
                    $post_max = strtoupper(trim($_POST['php_post_max_size'] ?? '25M'));
                    $max_file_uploads = (int)($_POST['php_max_file_uploads'] ?? 20);
                    $memory_limit = strtoupper(trim($_POST['php_memory_limit'] ?? '128M'));

                    // Estos valores acaban incrustados en el vhost de Apache: solo formato N[KMG]
                    if (!preg_match('/^\d+[KMG]?$/', $upload_max)
                        || !preg_match('/^\d+[KMG]?$/', $post_max)
                        || !preg_match('/^(-1|\d+[KMG]?)$/', $memory_limit)
                        || $max_file_uploads < 1) {
                        $msg = "Valores PHP no válidos: usa un número con sufijo K, M o G (ej: 20M).";
                        $msg_type = 'error';
                    } else {
                        $pdo->prepare("UPDATE sys_sites SET php_upload_max_filesize = ?, php_post_max_size = ?, php_max_file_uploads = ?, php_memory_limit = ? WHERE id = ?")
                            ->execute([$upload_max, $post_max, $max_file_uploads, $memory_limit, $siteId]);

                        $taskType = 'SITE_UPDATE_PHP_SETTINGS';
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
    // 3. Actualización de sistema
    elseif (isset($_POST['action']) && $_POST['action'] === 'system_update') {
        try {
            $stmt = $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('SYSTEM_UPDATE', '{}')");
            $stmt->execute();
            $_SESSION['flash_msg'] = "Tarea de actualización del sistema encolada con éxito.";
            $_SESSION['flash_type'] = "success";
            header("Location: tasks.php");
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_msg'] = "Error al encolar la actualización: " . $e->getMessage();
            $_SESSION['flash_type'] = "error";
            header("Location: index.php");
            exit;
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
     AND JSON_UNQUOTE(JSON_EXTRACT(t.payload, '$.domain')) = s.domain) as is_processing
    FROM sys_sites s 
    ORDER BY s.id ASC
")->fetchAll();
$localDomains = array_column($sites, 'domain');

// Obtener zonas DNS disponibles si están habilitadas
$apiZones = [];
$apiAvailableZones = [];
if (hasDnsServers()) {
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
                        <a href="<?php echo ($s['ssl_enabled'] == 1 ? 'https://' : 'http://') . htmlspecialchars($s['domain']); ?>" target="_blank" class="site-link">
                            <?php echo htmlspecialchars($s['domain']); ?>
                        </a>
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
                        <?php if ($s['php_enabled'] == 1): ?>
                        <div style="margin-top: 4px;">
                            <button type="button" class="btn btn-outline btn-sm" style="font-size: 0.8rem; padding: 2px 8px; color: var(--text-dim);" onclick="openPhpConfigModal(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars($s['domain']); ?>', '<?php echo htmlspecialchars($s['php_upload_max_filesize'] ?? '20M'); ?>', '<?php echo htmlspecialchars($s['php_post_max_size'] ?? '25M'); ?>', <?php echo htmlspecialchars($s['php_max_file_uploads'] ?? 20); ?>, '<?php echo htmlspecialchars($s['php_memory_limit'] ?? '128M'); ?>')" <?php echo ($s['is_processing'] > 0) ? 'disabled' : ''; ?>>
                                ⚙️ Config PHP
                            </button>
                        </div>
                        <?php endif; ?>
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
                            <?php
                            $exportFiles = glob(__DIR__ . '/downloads/export_' . $s['domain'] . '_*.tar.gz');
                            $exportReady = !empty($exportFiles);
                            if ($exportReady):
                                $exportFileName = basename($exportFiles[0]);
                            ?>
                                <a href="download.php?file=<?php echo urlencode($exportFileName); ?>" class="btn btn-outline btn-sm" style="color: var(--success); border-color: rgba(16, 185, 129, 0.3); background: rgba(16, 185, 129, 0.05);" title="Descargar exportación completa y eliminar del servidor">⬇️ Descargar</a>
                            <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="site_id" value="<?php echo $s['id']; ?>">
                                    <input type="hidden" name="action" value="export">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color: #c084fc; border-color: rgba(192, 132, 252, 0.3);" <?php echo ($s['is_processing'] > 0) ? 'disabled' : ''; ?> title="Comprimir sitio y bases de datos">
                                        📦 Exportar
                                    </button>
                                </form>
                            <?php endif; ?>
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

        <!-- PHP Config Modal -->
        <div id="phpConfigModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:var(--bg-card); padding:20px; border-radius:12px; width:400px; max-width:90%; border:1px solid var(--border);">
                <h3 style="margin-top:0;">Configuración PHP: <span id="phpConfigDomain"></span></h3>
                <form method="POST" id="phpConfigForm" onsubmit="return validatePhpConfig();">
                    <input type="hidden" name="action" value="update_php_config">
                    <input type="hidden" name="site_id" id="phpConfigSiteId">
                    
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label>upload_max_filesize (ej: 20M, 2G)</label>
                        <input type="text" name="php_upload_max_filesize" id="php_upload_max_filesize" required style="width:100%;">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label>post_max_size (ej: 25M) - debe ser mayor o igual que upload</label>
                        <input type="text" name="php_post_max_size" id="php_post_max_size" required style="width:100%;">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label>max_file_uploads (ej: 20)</label>
                        <input type="number" name="php_max_file_uploads" id="php_max_file_uploads" required style="width:100%;">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label>memory_limit (ej: 128M, 512M)</label>
                        <input type="text" name="php_memory_limit" id="php_memory_limit" required style="width:100%;">
                    </div>
                    
                    <div style="display:flex; gap:10px; justify-content:flex-end;">
                        <button type="button" class="btn btn-outline" onclick="closePhpConfigModal()">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>

    <script>
        function openPhpConfigModal(siteId, domain, upload, post, maxFiles, memory) {
            document.getElementById('phpConfigSiteId').value = siteId;
            document.getElementById('phpConfigDomain').innerText = domain;
            document.getElementById('php_upload_max_filesize').value = upload;
            document.getElementById('php_post_max_size').value = post;
            document.getElementById('php_max_file_uploads').value = maxFiles;
            document.getElementById('php_memory_limit').value = memory;
            document.getElementById('phpConfigModal').style.display = 'flex';
        }

        function closePhpConfigModal() {
            document.getElementById('phpConfigModal').style.display = 'none';
        }

        function parseSize(sizeStr) {
            sizeStr = sizeStr.trim().toUpperCase();
            let num = parseFloat(sizeStr);
            if (sizeStr.endsWith('G')) return num * 1024;
            if (sizeStr.endsWith('M')) return num;
            if (sizeStr.endsWith('K')) return num / 1024;
            return num / (1024 * 1024); // Assume bytes if no letter
        }

        function validatePhpConfig() {
            const uploadStr = document.getElementById('php_upload_max_filesize').value;
            const postStr = document.getElementById('php_post_max_size').value;
            const memoryStr = document.getElementById('php_memory_limit').value;
            
            const regex = /^\d+[KMG]$/i;
            if (!regex.test(uploadStr) || !regex.test(postStr) || (!regex.test(memoryStr) && memoryStr !== '-1')) {
                alert("Por favor usa el formato correcto (ej: 8M, 1G, 512K).");
                return false;
            }
            
            const uploadSize = parseSize(uploadStr);
            const postSize = parseSize(postStr);
            
            if (postSize < uploadSize) {
                alert("post_max_size debe ser mayor o igual que upload_max_filesize.");
                return false;
            }
            return true;
        }
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

        window.onTasksChecked = function(currentCount) {
            if (lastPendingCount > 0 && currentCount === 0) {
                window.location.href = window.location.pathname;
            }
            lastPendingCount = currentCount;
        };
    </script>
</body>
</html>
