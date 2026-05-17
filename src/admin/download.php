<?php
/**
 * Safe Single-Use Downloader with Auto-Delete
 */
require_once 'auth.php';
checkAuth();

// Activar ignore_user_abort para garantizar que el archivo se elimine del servidor
// incluso si el usuario cancela la descarga a mitad del proceso.
ignore_user_abort(true);

// Limitar tiempo de ejecución a 0 (ilimitado) para descargas grandes
set_time_limit(0);

$msg = '';
$file = isset($_GET['file']) ? trim($_GET['file']) : '';

if (empty($file)) {
    die("Error: No se especificó ningún archivo.");
}

// Validación estricta para evitar salto de directorio (Path Traversal)
// Nombre de archivo esperado: export_{domain}_{token}.tar.gz
if (!preg_match('/^export_[a-z0-9.-]+_[a-f0-9]{32}\.tar\.gz$/', $file)) {
    die("Error: Nombre de archivo no válido.");
}

$downloadsDir = __DIR__ . '/downloads';
$filePath = $downloadsDir . '/' . $file;

if (!file_exists($filePath)) {
    die("Error: El archivo de exportación no existe o ya ha sido descargado.");
}

// 1. Limpiar archivos antiguos de más de 1 hora en downloads/
$now = time();
if (is_dir($downloadsDir)) {
    foreach (glob("$downloadsDir/export_*_*.tar.gz") as $oldFile) {
        if (($now - filemtime($oldFile)) > 3600) {
            @unlink($oldFile);
        }
    }
}

// 2. Transmitir el archivo al cliente
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));

// Limpiar todos los buffers de salida para evitar corrupción de datos comprimidos
while (ob_get_level()) {
    ob_end_clean();
}

// Hacer streaming del archivo
readfile($filePath);

// 3. Eliminar el archivo del servidor inmediatamente después de finalizado el envío
@unlink($filePath);
exit;
