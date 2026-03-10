<?php
require 'config.php';
$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $taskId = (int)$_POST['task_id'];

    if ($action === 'cancel_task') {
        $stmt = $pdo->prepare("UPDATE sys_tasks SET status = 'error', result_msg = 'Cancelled by user via Panel' WHERE id = ? AND status = 'pending'");
        $stmt->execute([$taskId]);
        header("Location: tasks.php?msg=Task+Cancelled");
        exit;
    } elseif ($action === 'delete_task') {
        $stmt = $pdo->prepare("DELETE FROM sys_tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        header("Location: tasks.php?msg=Task+Deleted");
        exit;
    }
}

// Paginación o límite simple
$tasks = $pdo->query("SELECT * FROM sys_tasks ORDER BY created_at DESC LIMIT 50")->fetchAll();
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
            background: var(--card-bg); 
            padding: 32px; 
            border-radius: 16px; 
            margin: auto; 
            border: 1px solid var(--border);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3);
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
        nav a.active { color: var(--text); border-bottom: 2px solid var(--primary); padding-bottom: 14px; }

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

        h1 { font-size: 1.5rem; margin-bottom: 24px; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; border-bottom: 2px solid var(--border); color: var(--text-dim); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { padding: 16px 12px; border-bottom: 1px solid var(--border); font-size: 0.9rem; vertical-align: middle; }
        .badge { 
            padding: 4px 10px; 
            border-radius: 9999px; 
            font-size: 0.7rem; 
            font-weight: 600; 
            text-transform: uppercase;
        }
        .badge-pending { background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.2); }
        .badge-success { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); }
        .badge-completed { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); }
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
            font-family: 'Fira Code', monospace;
        }
        .btn-action {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-dim);
            border-radius: 4px;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 0.75rem;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-action:hover { border-color: var(--text); color: var(--text); background: rgba(255,255,255,0.05); }
        .btn-danger { color: var(--error); border-color: rgba(239, 68, 68, 0.3); }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.1); border-color: var(--error); }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <h1>Cola de Procesamiento</h1>
            <div style="font-size: 0.85rem; color: var(--text-dim);">Últimas 50 tareas</div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>Payload</th>
                    <th>Resultado / Error</th>
                    <th>Fecha</th>
                    <th style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $t): ?>
                <tr>
                    <td style="font-family: monospace; color: var(--text-dim); font-weight: 600;">#<?php echo $t['id']; ?></td>
                    <td style="font-weight: 600; color: var(--primary);"><?php echo $t['task_type']; ?></td>
                    <td><span class="badge badge-<?php echo $t['status']; ?>"><?php echo $t['status']; ?></span></td>
                    <td><pre title='<?php echo htmlspecialchars($t['payload']); ?>'><?php echo htmlspecialchars(substr($t['payload'], 0, 50)) . (strlen($t['payload']) > 50 ? '...' : ''); ?></pre></td>
                    <td style="font-size: 0.8rem; color: <?php echo $t['status'] === 'error' ? 'var(--error)' : 'var(--text-dim)'; ?>;">
                        <?php echo htmlspecialchars($t['result_msg']); ?>
                    </td>
                    <td style="color: var(--text-dim); font-size: 0.8rem; white-space: nowrap;"><?php echo $t['created_at']; ?></td>
                    <td style="text-align: right;">
                        <div style="display: flex; gap: 8px; justify-content: flex-end;">
                            <?php if ($t['status'] === 'pending'): ?>
                            <form method="POST" onsubmit="return confirm('¿Seguro que deseas cancelar esta tarea?');">
                                <input type="hidden" name="action" value="cancel_task">
                                <input type="hidden" name="task_id" value="<?php echo $t['id']; ?>">
                                <button type="submit" class="btn-action">Cancelar</button>
                            </form>
                            <?php endif; ?>
                            
                            <form method="POST" onsubmit="return confirm('¿Seguro que deseas ELIMINAR permanentemente esta tarea del historial?');">
                                <input type="hidden" name="action" value="delete_task">
                                <input type="hidden" name="task_id" value="<?php echo $t['id']; ?>">
                                <button type="submit" class="btn-action btn-danger" title="Eliminar Tarea">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($tasks)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-dim);">No hay tareas registradas.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
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
