<?php
session_start();
require 'config.php';

$pdo = getPDO();

$siteId = (int)($_GET['site_id'] ?? $_POST['site_id'] ?? 0);
if (!$siteId) {
    die("Error: Se requiere el ID del sitio.");
}

$stmt = $pdo->prepare("SELECT * FROM sys_sites WHERE id = ?");
$stmt->execute([$siteId]);
$site = $stmt->fetch();

if (!$site) {
    die("Error: Sitio no encontrado.");
}

$baseDir = rtrim($site['document_root'], '/');
if (!is_dir($baseDir)) {
    @mkdir($baseDir, 0755, true);
    @chown($baseDir, 'www-data');
    @chgrp($baseDir, 'www-data');
}

// Validation function against path traversal
function getValidPath($baseDir, $requestedPath) {
    $requestedPath = ltrim($requestedPath, '/');
    $fullPath = realpath($baseDir . '/' . $requestedPath);
    $realBase = realpath($baseDir);
    if ($requestedPath === '' || $requestedPath === '.') {
        return $realBase;
    }
    if ($fullPath && ($fullPath === $realBase || strpos($fullPath, $realBase . DIRECTORY_SEPARATOR) === 0)) {
        return $fullPath;
    }
    return false;
}

// Function to format file sizes
function formatSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

// Ensure correct permissions and ownership
function fixOwnership($path) {
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
}

