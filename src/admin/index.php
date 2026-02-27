<?php
require 'config.php';
$pdo = getPDO();
$msg = '';

// Manejo de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Crear nuevo sitio
    if (isset($_POST['domain'])) {
        $domain = filter_var($_POST['domain'], FILTER_SANITIZE_URL);
        $php = isset($_POST['php']) ? 1 : 0;
        
        if ($domain) {
            try {
                $stmt = $pdo->prepare("INSERT INTO sys_sites (domain, document_root, php_enabled) VALUES (?, ?, ?)");
                $doc_root = "/var/www/vhosts/" . $domain;
                $stmt->execute([$domain, $doc_root, $php]);
                
                $payload = json_encode(['domain' => $domain, 'php_enabled' => $php, 'path' => $doc_root]);
                $stmt = $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('SITE_CREATE', ?)");
                $stmt->execute([$payload]);
                
                $msg = "<p style='color:green'>Sitio '$domain' añadido a la cola de procesamiento.</p>";
            } catch (Exception $e) {
                $msg = "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
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
                    $taskType = 'SITE_TOGGLE_STATUS';
                    $payload['new_status'] = ($site['status'] === 'active') ? 'inactive' : 'active';
                    break;
                case 'toggle_ssl':
                    $taskType = 'SSL_LETSENCRYPT';
                    break;
                case 'delete':
                    $taskType = 'SITE_DELETE';
                    break;
            }

            if ($taskType) {
                $stmt = $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES (?, ?)");
                $stmt->execute([$taskType, json_encode($payload)]);
                $msg = "<p style='color:green'>Tarea de " . strtolower($action) . " para '" . $site['domain'] . "' encolada.</p>";
            }
        }
    }
}

