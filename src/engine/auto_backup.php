<?php
/**
 * Hosting Custom - Auto Backup Script
 * Enqueues a backup task for each active site.
 * Designed to be run daily via cron.
 */

require '/var/www/admin_panel/config.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

// 1. Check if MEGA is configured
$stmt = $pdo->prepare("SELECT setting_value FROM sys_settings WHERE setting_key = 'mega_status'");
$stmt->execute();
$megaStatus = $stmt->fetchColumn();

if ($megaStatus !== 'logged_in') {
    echo "MEGA is not configured. Aborting auto-backup.\n";
    exit(0);
}

// 2. Fetch all active sites
$stmt = $pdo->prepare("SELECT id, domain FROM sys_sites WHERE status = 'active'");
$stmt->execute();
$sites = $stmt->fetchAll();

if (empty($sites)) {
    echo "No active sites found. Aborting auto-backup.\n";
    exit(0);
}

// 3. Enqueue backup task for each site
$count = 0;
foreach ($sites as $site) {
    $siteId = (int)$site['id'];
    $domain = $site['domain'];
    
    // Check if there's already a pending backup task for this site to avoid duplicates
    $checkStmt = $pdo->prepare("SELECT id FROM sys_tasks WHERE task_type = 'SITE_BACKUP' AND payload LIKE ? AND status IN ('pending', 'running')");
    $checkStmt->execute(['%"site_id":' . $siteId . '%']);
    
    if (!$checkStmt->fetch()) {
        $payload = json_encode(['site_id' => $siteId]);
        $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('SITE_BACKUP', ?)")->execute([$payload]);
        echo "Enqueued backup for site: $domain (ID: $siteId)\n";
        $count++;
    } else {
        echo "Skipped $domain: Backup task already pending/running.\n";
    }
}

echo "Auto-backup process finished. Enqueued $count task(s).\n";
?>
