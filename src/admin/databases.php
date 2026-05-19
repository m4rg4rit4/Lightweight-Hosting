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
                header("Location: databases.php?site_id=$siteId&new=1");
                exit;
            }
            
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM sys_databases WHERE db_name = ? OR db_user = ?");
            $stmt_check->execute([$dbName, $dbUser]);
            if ($stmt_check->fetchColumn() > 0) {
                $_SESSION['flash_msg'] = "Error: La base de datos '$dbName' o el usuario '$dbUser' ya existen en el sistema.";
                $_SESSION['flash_type'] = "error";
                header("Location: databases.php?site_id=$siteId&new=1");
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
            <button onclick="toggleNewDbForm()" class="btn <?php echo isset($_GET['new']) ? 'btn-outline' : 'btn-primary'; ?>" id="toggle-db-btn">
                <?php echo isset($_GET['new']) ? '<span style="font-size: 1.2rem; margin-right: 8px;">−</span> Cancelar' : '+ Nueva Database'; ?>
            </button>
        </div>

        <div id="new-db-form" class="<?php echo isset($_GET['new']) ? 'show' : ''; ?>">
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
                    <div style="position: relative; display: flex; align-items: center;">
                        <input type="password" name="db_pass" id="db_pass" required placeholder="Contraseña segura" style="padding-right: 40px;">
                        <button type="button" onclick="togglePasswordVisibility('db_pass', this)" style="position: absolute; right: 10px; background: none; border: none; color: var(--text-dim); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; padding: 0;" title="Ver/Ocultar contraseña">
                            👁️
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Crear en Sistema</button>
                <button type="button" onclick="toggleNewDbForm()" class="btn btn-outline" style="margin-left: 10px;">Cancelar</button>
            </form>
        </div>

        <div style="background: rgba(14, 165, 233, 0.05); border: 1px solid rgba(14, 165, 233, 0.2); padding: 16px; border-radius: 12px; margin-bottom: 24px; display: flex; gap: 12px; align-items: flex-start;">
            <span style="font-size: 1.25rem; color: var(--info); margin-top: 2px;">ℹ️</span>
            <div style="font-size: 0.85rem; color: var(--text-dim); line-height: 1.4;">
                <strong style="color: var(--text);">Nota de administración:</strong> Para garantizar la integridad y seguridad del motor MariaDB, los nombres y usuarios de las bases de datos no son editables una vez creados. Si necesitas cambiar el nombre o el usuario, deberás eliminar la base de datos y crear una nueva. La contraseña puede ser modificada directamente en la tabla.
            </div>
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
                        <form method="POST" style="display: flex; gap: 8px; align-items: center; max-width: 260px; position: relative;">
                            <input type="hidden" name="action" value="change_pass">
                            <input type="hidden" name="db_id" value="<?php echo $db['id']; ?>">
                            <div style="position: relative; display: flex; align-items: center; width: 160px;">
                                <input type="password" name="new_pass" id="pass_<?php echo $db['id']; ?>" value="<?php echo htmlspecialchars($db['db_pass']); ?>" style="padding: 6px 30px 6px 8px; font-size: 0.85rem; width: 100%; border-radius: 6px; box-sizing: border-box; background: var(--bg); border: 1px solid var(--border);">
                                <button type="button" onclick="togglePasswordVisibility('pass_<?php echo $db['id']; ?>', this)" style="position: absolute; right: 8px; background: none; border: none; color: var(--text-dim); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; padding: 0;" title="Ver/Ocultar contraseña">👁️</button>
                            </div>
                            <button type="submit" class="btn btn-outline btn-sm" style="padding: 6px 12px; font-size: 0.75rem; white-space: nowrap;">Cambiar</button>
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

    <script>
        function toggleNewDbForm() {
            const form = document.getElementById('new-db-form');
            const btn = document.getElementById('toggle-db-btn');
            
            form.classList.toggle('show');
            
            if (form.classList.contains('show')) {
                btn.innerHTML = '<span style="font-size: 1.2rem; margin-right: 8px;">−</span> Cancelar';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-outline');
                setTimeout(() => {
                    form.querySelector('input[name="db_name"]').focus();
                }, 100);
            } else {
                btn.innerHTML = '+ Nueva Database';
                btn.classList.remove('btn-outline');
                btn.classList.add('btn-primary');
            }
        }
        
        function togglePasswordVisibility(inputId, buttonEl) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                buttonEl.textContent = '🔒';
            } else {
                input.type = 'password';
                buttonEl.textContent = '👁️';
            }
        }
    </script>
</body>
</html>