// API Endpoints
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $path = $_POST['path'] ?? '';
    
    $fullPath = getValidPath($baseDir, $path);
    
    if ($fullPath === false && $action !== 'create' && $action !== 'upload') {
        echo json_encode(['success' => false, 'error' => 'Ruta inválida o no permitida']);
        exit;
    }

    try {
        switch ($action) {
            case 'list':
                if (!is_dir($fullPath)) {
                    throw new Exception("El elemento no es un directorio.");
                }
                $files = [];
                $items = scandir($fullPath);
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') {
                        if ($item === '..' && $fullPath !== realpath($baseDir)) {
                            // Add Parent link if not at root
                            $files[] = [
                                'name' => '..',
                                'type' => 'dir',
                                'size' => '',
                                'perms' => '',
                                'modified' => ''
                            ];
                        }
                        continue;
                    }
                    $itemPath = $fullPath . '/' . $item;
                    $files[] = [
                        'name' => $item,
                        'type' => is_dir($itemPath) ? 'dir' : 'file',
                        'size' => is_dir($itemPath) ? '' : formatSize(filesize($itemPath)),
                        'perms' => substr(sprintf('%o', fileperms($itemPath)), -4),
                        'modified' => date("Y-m-d H:i:s", filemtime($itemPath)),
                    ];
                }
                // Sort directories first
                usort($files, function($a, $b) {
                    if ($a['name'] === '..') return -1;
                    if ($b['name'] === '..') return 1;
                    if ($a['type'] === $b['type']) {
                        return strcasecmp($a['name'], $b['name']);
                    }
                    return $a['type'] === 'dir' ? -1 : 1;
                });
                echo json_encode(['success' => true, 'files' => $files]);
                break;
                
            case 'upload':
                $targetDir = getValidPath($baseDir, $path);
                if (!$targetDir) $targetDir = $baseDir . '/' . ltrim($path, '/');
                if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
                
                if (isset($_FILES['file'])) {
                    $targetFile = $targetDir . '/' . basename($_FILES['file']['name']);
                    if (move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
                        fixOwnership($targetFile);
                        @chmod($targetFile, 0644);
                        echo json_encode(['success' => true]);
                    } else {
                        throw new Exception("Error al subir el archivo.");
                    }
                } else {
                    throw new Exception("No se recibió ningún archivo.");
                }
                break;
                
            case 'create':
                $name = $_POST['name'] ?? '';
                if (empty($name)) throw new Exception("Nombre no puede estar vacío.");
                $type = $_POST['type'] ?? 'file';
                $parentDir = getValidPath($baseDir, $path);
                if (!$parentDir) throw new Exception("Ruta base inválida.");
                
                $newItemPath = rtrim($parentDir, '/') . '/' . basename($name);
                
                // Prevent path traversal in name
                $realBase = realpath($baseDir);
                if ($newItemPath !== $realBase && strpos($newItemPath, $realBase . DIRECTORY_SEPARATOR) !== 0) {
                    throw new Exception("Operación no permitida.");
                }
                
                if (file_exists($newItemPath)) {
                    throw new Exception("El elemento ya existe.");
                }
                
                if ($type === 'dir') {
                    mkdir($newItemPath, 0755, true);
                    fixOwnership($newItemPath);
                } else {
                    file_put_contents($newItemPath, "");
                    @chmod($newItemPath, 0644);
                    fixOwnership($newItemPath);
                }
                echo json_encode(['success' => true]);
                break;
                
            case 'delete':
                if ($fullPath === realpath($baseDir)) throw new Exception("No puedes borrar el directorio raíz.");
                if (is_dir($fullPath)) {
                    // Recursive directory deletion
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($files as $fileinfo) {
                        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                        $todo($fileinfo->getRealPath());
                    }
                    rmdir($fullPath);
                } else {
                    unlink($fullPath);
                }
                echo json_encode(['success' => true]);
                break;
                
            case 'rename':
                $newName = $_POST['new_name'] ?? '';
                if (empty($newName)) throw new Exception("Nombre no puede estar vacío.");
                if ($fullPath === realpath($baseDir)) throw new Exception("No puedes renombrar la raíz.");
                
                $parentDir = dirname($fullPath);
                $newPath = $parentDir . '/' . basename($newName);
                
                if (file_exists($newPath)) throw new Exception("Ese nombre ya está en uso.");
                if (rename($fullPath, $newPath)) {
                    echo json_encode(['success' => true]);
                } else {
                    throw new Exception("Error al renombrar.");
                }
                break;
                
            case 'chmod':
                if ($fullPath === realpath($baseDir)) throw new Exception("No puedes cambiar permisos a la raíz aquí.");
                $permsStr = $_POST['perms'] ?? '0644';
                $perms = octdec($permsStr);
                if (chmod($fullPath, $perms)) {
                    echo json_encode(['success' => true]);
                } else {
                    throw new Exception("Error al cambiar permisos.");
                }
                break;

            case 'read':
                if (is_dir($fullPath)) throw new Exception("No es un archivo.");
                $content = file_get_contents($fullPath);
                echo json_encode(['success' => true, 'content' => $content]);
                break;

            case 'write':
                if (is_dir($fullPath)) throw new Exception("No es un archivo.");
                $content = $_POST['content'] ?? '';
                if (file_put_contents($fullPath, $content) !== false) {
                    echo json_encode(['success' => true]);
                } else {
                    throw new Exception("Error al guardar el archivo.");
                }
                break;

            default:
                throw new Exception("Acción no válida.");
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>File Manager - <?php echo htmlspecialchars($site['domain']); ?></title>
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
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3); 
            margin: auto; 
            border: 1px solid var(--border);
        }
        nav { 
            margin-bottom: 24px; 
            padding-bottom: 16px; 
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 20px;
        }
        nav strong { font-size: 1.25rem; color: var(--primary); }
        nav a { text-decoration: none; color: var(--text-dim); transition: color 0.2s; font-weight: 500; }
        nav a:hover { color: var(--text); }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        h1 { font-size: 1.5rem; margin: 0; font-weight: 600; }
        
        .breadcrumb-bar {
            background: rgba(0,0,0,0.2);
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--border);
        }
        .breadcrumb-item { cursor: pointer; color: var(--primary); font-weight: 600; }
        .breadcrumb-item:hover { text-decoration: underline; }
        .breadcrumb-sep { color: var(--text-dim); }
        
        .toolbar {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .btn { 
            padding: 8px 16px; 
            border-radius: 6px; 
            cursor: pointer; 
            font-weight: 600; 
            font-family: inherit;
            border: none;
            font-size: 0.85rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .btn-outline:hover { background: var(--border); }
        .btn-danger { color: var(--error); border-color: rgba(239,68,68,0.3); }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.1); border-color: var(--error); }
        .btn-sm { padding: 4px 10px; font-size: 0.75rem; }

        table { width: 100%; border-collapse: collapse; background: var(--card-bg); border-radius: 8px; }
        th { text-align: left; padding: 12px 16px; border-bottom: 2px solid var(--border); color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase; background: rgba(0,0,0,0.1); }
        th:first-child { border-top-left-radius: 8px; }
        th:last-child { border-top-right-radius: 8px; }
        td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:hover { background: rgba(255,255,255,0.02); }
        tr:last-child td { border-bottom: none; }
        tr:last-child td:first-child { border-bottom-left-radius: 8px; }
        tr:last-child td:last-child { border-bottom-right-radius: 8px; }
        
        .file-icon { font-size: 1.2rem; margin-right: 8px; width: 20px; text-align: center; display: inline-block; }
        .dir-row { color: var(--info); font-weight: 600; cursor: pointer; }
        .file-row { color: var(--text); }
        
        .drag-zone {
            border: 2px dashed var(--border);
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            color: var(--text-dim);
            margin-bottom: 20px;
            transition: all 0.3s;
            background: rgba(0,0,0,0.1);
        }
        .drag-zone.dragover {
            border-color: var(--primary);
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: var(--card-bg);
            padding: 24px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            border: 1px solid var(--border);
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);
        }
        .modal-content.large { max-width: 900px; }
        .modal-header { display: flex; justify-content: space-between; margin-bottom: 20px; align-items: center; }
        .modal-header h3 { margin: 0; }
        .close-btn { cursor: pointer; font-size: 1.5rem; color: var(--text-dim); line-height: 1; }
        .close-btn:hover { color: var(--text); }
        
        input[type="text"] { 
            width: 100%; padding: 10px 12px; background: var(--bg); 
            border: 1px solid var(--border); border-radius: 6px; color: var(--text); 
            font-family: inherit; margin-bottom: 16px; box-sizing: border-box;
        }
        textarea {
            width: 100%; height: 400px; background: #000; color: #0f0; 
            border: 1px solid var(--border); border-radius: 6px; 
            font-family: monospace; padding: 12px; box-sizing: border-box;
            margin-bottom: 16px; resize: vertical;
        }
        
        .actions-dropdown {
            position: relative;
            display: inline-block;
        }
        .actions-content {
            display: none;
            position: absolute;
            right: 0;
            background-color: var(--card-bg);
            min-width: 120px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.4);
            border: 1px solid var(--border);
            border-radius: 6px;
            z-index: 10;
        }
        .actions-dropdown:hover .actions-content { display: block; }
        .actions-content a {
            color: var(--text);
            padding: 8px 12px;
            text-decoration: none;
            display: block;
            font-size: 0.8rem;
        }
        .actions-content a:hover { background-color: rgba(255,255,255,0.05); }
        .text-danger { color: var(--error) !important; }
        
    </style>
