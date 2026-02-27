<?php
require 'config.php';
header('Content-Type: application/json');

try {
    $pdo = getPDO();
    $stmt = $pdo->query("SELECT COUNT(*) as pending FROM sys_tasks WHERE status IN ('pending', 'running')");
    $result = $stmt->fetch();
    echo json_encode(['pending_count' => (int)$result['pending']]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
