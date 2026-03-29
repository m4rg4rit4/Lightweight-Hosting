<?php
session_start();
require 'config.php';
$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = '';
    $msg_type = 'error';

    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        switch ($action) {
            case 'mega_login':
                $email = $_POST['mega_email'] ?? '';
                $pass = $_POST['mega_pass'] ?? '';
                if ($email && $pass) {
                    $payload = json_encode(['email' => $email, 'password' => $pass]);
                    $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('MEGA_LOGIN', ?)")->execute([$payload]);
                    $msg = "Petición de vinculación a MEGA enviada. Espera unos momentos.";
                    $msg_type = 'info';
                }
                break;
            case 'mega_logout':
                $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('MEGA_LOGOUT', '{}')")->execute();
                $msg = "Petición para desvincular MEGA enviada.";
                $msg_type = 'info';
                break;
            case 'mega_sync':
                $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('MEGA_SYNC_BACKUPS', '{}')")->execute();
                $msg = "Petición de sincronización enviada. Buscando copias en MEGA...";
                $msg_type = 'info';
                break;
            case 'set_retention':
                $days = (int)($_POST['retention_days'] ?? 7);
                $pdo->prepare("REPLACE INTO sys_settings (setting_key, setting_value) VALUES ('backup_retention_days', ?)")->execute([$days]);
                $msg = "Retención configurada a $days días.";
                $msg_type = 'success';
                break;
            case 'set_frequency':
                $siteId = (int)$_POST['site_id'];
                $freq = $_POST['frequency'] ?? 'none';
                if (in_array($freq, ['none', 'daily', 'weekly'])) {
                    try {
                        $pdo->prepare("UPDATE sys_sites SET backup_frequency = ? WHERE id = ?")->execute([$freq, $siteId]);
                        $msg = "Frecuencia de copia actualizada.";
                        $msg_type = 'success';
                    } catch (Exception $e) {
                        $msg = "Error: Es posible que falte la columna 'backup_frequency' en la tabla 'sys_sites'. El motor de tareas la creará automáticamente en su próxima ejecución, o puedes revisar el archivo install.sh.";
                        $msg_type = 'error';
                    }
                }
                break;
            case 'backup_now':
                $siteId = (int)$_POST['site_id'];
                $payload = json_encode(['site_id' => $siteId]);
                $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('SITE_BACKUP', ?)")->execute([$payload]);
                $msg = "Copia de seguridad encolada. Se subirá a MEGA pronto.";
                $msg_type = 'success';
                break;
            case 'restore_now':
                $backupId = (int)$_POST['backup_id'];
                $payload = json_encode(['backup_id' => $backupId]);
                $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('SITE_RESTORE', ?)")->execute([$payload]);
                $msg = "Restauración de copia programada. Puede tardar varios minutos dependiendo del tamaño.";
                $msg_type = 'warning';
                break;
        }

        if ($msg) {
            $_SESSION['flash_msg'] = $msg;
            $_SESSION['flash_type'] = $msg_type;
        }
        header("Location: backups.php");
        exit;
    }
}

