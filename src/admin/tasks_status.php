<?php
require 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

try {
    $pdo = getPDO();
    
    // 1. Tareas locales del hosting
    $stmt = $pdo->query("SELECT COUNT(*) FROM sys_tasks WHERE status IN ('pending', 'running')");
    $localCount = (int)$stmt->fetchColumn();
    
    // 2. Tareas remotas del DNS (si está configurado)
    $dnsCount = 0;
    if (defined('DNS_TOKEN') && defined('DNS_SERVER') && !empty(DNS_TOKEN) && !empty(DNS_SERVER)) {
        $servers = array_filter(array_map('trim', explode(',', DNS_SERVER)));
        if (!empty($servers)) {
            $serverUrl = $servers[0];
            $baseUrl = (strpos($serverUrl, 'http') === 0) ? rtrim($serverUrl, '/') : "http://" . rtrim($serverUrl, '/');
            
            $ch = curl_init($baseUrl . '/api-dns/status/pending');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . DNS_TOKEN]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $res = curl_exec($ch);
            if ($res) {
                $data = json_decode($res, true);
                if ($data && isset($data['pending_count'])) {
                    $dnsCount = (int)$data['pending_count'];
                }
            }
            curl_close($ch);
        }
    }
    
    echo json_encode(['pending_count' => $localCount + $dnsCount]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