$sites = $pdo->query("SELECT * FROM sys_sites ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <!-- El refresco se maneja por AJAX ahora -->
    <title>Hosting Admin | Control Panel</title>
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
        nav a:hover { color: var(--text); }
        h1 { font-size: 1.5rem; margin-top: 40px; margin-bottom: 24px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        h1:first-of-type { margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { text-align: left; padding: 12px; border-bottom: 2px solid var(--border); color: var(--text-dim); font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; }
        td { padding: 16px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: var(--text-dim); font-size: 0.9rem; }
        input[type="text"] { 
            width: 100%; 
            padding: 12px 16px; 
            background: var(--bg); 
            border: 1px solid var(--border); 
            border-radius: 8px; 
            color: var(--text); 
            font-family: inherit;
        }
        input[type="text"]:focus { outline: none; border-color: var(--primary); }
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
            text-decoration: none;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .btn-outline:hover { background: var(--border); }
        .btn-sm { padding: 4px 10px; font-size: 0.75rem; }
        .btn-danger { color: var(--error); }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.1); }
        
        .badge { 
            padding: 4px 10px; 
            border-radius: 9999px; 
            font-size: 0.75rem; 
            font-weight: 600; 
            text-transform: uppercase;
        }
        .badge-pending { background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.2); }
        .badge-active { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); }
        .badge-inactive { background: rgba(148, 163, 184, 0.1); color: var(--text-dim); border: 1px solid rgba(148, 163, 184, 0.2); }
        .badge-running { background: rgba(14, 165, 233, 0.1); color: var(--info); border: 1px solid rgba(14, 165, 233, 0.2); }
        .badge-error { background: rgba(239, 68, 68, 0.1); color: var(--error); border: 1px solid rgba(239, 68, 68, 0.2); }
        
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; font-size: 0.9rem; border: 1px solid transparent; }
        .alert-success { background: rgba(16, 185, 129, 0.1); color: var(--success); border-color: rgba(16, 185, 129, 0.2); }
        .alert-error { background: rgba(239, 68, 68, 0.1); color: var(--error); border-color: rgba(239, 68, 68, 0.2); }
        
        .actions { display: flex; gap: 8px; }
        
        /* Notificaciones de Tareas */
        .notification-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: auto;
            padding: 6px 14px;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 20px;
        }
        .notification-dot {
            width: 10px;
            height: 10px;
            background-color: var(--error);
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 12px var(--error);
            animation: pulse-red 2s infinite;
        }
        @keyframes pulse-red {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .pending-text {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text);
            letter-spacing: 0.02em;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav>
            <strong>Lightweight Hosting</strong>
            <a href="index.php">Sitios</a>
            <a href="<?php echo defined('DB_MANAGER_DIR') ? DB_MANAGER_DIR : 'dbadmin'; ?>/" target="_blank">Base de Datos</a>
            
            <div id="task-notification" style="display: none;" class="notification-container">
                <span class="notification-dot"></span>
                <span class="pending-text">TAREAS PENDIENTES</span>
            </div>
        </nav>
        
        <h1>Añadir Nuevo Dominio</h1>
        <?php if ($msg) { 
            $type = strpos($msg, 'color:green') !== false ? 'success' : 'error';
            echo "<div class='alert alert-$type'>" . strip_tags($msg) . "</div>";
        } ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Dominio (ej: misitio.com)</label>
                <input type="text" name="domain" required placeholder="example.com">
            </div>
            <div class="form-group" style="display: flex; align-items: center; gap: 10px;">
                <input type="checkbox" name="php" id="php_enabled" checked style="width: 18px; height: 18px;">
                <label for="php_enabled" style="margin-bottom: 0;">Habilitar soporte PHP</label>
            </div>
            <button type="submit" class="btn btn-primary">Crear Sitio</button>
        </form>

        <h1>Sitios Configurados</h1>
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
                            <button type="submit" class="btn btn-outline btn-sm">
                                <?php if ($s['php_enabled'] == 1): ?>
                                    <span style="color: var(--success);">●</span> Activo
                                <?php else: ?>
                                    <span style="color: var(--text-dim);">○</span> Inactivo
                                <?php endif; ?>
                            </button>
                        </form>
                    </td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="site_id" value="<?php echo $s['id']; ?>">
                            <input type="hidden" name="action" value="toggle_ssl">
                            <button type="submit" class="btn btn-outline btn-sm" <?php echo ($s['status'] !== 'active') ? 'disabled' : ''; ?>>
                                <?php if ($s['ssl_enabled'] == 1): ?>
                                    <span style="color: var(--success);">🔒</span> Protegido
                                <?php else: ?>
                                    <span style="color: var(--text-dim);">🔓</span> Sin SSL
                                <?php endif; ?>
                            </button>
                        </form>
                    </td>
                    <td><span class="badge badge-<?php echo $s['status']; ?>"><?php echo $s['status']; ?></span></td>
                    <td>
                        <div class="actions">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="site_id" value="<?php echo $s['id']; ?>">
                                <input type="hidden" name="action" value="toggle_status">
                                <button type="submit" class="btn btn-outline btn-sm">
                                    <?php echo ($s['status'] === 'active') ? 'Desactivar' : 'Activar'; ?>
                                </button>
                            </form>
                            <?php if ($s['id'] != 1): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Estás seguro de eliminar el dominio <?php echo $s['domain']; ?>?');">
                                <input type="hidden" name="site_id" value="<?php echo $s['id']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-outline btn-sm btn-danger">Eliminar</button>
                            </form>
                            <?php else: ?>
                                <button class="btn btn-outline btn-sm" style="opacity: 0.5; cursor: not-allowed;" title="El sitio principal no se puede eliminar">Eliminar</button>
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

    <script>
        let lastPendingCount = -1;

        async function checkTasks() {
            try {
                const response = await fetch('tasks_status.php');
                const data = await response.json();
                const currentCount = data.pending_count;

                const notification = document.getElementById('task-notification');
                if (currentCount > 0) {
                    notification.style.display = 'flex';
                } else {
                    notification.style.display = 'none';
                }

                // Si antes había tareas y ahora no, refrescamos para mostrar los cambios
                if (lastPendingCount > 0 && currentCount === 0) {
                    location.reload();
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