$msg = $_SESSION['flash_msg'] ?? '';
$msg_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// Validar si las tablas existen (por si es nueva instalación y faltan cosas)
try {
    $settingsRaw = $pdo->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll();
    $settings = [];
    foreach ($settingsRaw as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $settings = [];
    $msg = "Error: Faltan las tablas de sistema para Backups. Si acabas de actualizar, revisa el archivo install.sh";
    $msg_type = "error";
}

$megaEmail = $settings['mega_email'] ?? '';
$megaStatus = $settings['mega_status'] ?? 'logged_out';
$retentionDays = $settings['backup_retention_days'] ?? 7;

$sitesWithBackups = [];
try {
    $sites = $pdo->query("SELECT * FROM sys_sites ORDER BY domain ASC")->fetchAll();
    foreach ($sites as $site) {
        $stmt = $pdo->prepare("SELECT * FROM sys_backups WHERE site_id = ? ORDER BY created_at DESC");
        $stmt->execute([$site['id']]);
        $site['backups'] = $stmt->fetchAll();
        $sitesWithBackups[] = $site;
    }
} catch (Exception $e) {}

// Verificar tareas pendientes relacionadas
$megaTaskPending = $pdo->query("SELECT COUNT(*) FROM sys_tasks WHERE task_type LIKE 'MEGA_%' AND status IN ('pending', 'running')")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Backups MEGA | Hosting Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        .mega-card { background: rgba(79, 70, 229, 0.05); border: 1px solid rgba(79, 70, 229, 0.2); padding: 24px; border-radius: 12px; margin-bottom: 32px; }
        .mega-logged { display: flex; align-items: center; justify-content: space-between; }
        .mega-status { display: flex; align-items: center; gap: 10px; font-weight: 600; }
        .dot-green { width: 10px; height: 10px; background: var(--success); border-radius: 50%; display: inline-block; }
        .dot-red { width: 10px; height: 10px; background: var(--error); border-radius: 50%; display: inline-block; }

        .site-header { background: rgba(0,0,0,0.1); padding: 12px 16px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; margin-top: 32px; border: 1px solid var(--border); border-bottom: none; }
        .site-body { border: 1px solid var(--border); border-radius: 0 0 8px 8px; padding: 0 16px; }
        
        .empty-state { padding: 30px; text-align: center; color: var(--text-dim); }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <?php if ($msg): ?>
            <div class='alert alert-<?php echo $msg_type; ?>'>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <!-- MEGA CONFIGURATION -->
        <div class="mega-card">
            <?php if ($megaTaskPending > 0): ?>
                <div class="alert alert-info" style="margin: 0;">
                    ⏳ Hay tareas de vinculación/desvinculación con MEGA en proceso... La página reflejará los cambios en breve.
                </div>
            <?php elseif ($megaEmail && $megaStatus === 'logged_in'): ?>
                <div class="mega-logged">
                    <div>
                        <div class="mega-status"><span class="dot-green"></span> Conectado a MEGA</div>
                        <div style="color: var(--text-dim); margin-top: 4px; font-size: 0.9rem;">Cuenta: <strong><?php echo htmlspecialchars($megaEmail); ?></strong></div>
                    </div>
                    
                    <div style="display: flex; gap: 15px; align-items: center;">
                        <form method="POST" style="display:inline; margin-right: 20px;">
                            <input type="hidden" name="action" value="set_retention">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <label style="margin:0; white-space:nowrap;">Retener (días):</label>
                                <input type="number" name="retention_days" value="<?php echo htmlspecialchars($retentionDays); ?>" style="width: 80px; padding: 6px 10px;" min="1" max="365">
                                <button type="submit" class="btn btn-outline btn-sm">Guardar</button>
                            </div>
                        </form>
                        
                        <form method="POST" onsubmit="return confirm('¿Seguro que quieres buscar y sincronizar copias antiguas desde MEGA?');">
                            <input type="hidden" name="action" value="mega_sync">
                            <button type="submit" class="btn btn-outline btn-sm">Sincronizar</button>
                        </form>
                        
                        <form method="POST" onsubmit="return confirm('¿Seguro que quieres desvincular MEGA? No se podrán hacer ni restaurar backups.');">
                            <input type="hidden" name="action" value="mega_logout">
                            <button type="submit" class="btn btn-danger btn-sm">Desvincular</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <h2 style="font-size: 1.2rem; color: var(--primary); margin-bottom: 20px;"><span class="dot-red"></span> Configurar MEGA (Destino Remoto)</h2>
                <p style="color: var(--text-dim); font-size: 0.9rem; margin-bottom: 20px;">
                    Las copias se comprimen localmente, se suben a tu cuenta de MEGA usando <code>megacmd</code> y se borran del disco local para ahorrar el valioso espacio del servidor VPS. Solo necesitas una cuenta gratuita de MEGA.nz (20GB).
                </p>
                <form method="POST" style="display: flex; gap: 15px; align-items: flex-end;">
                    <input type="hidden" name="action" value="mega_login">
                    <div style="flex: 1;">
                        <label>Email de MEGA</label>
                        <input type="email" name="mega_email" required placeholder="tu@email.com">
                    </div>
                    <div style="flex: 1;">
                        <label>Contraseña</label>
                        <input type="password" name="mega_pass" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="height: 41px;">Vincular y Conectar</button>
                </form>
            <?php endif; ?>
        </div>

        <h1 style="font-size: 1.5rem; margin-top: 10px; margin-bottom: 24px;">Gestión de Copias por Sitio</h1>
        
        <?php if (!$megaEmail || $megaStatus !== 'logged_in'): ?>
            <div class="alert alert-warning">
                Debes vincular una cuenta de MEGA para poder realizar copias de seguridad de los sitios.
            </div>
        <?php endif; ?>

        <?php foreach ($sitesWithBackups as $site): ?>
            <div class="site-header">
                <div>
                    <strong style="font-size: 1.1rem;"><?php echo htmlspecialchars($site['domain']); ?></strong>
                    <div style="font-size: 0.8rem; color: var(--text-dim);">DocRoot: <?php echo htmlspecialchars($site['document_root']); ?></div>
                </div>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <form method="POST" style="display: flex; align-items: center; gap: 8px;">
                        <input type="hidden" name="action" value="set_frequency">
                        <input type="hidden" name="site_id" value="<?php echo $site['id']; ?>">
                        <label style="margin:0; font-size: 0.8rem;">Auto:</label>
                        <select name="frequency" onchange="this.form.submit()" style="padding: 4px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg); color: var(--text); font-family: inherit; font-size: 0.8rem;">
                            <option value="none" <?php echo ($site['backup_frequency'] ?? 'none') === 'none' ? 'selected' : ''; ?>>Nunca</option>
                            <option value="daily" <?php echo ($site['backup_frequency'] ?? '') === 'daily' ? 'selected' : ''; ?>>Diaria</option>
                            <option value="weekly" <?php echo ($site['backup_frequency'] ?? '') === 'weekly' ? 'selected' : ''; ?>>Semanal (Dom)</option>
                        </select>
                    </form>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="backup_now">
                        <input type="hidden" name="site_id" value="<?php echo $site['id']; ?>">
                        <button type="submit" class="btn btn-primary btn-sm" <?php echo (!$megaEmail || $megaStatus !== 'logged_in') ? 'disabled style="opacity:0.5"' : ''; ?>>+ Copia Ahora</button>
                    </form>
                </div>
            </div>
            <div class="site-body">
                <?php if (empty($site['backups'])): ?>
                    <div class="empty-state">No hay copias de seguridad almacenadas para este sitio en MEGA.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Archivo</th>
                                <th>Estado</th>
                                <th style="text-align: right;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($site['backups'] as $bkp): ?>
                            <tr>
                                <td style="font-size: 0.85rem; color: var(--text-dim);"><?php echo $bkp['created_at']; ?></td>
                                <td style="font-family: monospace; font-size: 0.85rem; word-break: break-all; max-width: 300px;">
                                    <?php echo htmlspecialchars($bkp['filename']); ?>
                                </td>
                                <td>
                                    <?php if ($bkp['status'] === 'completed'): ?>
                                        <span style="color: var(--success); font-size: 0.8rem; font-weight: 600;">Completada</span>
                                    <?php elseif ($bkp['status'] === 'pending'): ?>
                                        <span style="color: var(--warning); font-size: 0.8rem; font-weight: 600;">Subiendo...</span>
                                    <?php else: ?>
                                        <span style="color: var(--error); font-size: 0.8rem; font-weight: 600;"><?php echo $bkp['status']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <form method="POST" onsubmit="return confirm('¿ATENCIÓN: Esto borrará la base de datos actual y los ficheros del sitio para sobrescribirlos con la copia. ¿Deseas continuar?');">
                                        <input type="hidden" name="action" value="restore_now">
                                        <input type="hidden" name="backup_id" value="<?php echo $bkp['id']; ?>">
                                        <button type="submit" class="btn btn-outline btn-sm btn-danger" <?php echo (!$megaEmail || $megaStatus !== 'logged_in') ? 'disabled style="opacity:0.5"' : ''; ?>>
                                            Restaurar
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if (empty($sitesWithBackups)): ?>
            <div class="alert alert-info">Aún no hay sitios creados en el servidor.</div>
        <?php endif; ?>

    </div>
</body>
</html>
