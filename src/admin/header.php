<?php 
require_once __DIR__ . '/auth.php';
checkAuth();
?>
<link rel="stylesheet" href="admin-style.css">
<nav>
    <div style="display: flex; flex-direction: column;">
        <strong>Lightweight Hosting</strong>
        <?php if (defined('SYSTEM_VERSION')): ?>
            <span style="font-size: 0.7rem; color: var(--text-dim); margin-top: -4px;">v<?php echo SYSTEM_VERSION; ?></span>
        <?php endif; ?>
    </div>
    <?php
    $current_page = basename($_SERVER['PHP_SELF']);
    ?>
    <a href="index.php" class="<?php echo ($current_page === 'index.php') ? 'active' : ''; ?>">Sitios</a>
    <a href="backups.php" class="<?php echo ($current_page === 'backups.php') ? 'active' : ''; ?>">Backups (MEGA)</a>
    <a href="<?php echo defined('DB_MANAGER_DIR') ? DB_MANAGER_DIR : 'dbadmin'; ?>/" target="_blank" class="<?php echo ($current_page === 'databases.php') ? 'active' : ''; ?>">Base de Datos</a>
    
    <?php 
    require_once 'dns_utils.php';
    $hasDns = hasDnsServers();
    if ($hasDns):
        $dnsHealth = getDnsClusterHealth();
        $healthColor = 'var(--success)';
        if ($dnsHealth['status'] === 'warning') $healthColor = 'var(--warning)';
        if ($dnsHealth['status'] === 'error') $healthColor = 'var(--error)';
    ?>
        <a href="dns.php" class="<?php echo ($current_page === 'dns.php') ? 'active' : ''; ?>" style="display: flex; align-items: center; gap: 6px;">
            DNS 
            <span style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $healthColor; ?>;" title="<?php echo htmlspecialchars($dnsHealth['message']); ?>"></span>
        </a>
    <?php endif; ?>
    
    <a href="dns_servers.php" class="<?php echo ($current_page === 'dns_servers.php') ? 'active' : ''; ?>" style="display: flex; align-items: center; gap: 4px;">
        <?php if ($hasDns): ?>
            ⚙️
        <?php else: ?>
            🖧 DNS Servers
        <?php endif; ?>
    </a>
    
    <?php if ($current_page === 'filemanager.php'): ?>
        <span>/ Administrador de Archivos</span>
    <?php endif; ?>
    
    <?php if ($current_page === 'databases.php' && isset($site)): ?>
        <span style="color: var(--text-dim);">/</span>
        <span style="font-weight: 600;"><?php echo htmlspecialchars($site['domain']); ?></span>
    <?php endif; ?>
    
    <a href="tasks.php" id="task-notification" style="display: none; text-decoration: none;" class="notification-container">
        <span class="notification-dot"></span>
        <span class="pending-text">TAREAS PENDIENTES</span>
    </a>
</nav>
