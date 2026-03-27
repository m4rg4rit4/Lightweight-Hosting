<?php
session_start();
require 'config.php';
$pdo = getPDO();

// Auto-crear tabla si no existe
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_dns_servers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        url VARCHAR(255) NOT NULL,
        token VARCHAR(255) NOT NULL,
        is_active BOOLEAN DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) { /* tabla ya existe */ }

// Manejo de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $msg = '';
    $msg_type = 'error';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $url = rtrim(trim($_POST['url'] ?? ''), '/');
        $token = trim($_POST['token'] ?? '');

        if (empty($name) || empty($url) || empty($token)) {
            $msg = "Todos los campos son obligatorios.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO sys_dns_servers (name, url, token) VALUES (?, ?, ?)");
                $stmt->execute([$name, $url, $token]);
                $msg = "Servidor DNS '$name' añadido correctamente.";
                $msg_type = 'success';
            } catch (Exception $e) {
                $msg = "Error al añadir servidor: " . $e->getMessage();
            }
        }
    } elseif ($action === 'edit') {
        $id = (int)($_POST['server_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $url = rtrim(trim($_POST['url'] ?? ''), '/');
        $token = trim($_POST['token'] ?? '');

        if ($id > 0 && !empty($name) && !empty($url) && !empty($token)) {
            try {
                $stmt = $pdo->prepare("UPDATE sys_dns_servers SET name = ?, url = ?, token = ? WHERE id = ?");
                $stmt->execute([$name, $url, $token, $id]);
                $msg = "Servidor DNS actualizado correctamente.";
                $msg_type = 'success';
            } catch (Exception $e) {
                $msg = "Error al actualizar: " . $e->getMessage();
            }
        } else {
            $msg = "Todos los campos son obligatorios.";
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['server_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM sys_dns_servers WHERE id = ?")->execute([$id]);
            $msg = "Servidor DNS eliminado.";
            $msg_type = 'success';
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['server_id'] ?? 0);
        $current = (int)($_POST['current_status'] ?? 0);
        $new = $current === 1 ? 0 : 1;
        if ($id > 0) {
            $pdo->prepare("UPDATE sys_dns_servers SET is_active = ? WHERE id = ?")->execute([$new, $id]);
            $msg = $new === 1 ? "Servidor activado." : "Servidor desactivado.";
            $msg_type = 'success';
        }
    } elseif ($action === 'test') {
        $url = rtrim(trim($_POST['test_url'] ?? ''), '/');
        $token = trim($_POST['test_token'] ?? '');

        if (!empty($url) && !empty($token)) {
            $ch = curl_init($url . '/api-dns/zones');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $token,
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                $zoneCount = count($data['zones'] ?? []);
                $msg = "✅ Conexión exitosa. El servidor tiene $zoneCount zonas DNS.";
                $msg_type = 'success';
            } elseif ($httpCode === 401) {
                $msg = "❌ Token rechazado (401 Unauthorized).";
                $msg_type = 'error';
            } elseif ($httpCode === 0) {
                $msg = "❌ No se pudo conectar al servidor. Verifica la URL.";
                $msg_type = 'error';
            } else {
                $msg = "⚠️ Respuesta inesperada del servidor (HTTP $httpCode).";
                $msg_type = 'warning';
            }
        } else {
            $msg = "Introduce la URL y el token para probar la conexión.";
            $msg_type = 'error';
        }

        // Si es AJAX, devolver JSON y salir
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest' || isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $httpCode === 200, 'message' => $msg, 'type' => $msg_type]);
            exit;
        }
    }

    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_type'] = $msg_type;
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// GET
$msg = $_SESSION['flash_msg'] ?? '';
$msg_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$servers = $pdo->query("SELECT * FROM sys_dns_servers ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Hosting Admin | Servidores DNS</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <?php if ($msg): ?>
            <div class='alert alert-<?php echo $msg_type; ?>'><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <div class="section-header">
            <h1>🖧 Servidores DNS</h1>
            <button onclick="document.getElementById('form-add-server').style.display = document.getElementById('form-add-server').style.display === 'none' ? 'block' : 'none'" class="btn btn-primary">
                <span style="font-size: 1.2rem; margin-right: 8px;">+</span> Añadir Servidor
            </button>
        </div>

        <p style="color: var(--text-dim); margin: -10px 0 24px 0; font-size: 0.9rem;">
            Configura aquí los servidores DNS que administras. Cada servidor necesita su URL y un token Bearer válido para la API.
        </p>

        <!-- Formulario Añadir Servidor -->
        <div id="form-add-server" style="display: none; background: rgba(79, 70, 229, 0.05); padding: 24px; border-radius: 12px; border: 1px solid rgba(79, 70, 229, 0.3); margin-bottom: 32px; box-shadow: 0 0 20px rgba(79, 70, 229, 0.1);">
            <h3 style="margin-top: 0; font-size: 1.1rem; color: var(--primary); margin-bottom: 20px;">Nuevo Servidor DNS</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label>Nombre del Servidor</label>
                        <input type="text" name="name" placeholder="Ej: DNS Principal, DNS Secundario..." required>
                    </div>
                    <div class="form-group" style="flex: 2;">
                        <label>URL del Servidor</label>
                        <input type="text" name="url" id="add_url" placeholder="https://dns1.example.com:8090" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label>Token (Bearer API Key)</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" name="token" id="add_token" placeholder="Token de autenticación" required style="flex: 1; font-family: monospace;">
                            <button type="button" onclick="generateToken('add_token')" class="btn btn-outline" style="white-space: nowrap;" title="Generar token aleatorio">🔑 Generar</button>
                        </div>
                        <small style="color: var(--text-dim); font-size: 0.75rem;">El token debe existir en el servidor DNS como API Key activa.</small>
                    </div>
                </div>
                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 8px;">
                    <button type="button" class="btn btn-outline" onclick="testConnection('add_url', 'add_token')">🔌 Probar Conexión</button>
                    <button type="button" onclick="document.getElementById('form-add-server').style.display='none'" class="btn btn-outline">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Servidor</button>
                </div>
            </form>
            <div id="test-result-add" style="display: none; margin-top: 12px; padding: 10px 14px; border-radius: 8px; font-size: 0.9rem;"></div>
        </div>

        <!-- Tabla de Servidores -->
        <?php if (empty($servers)): ?>
            <div class="empty-state" style="padding: 60px 40px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width: 60px; height: 60px; margin-bottom: 16px; opacity: 0.15;">
                    <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                    <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                    <line x1="6" y1="6" x2="6.01" y2="6"></line>
                    <line x1="6" y1="18" x2="6.01" y2="18"></line>
                </svg>
                <h2 style="color: var(--text); font-size: 1.3rem;">No hay servidores DNS configurados</h2>
                <p>Añade un servidor DNS para empezar a gestionar tus zonas y registros desde este panel.</p>
                <button onclick="document.getElementById('form-add-server').style.display='block'; window.scrollTo({top:0, behavior:'smooth'});" class="btn btn-primary" style="margin-top: 16px;">+ Añadir Primer Servidor</button>
            </div>
        <?php else: ?>
            <div style="display: grid; gap: 16px;">
                <?php foreach ($servers as $s): ?>
                    <div class="panel" style="<?php echo $s['is_active'] ? '' : 'opacity: 0.5;'; ?> transition: opacity 0.3s;">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 200px;">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                                    <span style="width: 10px; height: 10px; border-radius: 50%; background: <?php echo $s['is_active'] ? 'var(--success)' : 'var(--text-dim)'; ?>; display: inline-block;"></span>
                                    <h3 style="margin: 0; font-size: 1.1rem;"><?php echo htmlspecialchars($s['name']); ?></h3>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--text-dim); font-family: monospace; margin-left: 20px;">
                                    <?php echo htmlspecialchars($s['url']); ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-dim); margin-left: 20px; margin-top: 4px;">
                                    Token: <code style="background: var(--bg); padding: 2px 6px; border-radius: 4px; color: var(--info);"><?php echo htmlspecialchars(substr($s['token'], 0, 8)) . '...' . htmlspecialchars(substr($s['token'], -4)); ?></code>
                                    · Añadido: <?php echo $s['created_at']; ?>
                                </div>
                            </div>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <!-- Test -->
                                <button type="button" class="btn btn-outline btn-sm" title="Probar conexión" 
                                        onclick="testConnectionInline(this, '<?php echo htmlspecialchars($s['url']); ?>', '<?php echo htmlspecialchars($s['token']); ?>')">
                                    🔌 Test
                                </button>
                                <!-- Toggle -->
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="server_id" value="<?php echo $s['id']; ?>">
                                    <input type="hidden" name="current_status" value="<?php echo $s['is_active']; ?>">
                                    <button type="submit" class="btn btn-outline btn-sm"><?php echo $s['is_active'] ? '⏸ Desactivar' : '▶ Activar'; ?></button>
                                </form>
                                <!-- Edit -->
                                <button type="button" class="btn btn-outline btn-sm" onclick="editServer(<?php echo htmlspecialchars(json_encode($s)); ?>)">✏️ Editar</button>
                                <!-- Delete -->
                                <form method="POST" style="display: inline;" onsubmit="return confirm('¿Eliminar el servidor \'<?php echo htmlspecialchars(addslashes($s['name'])); ?>\'? Los dominios asociados no se borrarán.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="server_id" value="<?php echo $s['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">🗑 Eliminar</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal Editar Servidor -->
    <div id="modal-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
        <div class="panel" style="width: 100%; max-width: 550px; border-color: var(--primary);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <h3 style="margin-top:0; font-size: 1.3rem;">Editar Servidor DNS</h3>
                <button onclick="document.getElementById('modal-edit').style.display='none'" style="background:none; border:none; color: var(--text-dim); cursor:pointer; font-size: 1.5rem;">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="server_id" id="edit_id">
                <div class="form-group">
                    <label>Nombre del Servidor</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>
                <div class="form-group">
                    <label>URL del Servidor</label>
                    <input type="text" name="url" id="edit_url" required>
                </div>
                <div class="form-group">
                    <label>Token (Bearer API Key)</label>
                    <div style="display: flex; gap: 8px;">
                        <input type="text" name="token" id="edit_token" required style="flex: 1; font-family: monospace;">
                        <button type="button" onclick="generateToken('edit_token')" class="btn btn-outline" title="Generar token aleatorio">🔑</button>
                    </div>
                </div>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="document.getElementById('modal-edit').style.display='none'" class="btn btn-outline">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function editServer(server) {
            document.getElementById('edit_id').value = server.id;
            document.getElementById('edit_name').value = server.name;
            document.getElementById('edit_url').value = server.url;
            document.getElementById('edit_token').value = server.token;
            document.getElementById('modal-edit').style.display = 'flex';
        }

        function generateToken(inputId) {
            const chars = 'abcdef0123456789';
            let result = '';
            for (let i = 0; i < 32; i++) {
                result += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById(inputId).value = result;
        }

        async function testConnection(urlInputId, tokenInputId) {
            const urlInput = document.getElementById(urlInputId);
            const tokenInput = document.getElementById(tokenInputId);
            const url = urlInput.value.replace(/\/+$/, '');
            const token = tokenInput.value;
            const resultDiv = document.getElementById('test-result-add');

            if (!url || !token) {
                resultDiv.style.display = 'block';
                resultDiv.style.background = 'rgba(239, 68, 68, 0.1)';
                resultDiv.style.color = 'var(--error)';
                resultDiv.textContent = 'Introduce la URL y el token primero.';
                return;
            }

            resultDiv.style.display = 'block';
            resultDiv.style.background = 'rgba(14, 165, 233, 0.1)';
            resultDiv.style.color = 'var(--info)';
            resultDiv.textContent = '⏳ Probando conexión...';

            try {
                const formData = new FormData();
                formData.append('action', 'test');
                formData.append('test_url', url);
                formData.append('test_token', token);
                formData.append('ajax', '1');

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await response.json();
                resultDiv.style.display = 'block';
                resultDiv.style.background = data.type === 'success' ? 'rgba(34, 197, 94, 0.1)' : 'rgba(239, 68, 68, 0.1)';
                resultDiv.style.color = data.type === 'success' ? 'var(--success)' : 'var(--error)';
                resultDiv.textContent = data.message;
            } catch (error) {
                resultDiv.style.background = 'rgba(239, 68, 68, 0.1)';
                resultDiv.style.color = 'var(--error)';
                resultDiv.textContent = '❌ Error de red o del servidor.';
            }
        }

        async function testConnectionInline(btn, url, token) {
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳...';

            try {
                const formData = new FormData();
                formData.append('action', 'test');
                formData.append('test_url', url);
                formData.append('test_token', token);
                formData.append('ajax', '1');

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await response.json();
                alert(data.message);
            } catch (error) {
                alert('❌ Error al probar conexión.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }

        // Close modal on click outside
        window.onclick = function(event) {
            const modal = document.getElementById('modal-edit');
            if (event.target === modal) modal.style.display = 'none';
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.getElementById('modal-edit').style.display = 'none';
                document.getElementById('form-add-server').style.display = 'none';
            }
        });

        // Task notification check
        async function checkTasks() {
            try {
                const response = await fetch('tasks_status.php?t=' + Date.now());
                if (!response.ok) throw new Error('Network response was not ok');
                const data = await response.json();
                const notification = document.getElementById('task-notification');
                if (data.pending_count > 0) {
                    notification.style.display = 'flex';
                } else {
                    notification.style.display = 'none';
                }
            } catch (error) { console.error('Error checking tasks:', error); }
        }
        setInterval(checkTasks, 5000);
        checkTasks();
    </script>
</body>
</html>
