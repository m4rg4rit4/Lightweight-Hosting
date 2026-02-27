<?php
require 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

try {
    $pdo = getPDO();
    // Usamos fetchColumn para obtener directamente el conteo
    $stmt = $pdo->query("SELECT COUNT(*) FROM sys_tasks WHERE status IN ('pending', 'running')");
    $count = $stmt->fetchColumn();
    echo json_encode(['pending_count' => (int)$count]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
