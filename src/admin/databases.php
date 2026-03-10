<?php
session_start();
require 'config.php';
$pdo = getPDO();

$siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
if (!$siteId) {
    header("Location: index.php");
    exit;
}

$site = $pdo->prepare("SELECT * FROM sys_sites WHERE id = ?");
$site->execute([$siteId]);
$site = $site->fetch();

if (!$site) {
    header("Location: index.php");
    exit;
}

// Manejo de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = '';
    $msg_type = 'error';

    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'create_db') {
            $dbName = trim($_POST['db_name']);
            $dbUser = trim($_POST['db_user']);
            $dbPass = $_POST['db_pass'];
            
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName) || !preg_match('/^[a-zA-Z0-9_]+$/', $dbUser) || strlen($dbName) > 64 || strlen($dbUser) > 32) {
                $_SESSION['flash_msg'] = "Error: El nombre de la base de datos o usuario contiene caracteres no válidos (solo letras, números y _).";
                $_SESSION['flash_type'] = "error";
                header("Location: databases.php?site_id=$siteId");
                exit;
            }
            
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM sys_databases WHERE db_name = ? OR db_user = ?");
            $stmt_check->execute([$dbName, $dbUser]);
            if ($stmt_check->fetchColumn() > 0) {
                $_SESSION['flash_msg'] = "Error: La base de datos '$dbName' o el usuario '$dbUser' ya existen en el sistema.";
                $_SESSION['flash_type'] = "error";
                header("Location: databases.php?site_id=$siteId");
                exit;
            }
            
            if ($dbName && $dbUser && $dbPass) {
                $payload = json_encode([
                    'site_id' => $siteId,
                    'db_name' => $dbName,
                    'db_user' => $dbUser,
                    'db_pass' => $dbPass
                ]);
                $stmt = $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('DB_CREATE', ?)");
                $stmt->execute([$payload]);
                $msg = "Tarea de creación de base de datos '$dbName' encolada.";
                $msg_type = 'success';
            }
        } elseif ($action === 'delete_db') {
            $dbId = (int)$_POST['db_id'];
            $db = $pdo->prepare("SELECT * FROM sys_databases WHERE id = ? AND site_id = ?");
            $db->execute([$dbId, $siteId]);
            $db = $db->fetch();
            
            if ($db) {
                $payload = json_encode([
                    'db_name' => $db['db_name'],
                    'db_user' => $db['db_user']
                ]);
                $stmt = $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('DB_DELETE', ?)");
                $stmt->execute([$payload]);
                $msg = "Tarea de eliminación de base de datos '" . $db['db_name'] . "' encolada.";
                $msg_type = 'success';
            }
        } elseif ($action === 'change_pass') {
            $dbId = (int)$_POST['db_id'];
            $newPass = $_POST['new_pass'];
            $db = $pdo->prepare("SELECT * FROM sys_databases WHERE id = ? AND site_id = ?");
            $db->execute([$dbId, $siteId]);
            $db = $db->fetch();
            
            if ($db && $newPass) {
                $payload = json_encode([
                    'db_name' => $db['db_name'],
                    'db_user' => $db['db_user'],
                    'new_pass' => $newPass
                ]);
                $stmt = $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('DB_CHANGE_PASSWORD', ?)");
                $stmt->execute([$payload]);
                $msg = "Tarea de cambio de contraseña para '" . $db['db_user'] . "' encolada.";
                $msg_type = 'success';
            }
        }
    }

    if ($msg) {
        $_SESSION['flash_msg'] = $msg;
        $_SESSION['flash_type'] = $msg_type;
    }
    header("Location: databases.php?site_id=$siteId");
    exit;
}

$msg = $_SESSION['flash_msg'] ?? '';
$msg_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$databases = $pdo->prepare("SELECT * FROM sys_databases WHERE site_id = ?");
$databases->execute([$siteId]);
$databases = $databases->fetchAll();

// Verificar si hay tareas en curso para este sitio (reutilizamos la lógica de index.php)
$is_processing = $pdo->prepare("
    SELECT COUNT(*) FROM sys_tasks 
    WHERE status IN ('pending', 'running') 
    AND (JSON_EXTRACT(payload, '$.site_id') = ? OR JSON_EXTRACT(payload, '$.db_name') IN (SELECT db_name FROM sys_databases WHERE site_id = ?))
");
$is_processing->execute([$siteId, $siteId]);
$processing_count = $is_processing->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bases de Datos | <?php echo htmlspecialchars($site['domain']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        #new-db-form { display: none; background: rgba(79, 70, 229, 0.05); padding: 24px; border-radius: 12px; border: 1px solid rgba(79, 70, 229, 0.3); margin-bottom: 32px; }
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

        <div class="section-header">
            <h1>Bases de Datos para <?php echo htmlspecialchars($site['domain']); ?></h1>
            <button onclick="document.getElementById('new-db-form').style.display='block'" class="btn btn-primary">+ Nueva Database</button>
        </div>

        <div id="new-db-form">
            <h2 style="margin-top: 0; font-size: 1.1rem; margin-bottom: 20px; color: var(--primary);">Crear Nueva Base de Datos</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_db">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Nombre de la Base de Datos</label>
                        <input type="text" name="db_name" required placeholder="ej: db_<?php echo explode('.', $site['domain'])[0]; ?>">
                    </div>
                    <div class="form-group">
                        <label>Usuario</label>
                        <input type="text" name="db_user" required placeholder="ej: user_<?php echo explode('.', $site['domain'])[0]; ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Contraseña</label>
                    <input type="text" name="db_pass" required placeholder="Contraseña segura">
                </div>
                <button type="submit" class="btn btn-primary">Crear en Sistema</button>
                <button type="button" onclick="document.getElementById('new-db-form').style.display='none'" class="btn btn-outline" style="margin-left: 10px;">Cancelar</button>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Nombre DB</th>
                    <th>Usuario</th>
                    <th>Contraseña</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($databases as $db): ?>
                <tr>
                    <td style="font-weight: 600;"><?php echo htmlspecialchars($db['db_name']); ?></td>
                    <td><?php echo htmlspecialchars($db['db_user']); ?></td>
                    <td>
                        <form method="POST" style="display: flex; gap: 8px; align-items: center;">
                            <input type="hidden" name="action" value="change_pass">
                            <input type="hidden" name="db_id" value="<?php echo $db['id']; ?>">
                            <input type="text" name="new_pass" value="<?php echo htmlspecialchars($db['db_pass']); ?>" style="padding: 4px 8px; font-size: 0.8rem; width: 140px;">
                            <button type="submit" class="btn btn-outline" style="padding: 4px 8px; font-size: 0.7rem;">Cambiar</button>
                        </form>
                    </td>
                    <td>
                        <div style="display: flex; gap: 8px;">
                            <form method="POST" onsubmit="return confirm('¿Estás seguro de eliminar esta base de datos definitivamente?');">
                                <input type="hidden" name="action" value="delete_db">
                                <input type="hidden" name="db_id" value="<?php echo $db['id']; ?>">
                                <button type="submit" class="btn btn-outline btn-danger" style="padding: 4px 8px; font-size: 0.7rem;">Eliminar</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($databases)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; color: var(--text-dim); padding: 40px;">No hay bases de datos creadas para este sitio.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if ($processing_count > 0): ?>
            <div style="margin-top: 30px; text-align: center;">
                <span class="badge badge-running">El sistema está procesando cambios... La página se refrescará cuando terminen.</span>
            </div>
            <script>
                setTimeout(() => window.location.reload(), 5000);
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
