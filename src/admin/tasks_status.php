<?php
require_once 'auth.php';
checkAuth();
require_once 'dns_utils.php';
require_once 'config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

try {
    $pdo = getPDO();
    
    // 1. Tareas locales del hosting
    $stmt = $pdo->query("SELECT COUNT(*) FROM sys_tasks WHERE status IN ('pending', 'running')");
    $localCount = (int)$stmt->fetchColumn();
    
    // 2. Tareas remotas del DNS
    $dnsCount = 0;
    $servers = getDnsServers();
    foreach ($servers as $server) {
        $res = dnsApiRequestOnServer($server['url'], '/api-dns/status/pending', 'GET', null, $server['token']);
        if ($res['code'] === 200) {
            $data = json_decode($res['response'], true);
            if ($data && isset($data['pending_count'])) {
                $dnsCount += (int)$data['pending_count'];
            }
        }
    }
    
    echo json_encode(['pending_count' => $localCount + $dnsCount]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
