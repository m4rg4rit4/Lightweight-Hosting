<?php
require 'config.php';
$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_task') {
    $taskId = (int)$_POST['task_id'];
    $stmt = $pdo->prepare("UPDATE sys_tasks SET status = 'error', result_msg = 'Cancelled by user via Panel' WHERE id = ? AND status = 'pending'");
    $stmt->execute([$taskId]);
    header("Location: tasks.php?msg=Task+Cancelled");
    exit;
}

// Paginación o límite simple
$tasks = $pdo->query("SELECT * FROM sys_tasks ORDER BY created_at DESC LIMIT 25")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tareas del Sistema | Hosting Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
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
        }
        .container { 
            max-width: 1000px; 
            background: var(--card-bg); 
            padding: 32px; 
            border-radius: 16px; 
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
        nav a { text-decoration: none; color: var(--text-dim); font-weight: 500; }
        nav a:hover { color: var(--text); }
        h1 { font-size: 1.5rem; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; border-bottom: 2px solid var(--border); color: var(--text-dim); font-size: 0.75rem; text-transform: uppercase; }
        td { padding: 16px 12px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        .badge { 
            padding: 4px 10px; 
            border-radius: 9999px; 
            font-size: 0.7rem; 
            font-weight: 600; 
            text-transform: uppercase;
        }
        .badge-pending { background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.2); }
        .badge-success { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); }
        .badge-running { background: rgba(14, 165, 233, 0.1); color: var(--info); border: 1px solid rgba(14, 165, 233, 0.2); }
        .badge-error { background: rgba(239, 68, 68, 0.1); color: var(--error); border: 1px solid rgba(239, 68, 68, 0.2); }
        
        pre { 
            background: var(--bg); 
            padding: 8px; 
            border-radius: 4px; 
            font-size: 0.75rem; 
            margin: 0; 
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text-dim);
        }
    </style>
</head>
<body>
    <div class="container">
        <nav>
            <strong>Lightweight Hosting</strong>
            <a href="index.php">Sitios</a>
            <a href="backups.php">Backups (MEGA)</a>
            <a href="tasks.php" style="color: var(--text);">Historial de Tareas</a>
        </nav>

        <h1>Cola de Procesamiento</h1>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>Payload</th>
                    <th>Resultado / Error</th>
                    <th>Fecha</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $t): ?>
                <tr>
                    <td style="font-family: monospace; color: var(--text-dim);">#<?php echo $t['id']; ?></td>
                    <td style="font-weight: 600;"><?php echo $t['task_type']; ?></td>
                    <td><span class="badge badge-<?php echo $t['status']; ?>"><?php echo $t['status']; ?></span></td>
                    <td><pre><?php echo htmlspecialchars($t['payload']); ?></pre></td>
                    <td style="font-size: 0.8rem; color: <?php echo $t['status'] === 'error' ? 'var(--error)' : 'var(--text-dim)'; ?>;">
                        <?php echo htmlspecialchars($t['result_msg']); ?>
                    </td>
                    <td style="color: var(--text-dim); font-size: 0.8rem;"><?php echo $t['created_at']; ?></td>
                    <td>
                        <?php if ($t['status'] === 'pending'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿Seguro que deseas cancelar esta tarea?');">
                            <input type="hidden" name="action" value="cancel_task">
                            <input type="hidden" name="task_id" value="<?php echo $t['id']; ?>">
                            <button type="submit" style="background:transparent; border:1px solid rgba(239, 68, 68, 0.3); color:var(--error); border-radius:4px; padding:4px 8px; cursor:pointer; font-size:0.75rem;">Cancelar</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($tasks)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-dim);">No hay tareas registradas.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