</head>
<body>
    <div class="container">
        <nav>
            <strong>Lightweight Hosting</strong>
            <a href="index.php">&larr; Volver a Sitios</a>
            <span>/ Administrador de Archivos</span>
        </nav>
        
        <div class="header">
            <h1><span style="color: var(--text-dim); font-weight: 300;">Archivos de:</span> <?php echo htmlspecialchars($site['domain']); ?></h1>
        </div>

        <div class="toolbar">
            <button class="btn btn-primary" onclick="openModal('createModal')">+ Nueva Carpeta/Archivo</button>
            <button class="btn btn-outline" onclick="document.getElementById('fileInput').click()">↑ Subir Archivos</button>
            <input type="file" id="fileInput" multiple style="display: none;" onchange="handleFileUpload(event)">
        </div>
        
        <div class="drag-zone" id="dragZone">
            Arrastra archivos aquí para subirlos a la carpeta actual
        </div>

        <div class="breadcrumb-bar" id="breadcrumb">
            <!-- Pato breadcrumb js fill -->
        </div>

        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Tamaño</th>
                    <th>Permisos</th>
                    <th>Modificado</th>
                    <th style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody id="fileTableBody">
                <!-- Javascript will populate this -->
            </tbody>
        </table>
    </div>

    <!-- Modals -->
    <div class="modal" id="createModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Crear Nuevo Elemento</h3>
                <span class="close-btn" onclick="closeModal('createModal')">&times;</span>
            </div>
            <select id="createType" style="width: 100%; padding: 10px; margin-bottom: 16px; background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 6px;">
                <option value="dir">Carpeta (Directorio)</option>
                <option value="file">Archivo de texto</option>
            </select>
            <input type="text" id="createName" placeholder="Nombre (ej. nueva_carpeta o archivo.txt)">
            <button class="btn btn-primary" onclick="createItem()">Crear</button>
        </div>
    </div>

    <div class="modal" id="renameModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Renombrar</h3>
                <span class="close-btn" onclick="closeModal('renameModal')">&times;</span>
            </div>
            <input type="hidden" id="renameOldName">
            <input type="text" id="renameNewName" placeholder="Nuevo nombre">
            <button class="btn btn-primary" onclick="renameItem()">Guardar</button>
        </div>
    </div>

    <div class="modal" id="chmodModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Cambiar Permisos (Chmod)</h3>
                <span class="close-btn" onclick="closeModal('chmodModal')">&times;</span>
            </div>
            <input type="hidden" id="chmodName">
            <input type="text" id="chmodValue" placeholder="Ej. 0644 o 0755">
            <button class="btn btn-primary" onclick="chmodItem()">Guardar</button>
        </div>
    </div>

    <div class="modal" id="editorModal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3 id="editorTitle">Editando archivo</h3>
                <span class="close-btn" onclick="closeModal('editorModal')">&times;</span>
            </div>
            <input type="hidden" id="editorFilePath">
            <textarea id="editorContent" spellcheck="false"></textarea>
            <div style="text-align: right;">
                <button class="btn btn-outline" onclick="closeModal('editorModal')">Cancelar</button>
                <button class="btn btn-primary" onclick="saveFile()">Guardar Cambios</button>
            </div>
        </div>
    </div>

    <script>
        const siteId = <?php echo $siteId; ?>;
        let currentPath = ''; // Raíz
        
        const dragZone = document.getElementById('dragZone');
        
        // Inicializar Drag and Drop
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dragZone.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            dragZone.addEventListener(eventName, () => dragZone.classList.add('dragover'), false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            dragZone.addEventListener(eventName, () => dragZone.classList.remove('dragover'), false);
        });
        
        dragZone.addEventListener('drop', (e) => {
            let dt = e.dataTransfer;
            
            // Si soporta webkitGetAsEntry, lo usamos para recursividad
            if (dt.items && dt.items.length > 0 && dt.items[0].webkitGetAsEntry) {
                dragZone.innerHTML = "Procesando subida...";
                let uploadQueue = [];
                let activeUploads = 0;
                let pendingEntries = 0;

                function processEntry(entry, path) {
                    if (entry.isFile) {
                        pendingEntries++;
                        entry.file(file => {
                            uploadQueue.push({ action: 'upload', file: file, currentDir: path });
                            pendingEntries--;
                            checkStartUploads();
                        });
                    } else if (entry.isDirectory) {
                        pendingEntries++;
                        uploadQueue.push({ action: 'create_dir', dirName: entry.name, currentDir: path });
                        let dirReader = entry.createReader();
                        let newPath = path ? path + '/' + entry.name : entry.name;
                        
                        function readEntries() {
                            dirReader.readEntries(entries => {
                                if (entries.length > 0) {
                                    entries.forEach(e => processEntry(e, newPath));
                                    readEntries(); // Seguir leyendo (API puede devolver en bloques)
                                } else {
                                    pendingEntries--;
                                    checkStartUploads();
                                }
                            });
                        }
                        readEntries();
                    }
                }

                function checkStartUploads() {
                    // Start actual HTTP calls only when file structure has been completely parsed
                    if (pendingEntries === 0) {
                        processQueueRecursively();
                    }
                }

                async function processQueueRecursively() {
                    dragZone.innerHTML = "Subiendo archivos y carpetas...";
                    // Sort queue: process creates (dirs) first, then files
                    uploadQueue.sort((a, b) => {
                        if (a.action === 'create_dir' && b.action !== 'create_dir') return -1;
                        if (a.action !== 'create_dir' && b.action === 'create_dir') return 1;
                        return 0; // maintain relative order otherwise
                    });

                    for (let i = 0; i < uploadQueue.length; i++) {
                        let item = uploadQueue[i];
                        let formData = new FormData();
                        formData.append('site_id', siteId);
                        
                        // Determinar ruta destino final incluyendo la ruta local del navegador
                        let targetPath = currentPath;
                        if (targetPath && item.currentDir) targetPath += '/' + item.currentDir;
                        else if (!targetPath && item.currentDir) targetPath = item.currentDir;
                        
                        if (item.action === 'create_dir') {
                            formData.append('action', 'create');
                            formData.append('type', 'dir');
                            formData.append('name', item.dirName);
                            formData.append('path', targetPath);
                        } else {
                            formData.append('action', 'upload');
                            formData.append('file', item.file);
                            formData.append('path', targetPath);
                        }
                        
                        try {
                            await fetch('filemanager.php', { method: 'POST', body: formData });
                        } catch (err) {
                            console.error("Error interaccionando", item, err);
                        }
                    }
                    
                    dragZone.innerHTML = "Arrastra archivos y carpetas aquí para subirlos";
                    document.getElementById('fileInput').value = '';
                    loadFiles(currentPath);
                }

                // Iniciar el procesado
                for (let i = 0; i < dt.items.length; i++) {
                    let entry = dt.items[i].webkitGetAsEntry();
                    if (entry) {
                        processEntry(entry, '');
                    }
                }
                
            } else {
                // Fallback clásico
                handleFiles(dt.files);
            }
        }, false);
        
        function handleFileUpload(e) {
            handleFiles(e.target.files);
        }
        
        async function handleFiles(files) {
            if (!files.length) return;
            dragZone.innerHTML = "Subiendo archivos...";
            
            for (let i = 0; i < files.length; i++) {
                let formData = new FormData();
                formData.append('action', 'upload');
                formData.append('path', currentPath);
                formData.append('site_id', siteId);
                formData.append('file', files[i]);
                
                try {
                    await fetch('filemanager.php', { method: 'POST', body: formData });
                } catch (err) {
                    console.error("Error subiendo", files[i].name, err);
                    alert("Error al subir " + files[i].name);
                }
            }
            
            dragZone.innerHTML = "Arrastra archivos y carpetas aquí para subirlos a la carpeta actual";
            document.getElementById('fileInput').value = '';
            loadFiles(currentPath);
        }

        async function doPost(data) {
            data.append('site_id', siteId);
            const res = await fetch('filemanager.php', {
                method: 'POST',
                body: data
            });
            const result = await res.json();
            if (!result.success) {
                alert(result.error || "Se produjo un error desconocido.");
                throw new Error(result.error);
            }
            return result;
        }

        function updateBreadcrumb() {
            const bc = document.getElementById('breadcrumb');
            let html = `<span class="breadcrumb-item" onclick="loadFiles('')">/raíz</span>`;
            if (currentPath) {
                const parts = currentPath.split('/').filter(p => p !== '');
                let builtPath = '';
                parts.forEach((p, idx) => {
                    builtPath += '/' + p;
                    html += ` <span class="breadcrumb-sep">&rsaquo;</span> <span class="breadcrumb-item" onclick="loadFiles('${builtPath}')">${p}</span>`;
                });
            }
            bc.innerHTML = html;
        }

        async function loadFiles(path) {
            try {
                let body = new FormData();
                body.append('action', 'list');
                body.append('path', path);
                
                const result = await doPost(body);
                currentPath = path.replace(/^\/+/, ''); // Clean leading slash
                updateBreadcrumb();
                
                const tbody = document.getElementById('fileTableBody');
                tbody.innerHTML = '';
                
                if (result.files.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-dim);">(Carpeta vacía)</td></tr>';
                    return;
                }
                
                result.files.forEach(f => {
                    const tr = document.createElement('tr');
                    const fullItemPath = currentPath ? currentPath + '/' + f.name : f.name;
                    
                    let nameHtml = '';
                    if (f.name === '..') {
                        // Navegar un nivel arriba
                        let parentPath = currentPath.split('/');
                        parentPath.pop();
                        parentPath = parentPath.join('/');
                        nameHtml = `<div class="dir-row" onclick="loadFiles('${parentPath}')"><span class="file-icon">📁</span> .. (Arriba)</div>`;
                    } else if (f.type === 'dir') {
                        nameHtml = `<div class="dir-row" onclick="loadFiles('${fullItemPath}')"><span class="file-icon">📁</span> ${f.name}</div>`;
                    } else {
                        nameHtml = `<div class="file-row"><span class="file-icon">📄</span> ${f.name}</div>`;
                    }
                    
                    let actionsHtml = '';
                    if (f.name !== '..') {
                        const isText = (f.name.match(/\.(txt|php|html|css|js|json|md|csv|xml)$/i) !== null);
                        actionsHtml = `
                            <div class="actions-dropdown">
                                <button class="btn btn-outline btn-sm">Opciones ▼</button>
                                <div class="actions-content">
                                    ${(f.type === 'file' && isText) ? `<a href="#" onclick="openEditor('${fullItemPath}')">Editar (Texto)</a>` : ''}
                                    <a href="#" onclick="showRename('${fullItemPath}', '${f.name}')">Renombrar</a>
                                    <a href="#" onclick="showChmod('${fullItemPath}', '${f.perms}')">Permisos</a>
                                    <a href="#" class="text-danger" onclick="deleteItem('${fullItemPath}')">Borrar</a>
                                </div>
                            </div>
                        `;
                    }
                    
                    tr.innerHTML = `
                        <td>${nameHtml}</td>
                        <td>${f.size}</td>
                        <td style="font-family: monospace;">${f.perms || ''}</td>
                        <td>${f.modified}</td>
                        <td style="text-align: right;">${actionsHtml}</td>
                    `;
                    tbody.appendChild(tr);
                });
                
            } catch (err) {
                console.error(err);
            }
        }

        async function createItem() {
            const name = document.getElementById('createName').value;
            const type = document.getElementById('createType').value;
            if (!name) return alert("Ingresa un nombre.");
            
            try {
                let data = new FormData();
                data.append('action', 'create');
                data.append('path', currentPath);
                data.append('name', name);
                data.append('type', type);
                await doPost(data);
                closeModal('createModal');
                document.getElementById('createName').value = '';
                loadFiles(currentPath);
            } catch (e) {}
        }
        
        async function deleteItem(path) {
            if (!confirm(`¿Estás seguro de que deseas eliminar permanentemente: ${path}?`)) return;
            try {
                let data = new FormData();
                data.append('action', 'delete');
                data.append('path', path);
                await doPost(data);
                loadFiles(currentPath);
            } catch (e) {}
        }
        
        function showRename(path, currentName) {
            document.getElementById('renameOldName').value = path;
            document.getElementById('renameNewName').value = currentName;
            openModal('renameModal');
        }
        
        async function renameItem() {
            const path = document.getElementById('renameOldName').value;
            const newName = document.getElementById('renameNewName').value;
            if (!newName) return;
            
            try {
                let data = new FormData();
                data.append('action', 'rename');
                data.append('path', path);
                data.append('new_name', newName);
                await doPost(data);
                closeModal('renameModal');
                loadFiles(currentPath);
            } catch (e) {}
        }

        function showChmod(path, currentPerms) {
            document.getElementById('chmodName').value = path;
            document.getElementById('chmodValue').value = "0" + currentPerms;
            openModal('chmodModal');
        }

        async function chmodItem() {
            const path = document.getElementById('chmodName').value;
            const perms = document.getElementById('chmodValue').value;
            if (!perms) return;
            
            try {
                let data = new FormData();
                data.append('action', 'chmod');
                data.append('path', path);
                data.append('perms', perms);
                await doPost(data);
                closeModal('chmodModal');
                loadFiles(currentPath);
            } catch (e) {}
        }

        async function openEditor(path) {
            try {
                let data = new FormData();
                data.append('action', 'read');
                data.append('path', path);
                const res = await doPost(data);
                
                document.getElementById('editorTitle').innerText = 'Editando: ' + path;
                document.getElementById('editorFilePath').value = path;
                document.getElementById('editorContent').value = res.content;
                openModal('editorModal');
            } catch (err) {}
        }

        async function saveFile() {
            const path = document.getElementById('editorFilePath').value;
            const content = document.getElementById('editorContent').value;
            try {
                let data = new FormData();
                data.append('action', 'write');
                data.append('path', path);
                data.append('content', content);
                await doPost(data);
                alert("Archivo guardado con éxito.");
                closeModal('editorModal');
                loadFiles(currentPath);
            } catch (err) {}
        }

        // Modal Helpers
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }
        
        // Setup close on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

        // Init
        loadFiles('');
    </script>
</body>
</html>
