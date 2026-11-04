<?php
/**
 * Hosting Custom - Task Processor (ISPConfig Style)
 * Runs as root via cron
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!is_dir('/run/lightweight-hosting')) mkdir('/run/lightweight-hosting', 0700);
$engineLock = fopen('/run/lightweight-hosting/engine.lock', 'c');
if (!$engineLock || !flock($engineLock, LOCK_EX | LOCK_NB)) exit;
// The lock is held until process exit, including long backups and updates.

// --- Configuración de Comandos ---
$cmd_a2ensite = "/usr/sbin/a2ensite";
$cmd_a2dissite = "/usr/sbin/a2dissite";
$cmd_apache_reload = "/usr/bin/systemctl reload apache2";
$cmd_certbot = "/usr/bin/certbot";

// 0. Detectar entorno y utilidades
$php_version = file_exists('/etc/php/') ? array_filter(scandir('/etc/php/'), function($v) { return is_numeric($v[0]); }) : [];
$php_v = !empty($php_version) ? max($php_version) : '8.2'; // Usar la versión más alta detectorra
$php_socket = "/run/php/php$php_v-fpm.sock";

// Fallback: buscar cualquier socket activo si el detectado no existe
if (!file_exists($php_socket)) {
    // Probar el socket genérico de Debian 13 primero
    if (file_exists('/run/php/php-fpm.sock')) {
        $php_socket = '/run/php/php-fpm.sock';
    } else {
        $found_sockets = glob("/run/php/php*-fpm.sock");
        if (!empty($found_sockets)) {
            $php_socket = $found_sockets[0];
            // Extraer versión del socket si es posible
            if (preg_match('/php([\d\.]+)-fpm/', $php_socket, $matches)) {
                $php_v = $matches[1];
            }
        }
    }
}

// Garantizar que NUNCA esté vacío para evitar "400 Bad Request"
if (empty($php_socket) || !file_exists($php_socket)) {
    $found_any = glob("/run/php/php*-fpm.sock");
    $php_socket = !empty($found_any) ? $found_any[0] : "/run/php/php8.2-fpm.sock";
}

// Support both production and local development paths
if (file_exists('/var/www/admin_panel/config.php')) {
    require '/var/www/admin_panel/config.php';
    require_once '/var/www/admin_panel/dns_utils.php';
} else {
    require_once __DIR__ . '/../admin/config.php';
    require_once __DIR__ . '/../admin/dns_utils.php';
}

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// --- Funciones Auxiliares ---
function privateWorkdir() {
    $path = sys_get_temp_dir() . '/lwh-' . bin2hex(random_bytes(16));
    if (!mkdir($path, 0700)) throw new RuntimeException('No se pudo crear el directorio temporal.');
    return $path;
}
function copyApplicationTree($source, $destination) {
    checkedCommand('cp -a -- ' . escapeshellarg(rtrim($source, '/') . '/.') . ' ' . escapeshellarg($destination));
    checkedCommand('python3 ' . escapeshellarg(__DIR__ . '/archive_links.py') . ' ' . escapeshellarg($source) . ' ' . escapeshellarg($destination));
}
function checkedCommand($command) {
    $output = []; $code = 0;
    exec($command . ' 2>&1', $output, $code);
    if ($code !== 0) throw new RuntimeException('Comando fallido (código ' . $code . ').');
    return $output;
}
function dumpDatabase($dumpBin, $auth, $dbName, $destination) {
    $sql = $destination . '.sql';
    try {
        checkedCommand($dumpBin . ' ' . $auth . ' --single-transaction --routines --events --triggers ' . escapeshellarg($dbName) . ' > ' . escapeshellarg($sql));
        checkedCommand('gzip -c -- ' . escapeshellarg($sql) . ' > ' . escapeshellarg($destination));
    } finally { @unlink($sql); }
}


function getPublicIP() {
    $ip = shell_exec("/usr/bin/curl -s https://api.ipify.org");
    return trim($ip);
}

function checkExternalDNS($domain, $expectedIP) {
    // Usar dig con el resolver de Google para evitar caché local/hosts
    $output = shell_exec("/usr/bin/dig @8.8.8.8 " . escapeshellarg($domain) . " +short");
    $ips = array_filter(explode("\n", trim($output)));
    return in_array($expectedIP, $ips);
}

/**
 * Nombres (SAN) que cubre realmente el certificado vivo de un dominio.
 * Devuelve [] si no hay certificado. Sirve para detectar certificados emitidos
 * "cojos" (p.ej. sin www) y poder ampliarlos despues.
 */
function getCertNames($domain) {
    $file = "/etc/letsencrypt/live/$domain/cert.pem";
    if (!file_exists($file)) return [];
    $out = shell_exec("/usr/bin/openssl x509 -in " . escapeshellarg($file) . " -noout -text 2>/dev/null");
    if (!$out || !preg_match_all('/DNS:([^\s,]+)/i', $out, $m)) return [];
    return array_map('strtolower', $m[1]);
}

/**
 * De los subdominios que el panel siempre sirve (www, pma, phpmyadmin), cuales
 * apuntan ya a nuestra IP pero NO estan en el certificado. Si el certificado es
 * wildcard no falta ninguno.
 */
function getMissingCertNames($domain, $publicIP) {
    $certNames = getCertNames($domain);
    if (empty($certNames)) return [];
    if (in_array("*.$domain", $certNames)) return [];

    $missing = [];
    foreach (['www', 'pma', 'phpmyadmin'] as $sub) {
        $fqdn = "$sub.$domain";
        if (in_array($fqdn, $certNames)) continue;
        if (checkExternalDNS($fqdn, $publicIP)) $missing[] = $fqdn;
    }
    return $missing;
}

function siteUser($domain) { return 'lwh_' . substr(hash('sha256', $domain), 0, 16); }
function fpmPoolLimit($phpVersion) {
    $configuration = @file_get_contents("/etc/php/$phpVersion/fpm/php-fpm.conf");
    if ($configuration && preg_match('/^\s*process\.max\s*=\s*(\d+)/m', $configuration, $match)) return min(10, max(2, intdiv((int)$match[1], 2)));
    return 2;
}
function siteApplicationRoot($domain, $documentRoot) {
    if (strlen($domain) > 253 || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $domain)) throw new RuntimeException('Dominio inválido.');
    $documentRoot = rtrim($documentRoot, '/');
    $container = $documentRoot === '/var/www/html' ? $documentRoot : '/var/www/vhosts/' . $domain;
    if ($documentRoot !== $container && strpos($documentRoot, $container . '/') !== 0) throw new RuntimeException('Raíz del sitio fuera de su contenedor.');
    foreach (explode('/', substr($documentRoot, 1)) as $part) {
        if ($part === '.' || $part === '..' || !preg_match('/^[a-zA-Z0-9._-]+$/D', $part)) throw new RuntimeException('Ruta de sitio inválida.');
    }
    // Check all existing ancestors, including a parent of a missing public dir.
    $current = '';
    foreach (explode('/', substr($documentRoot, 1)) as $part) {
        $current .= '/' . $part;
        if (is_link($current)) throw new RuntimeException('La raíz del sitio contiene un enlace.');
    }
    return $container;
}
function normalizeHtaccessOptions($contents) {
    return preg_replace_callback('/^([ \t]*Options[ \t]+)([^\r\n]*)/mi', function ($match) {
        $tokens = preg_split('/([ \t]+)/', $match[2], -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($tokens as &$token) {
            if (strcasecmp($token, '+FollowSymLinks') === 0) $token = '+SymLinksIfOwnerMatch';
            elseif (strcasecmp($token, 'FollowSymLinks') === 0) $token = 'SymLinksIfOwnerMatch';
        }
        return $match[1] . implode('', $tokens);
    }, $contents);
}
function migrateHtaccessOptions($path, $user) {
    $backupDir = '/var/lib/lightweight-hosting/htaccess-migration/' . $user;
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($entries as $entry) {
        if ($entry->getFilename() !== '.htaccess' || $entry->isLink() || !$entry->isFile()) continue;
        $file = $entry->getPathname();
        $contents = file_get_contents($file);
        if ($contents === false) throw new RuntimeException('No se pudo leer .htaccess.');
        $updated = normalizeHtaccessOptions($contents);
        if ($updated === $contents) continue;
        if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true)) throw new RuntimeException('No se pudo guardar la configuración anterior.');
        $backup = $backupDir . '/' . hash('sha256', $file) . '.htaccess';
        if (!file_exists($backup) && !copy($file, $backup)) throw new RuntimeException('No se pudo guardar .htaccess.');
        chmod($backup, 0600);
        if (file_put_contents($file, $updated) === false) throw new RuntimeException('No se pudo adaptar .htaccess.');
    }
}
function isolateSite($domain, $path, $phpVersion) {
    global $isolationBatch;
    $applicationRoot = siteApplicationRoot($domain, $path);
    $user = siteUser($domain);
    umask(0007);
    if (!posix_getpwnam($user)) checkedCommand('/usr/sbin/useradd --system --user-group --no-create-home --shell /usr/sbin/nologin ' . escapeshellarg($user));
    // Panel and Apache can access sites; site users cannot access each other or the panel.
    checkedCommand('/usr/sbin/usermod -a -G ' . escapeshellarg($user) . ' www-data');
    if (is_dir($applicationRoot)) {
        migrateHtaccessOptions($applicationRoot, $user);
        checkedCommand('chown -R -h -- ' . escapeshellarg($user . ':' . $user) . ' ' . escapeshellarg($applicationRoot));
        checkedCommand('find ' . escapeshellarg($applicationRoot) . ' -type d -exec chmod 2770 {} +');
        checkedCommand('find ' . escapeshellarg($applicationRoot) . ' -type f -exec chmod u+rw,g+rw,o-rwx {} +');
    }
    $poolLimit = fpmPoolLimit($phpVersion);
    $pool = "[$user]\nuser = $user\ngroup = $user\nlisten = /run/php/$user.sock\nlisten.owner = www-data\nlisten.group = www-data\nlisten.mode = 0600\npm = ondemand\npm.max_children = $poolLimit\npm.process_idle_timeout = 10s\npm.max_requests = 300\nphp_admin_value[memory_limit] = 128M\nphp_admin_value[session.save_path] = /var/lib/php/$user\n";
    if (!is_dir('/var/lib/php/' . $user)) mkdir('/var/lib/php/' . $user, 0700, true);
    chown('/var/lib/php/' . $user, $user);
    file_put_contents("/etc/php/$phpVersion/fpm/pool.d/$user.conf", $pool);
    checkedCommand('/usr/sbin/php-fpm' . $phpVersion . ' -t');
    if (empty($isolationBatch)) {
        checkedCommand('/usr/bin/systemctl restart php' . $phpVersion . '-fpm');
        checkedCommand('/usr/bin/systemctl restart apache2');
    }
}
function generateVhost($domain, $document_root, $php_enabled, $php_v, $is_ssl = false) {
    global $php_socket, $pdo;
    $siteSocket = "/run/php/" . siteUser($domain) . ".sock";
    $identity = siteUser($domain);
    $applicationRoot = siteApplicationRoot($domain, $document_root);
    clearstatcache(true, $applicationRoot);
    $account = posix_getpwnam($identity);
    if (!file_exists("/etc/php/$php_v/fpm/pool.d/$identity.conf") || !$account ||
        (is_dir($applicationRoot) && fileowner($applicationRoot) !== $account['uid'])) {
        isolateSite($domain, $document_root, $php_v);
    }
    $port = $is_ssl ? 443 : 80;
    $vhost = "<VirtualHost *:$port>\n";
    $vhost .= "    ServerName $domain\n";
    $vhost .= "    ServerAlias www.$domain pma.$domain phpmyadmin.$domain\n";
    $vhost .= "    DocumentRoot $document_root\n";
    $vhost .= "    DirectoryIndex index.php index.html\n";
    $vhost .= "    LwhGuardSiteHandler \"proxy:unix:$siteSocket|fcgi://localhost/\"\n";
    $vhost .= "    LwhGuardPanelHandler \"proxy:unix:$php_socket|fcgi://localhost/\"\n";
    $vhost .= "    ErrorLog \${APACHE_LOG_DIR}/" . ($is_ssl ? "ssl_" : "") . "{$domain}_error.log\n";
    $vhost .= "    CustomLog \${APACHE_LOG_DIR}/" . ($is_ssl ? "ssl_" : "") . "{$domain}_access.log combined\n\n";
    
    // Soporte para subdominios mágicos phpmyadmin.dominio.com -> /phpmyadmin
    $vhost .= "    RewriteEngine On\n";
    $vhost .= "    RewriteCond %{HTTP_HOST} ^(pma|phpmyadmin)\. [NC]\n";
    $vhost .= "    RewriteRule ^/(.*)$ /phpmyadmin/$1 [L,PT]\n\n";

    $vhost .= "    <Directory $document_root>\n";
    $vhost .= "        Options -Indexes -FollowSymLinks +SymLinksIfOwnerMatch\n";
    // Live .htaccess support, as in ISPConfig. Keep symlink/CGI options controlled
    // by root while allowing rewrites, headers, authentication and cache rules.
    $vhost .= "        AllowOverride All Options=Indexes,MultiViews,SymLinksIfOwnerMatch\n";
    $vhost .= "        Require all granted\n";
    $vhost .= "    </Directory>\n\n";
    // Location sections merge after .htaccess: the PHP identity belongs to the
    // vhost, while applications keep control of their URL and access rules.
    if ($php_enabled) {
        $vhost .= "    <Location />\n";
        $vhost .= "        <If \"-f '%{REQUEST_FILENAME}' && %{REQUEST_FILENAME} =~ m#\\.php$#\">\n";
        $vhost .= "            SetHandler \"proxy:unix:$siteSocket|fcgi://localhost/\"\n";
        $vhost .= "        </If>\n        <Else>\n            SetHandler default-handler\n        </Else>\n";
        $vhost .= "    </Location>\n";
        $vhost .= "    <LocationMatch ^/(phpmyadmin|dbadmin)(/|$)>\n";
        $vhost .= "        <If \"-f '%{REQUEST_FILENAME}' && %{REQUEST_FILENAME} =~ m#\\.php$#\">\n";
        $vhost .= "            SetHandler \"proxy:unix:$php_socket|fcgi://localhost/\"\n";
        $vhost .= "        </If>\n    </LocationMatch>\n";
    }
    
    // DB Manager access (Global Alias)
    $db_manager = defined('DB_MANAGER_DIR') ? DB_MANAGER_DIR : 'phpmyadmin';
    $vhost .= "    Alias /phpmyadmin /var/www/admin_panel/phpmyadmin\n";
    $vhost .= "    Alias /dbadmin /var/www/admin_panel/dbadmin\n";
    
    $manager_path = "/var/www/admin_panel/$db_manager";
    if (file_exists($manager_path)) {
        $vhost .= "    <Directory $manager_path>\n";
        $vhost .= "        Options -Indexes +FollowSymLinks\n";
        $vhost .= "        AllowOverride All\n";
        $vhost .= "        Require all granted\n";
        $vhost .= "        <FilesMatch \.php$>\n";
        $vhost .= "            SetHandler \"proxy:unix:$php_socket|fcgi://localhost/\"\n";
        $vhost .= "        </FilesMatch>\n";
        $vhost .= "    </Directory>\n\n";
    }
    
    if ($php_enabled) {
        $php_socket_site = $siteSocket;
        $vhost .= "    <FilesMatch \.php$>\n";
        $vhost .= "        SetHandler \"proxy:unix:$php_socket_site|fcgi://localhost/\"\n";
        $vhost .= "    </FilesMatch>\n";
        
        try {
            $stmt = $pdo->prepare("SELECT php_upload_max_filesize, php_post_max_size, php_max_file_uploads, php_memory_limit FROM sys_sites WHERE domain = ?");
            $stmt->execute([$domain]);
            $php_settings = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($php_settings) {
                // Solo valores tipo "20M", "512K", "1G" o bytes; evita romper la sintaxis del vhost
                $sizeOk = function ($v) { return is_string($v) && preg_match('/^\d+[KMG]?$/i', $v); };
                $directives = [];
                if ($sizeOk($php_settings['php_upload_max_filesize'] ?? null)) {
                    $directives[] = "upload_max_filesize=" . $php_settings['php_upload_max_filesize'];
                }
                if ($sizeOk($php_settings['php_post_max_size'] ?? null)) {
                    $directives[] = "post_max_size=" . $php_settings['php_post_max_size'];
                }
                if ((int)($php_settings['php_max_file_uploads'] ?? 0) > 0) {
                    $directives[] = "max_file_uploads=" . (int)$php_settings['php_max_file_uploads'];
                }
                $mem = $php_settings['php_memory_limit'] ?? null;
                if ($mem === '-1' || $sizeOk($mem)) {
                    $directives[] = "memory_limit=" . $mem;
                }

                if ($directives) {
                    // ProxyFCGISetEnvIf (no SetEnv): sus cadenas ap_expr convierten \n en salto de
                    // línea real, que es el separador que exige PHP-FPM para valores múltiples.
                    // Con SetEnv el \n llegaba literal y FPM aplicaba todo como un único valor
                    // corrupto de upload_max_filesize (interpretado como 8 bytes).
                    $vhost .= "    ProxyFCGISetEnvIf \"true\" PHP_ADMIN_VALUE \"" . implode('\\n', $directives) . "\"\n";
                }
            }
        } catch (Exception $e) {
            // Silencioso
        }
    }

    if ($is_ssl) {
        // Marcadores para que Certbot sepa dónde insertar sus directivas si se hiciera manualmente,
        // pero como usamos --apache certbot lo gestiona. 
        // No obstante, si nosotros REGENERAMOS el vhost SSL, debemos mantener las rutas a los certs.
        $certPath = "/etc/letsencrypt/live/$domain";
        if (file_exists("$certPath/fullchain.pem")) {
            $vhost .= "    SSLEngine on\n";
            $vhost .= "    SSLCertificateFile $certPath/fullchain.pem\n";
            $vhost .= "    SSLCertificateKeyFile $certPath/privkey.pem\n";
            $vhost .= "    Include /etc/letsencrypt/options-ssl-apache.conf\n";
        }
    }
    
    $vhost .= "</VirtualHost>";
    return $vhost;
}

// --- Inicialización de Tablas adicionales (Migraciones ligeras) ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_databases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        site_id INT NOT NULL,
        db_name VARCHAR(64) NOT NULL UNIQUE,
        db_user VARCHAR(32) NOT NULL,
        db_pass VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (site_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_settings (
        setting_key VARCHAR(64) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT IGNORE INTO sys_settings (setting_key, setting_value) VALUES ('backup_retention_days', '7')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_backups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        site_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        mega_path VARCHAR(255) NOT NULL,
        status ENUM('pending', 'completed', 'failed', 'restoring') DEFAULT 'completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (site_id)
    )");

    // Nuevas columnas para caché de sincronización DNS
    $pdo->exec("ALTER TABLE sys_dns_servers ADD COLUMN IF NOT EXISTS sync_status VARCHAR(50) DEFAULT 'ok'");
    $pdo->exec("ALTER TABLE sys_dns_servers ADD COLUMN IF NOT EXISTS last_sync_check DATETIME");
    $pdo->exec("ALTER TABLE sys_sites ADD COLUMN IF NOT EXISTS dns_sync_status VARCHAR(100) DEFAULT 'ok'");
    $pdo->exec("ALTER TABLE sys_sites ADD COLUMN IF NOT EXISTS dns_last_sync DATETIME");
    $pdo->exec("ALTER TABLE sys_sites ADD COLUMN IF NOT EXISTS backup_frequency ENUM('none', 'daily', 'weekly') DEFAULT 'none'");

    // Nuevas columnas para configuración PHP
    $pdo->exec("ALTER TABLE sys_sites ADD COLUMN IF NOT EXISTS php_upload_max_filesize VARCHAR(16) DEFAULT '20M'");
    $pdo->exec("ALTER TABLE sys_sites ADD COLUMN IF NOT EXISTS php_post_max_size VARCHAR(16) DEFAULT '25M'");
    $pdo->exec("ALTER TABLE sys_sites ADD COLUMN IF NOT EXISTS php_max_file_uploads INT DEFAULT 20");
    $pdo->exec("ALTER TABLE sys_sites ADD COLUMN IF NOT EXISTS php_memory_limit VARCHAR(16) DEFAULT '128M'");

    // Migración 1.4.33: el SetEnv roto dejaba upload_max_filesize en 8 bytes. Subir el
    // default antiguo (8M) a 20M y regenerar todos los vhosts con la directiva corregida.
    $fixDone = $pdo->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'migration_php_limits_20m'")->fetchColumn();
    if (!$fixDone) {
        $pdo->exec("UPDATE sys_sites SET php_upload_max_filesize = '20M' WHERE php_upload_max_filesize IS NULL OR php_upload_max_filesize IN ('8M', '8')");
        $stmtTask = $pdo->prepare("INSERT INTO sys_tasks (task_type, payload, status) VALUES ('SITE_UPDATE_PHP_SETTINGS', ?, 'pending')");
        foreach ($pdo->query("SELECT domain FROM sys_sites")->fetchAll(PDO::FETCH_COLUMN) as $migDomain) {
            $stmtTask->execute([json_encode(['domain' => $migDomain])]);
        }
        $pdo->exec("INSERT IGNORE INTO sys_settings (setting_key, setting_value) VALUES ('migration_php_limits_20m', '1')");
        echo "Migración PHP: límites por defecto a 20M y vhosts encolados para regenerar.\n";
    }

} catch (Exception $e) {
    // Silencioso si falla por permisos en una ejecución normal, aunque el motor corre como root
}

// Migrate existing sites once. Keep the marker absent until every site succeeds.
if (!$pdo->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'migration_site_isolation_v1'")->fetchColumn()) {
    $migrationSites = $pdo->query('SELECT domain, document_root FROM sys_sites')->fetchAll();
    // Validate the whole inventory before changing any site's ownership.
    $containers = [];
    foreach ($migrationSites as $existingSite) {
        $container = siteApplicationRoot($existingSite['domain'], $existingSite['document_root']);
        if (isset($containers[$container])) throw new RuntimeException('Dos sitios comparten un contenedor; revisar antes de migrar.');
        $containers[$container] = true;
    }
    $isolationBatch = true;
    foreach ($migrationSites as $existingSite) {
        isolateSite($existingSite['domain'], $existingSite['document_root'], $php_v);
        $pdo->prepare("INSERT INTO sys_tasks (task_type, payload) VALUES ('SITE_UPDATE_PHP_SETTINGS', ?)")->execute([json_encode(['domain' => $existingSite['domain']])]);
    }
    $isolationBatch = false;
    checkedCommand('/usr/bin/systemctl restart php' . $php_v . '-fpm');
    checkedCommand('/usr/bin/systemctl restart apache2');
    $pdo->exec("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('migration_site_isolation_v1', '1')");
}
// A previous process died while working. Never replay destructive work automatically.
$pdo->exec("UPDATE sys_tasks SET status = 'error', result_msg = 'Motor interrumpido. Revisar el resultado antes de reintentar.' WHERE status = 'running'");
// 1. Buscar tareas pendientes
$stmt = $pdo->prepare("SELECT * FROM sys_tasks WHERE status = 'pending' ORDER BY created_at ASC");
$stmt->execute();
$tasks = $stmt->fetchAll();

foreach ($tasks as $task) {
    $taskId = $task['id'];
    $payload = json_decode($task['payload'], true);
    $domain = $payload['domain'] ?? '';
    $success = false;
    $msg = '';

    // Marcar como en ejecución (excepto si queremos reintentar silenciosamente)
    $claim = $pdo->prepare("UPDATE sys_tasks SET status = 'running' WHERE id = ? AND status = 'pending'");
    $claim->execute([$taskId]);
    if ($claim->rowCount() !== 1) continue;

    $tmpDir = null; $tmpTar = null;
    try {
    switch ($task['task_type']) {
        case 'SITE_CREATE':
            $path = $payload['path'];
            $php = $payload['php_enabled'];
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
                isolateSite($domain, $path, $php_v);
            }

            // Copiar index.html inicial si el directorio está vacío (o si es el root principal /var/www/html)
            $indexPath = rtrim($path, '/') . '/index.html';
            $indexPhpPath = rtrim($path, '/') . '/index.php';
            $templatePath = __DIR__ . '/index.html.template';

            $isEmpty = !file_exists($indexPath) && !file_exists($indexPhpPath);
            $isMainRoot = ($path === '/var/www/html');

            if (($isEmpty || $isMainRoot) && file_exists($templatePath)) {
                // Si es root de apache por defecto, quitar el de Debian si existe
                if ($isMainRoot && file_exists($indexPath)) {
                    // Limpieza agresiva del index por defecto de Debian
                    unlink($indexPath);
                }
                
                if (!file_exists($indexPath) && !file_exists($indexPhpPath)) {
                    copy($templatePath, $indexPath);
                    chown($indexPath, siteUser($domain));
                    chgrp($indexPath, siteUser($domain));
                    chmod($indexPath, 0660);
                }
            }
            file_put_contents("/etc/apache2/sites-available/$domain.conf", generateVhost($domain, $path, $php, $php_v));
            $safeDomainConf = escapeshellarg("$domain.conf");
            
            // Desactivar sitios por defecto de Apache para evitar interferencias
            shell_exec("$cmd_a2dissite 000-default.conf 2>/dev/null");
            shell_exec("$cmd_a2dissite default-ssl.conf 2>/dev/null");
            
            // Validar sintaxis antes de recargar
            $check = shell_exec("/usr/sbin/apache2ctl -t 2>&1");
            if (strpos($check, "Syntax OK") !== false) {
                shell_exec("$cmd_a2ensite $safeDomainConf && $cmd_apache_reload");
                $success = true;
            } else {
                $msg = "Apache Config Error: " . trim($check);
                $pdo->prepare("UPDATE sys_tasks SET status = 'error', result_msg = ? WHERE id = ?")->execute([$msg, $taskId]);
                continue 2;
            }
            $pdo->prepare("UPDATE sys_sites SET status = 'active' WHERE domain = ?")->execute([$domain]);
            $msg = "Site $domain created.";
            $success = true;


            // --- Auto-encolar SSL ---
            $publicIP = getPublicIP();
            // hasDnsServers() cubre tanto sys_dns_servers como las constantes legacy.
            // Mirar solo las constantes daba falso en toda instalación nueva, donde el
            // DNS sí está gestionado pero desde la tabla.
            $dnsManaged = hasDnsServers();
            
            if ($dnsManaged || ($publicIP && checkExternalDNS($domain, $publicIP))) {
                // Verificar si ya hay una tarea SSL pendiente para este dominio para no duplicar.
                // Antes se comparaba con LIKE '%"domain": "..."%', con un espacio tras los
                // dos puntos. MariaDB guarda el JSON tal cual se inserto y json_encode no
                // pone ese espacio, asi que el patron no casaba nunca y la comprobacion
                // siempre daba "no hay tarea": cada SITE_CREATE encolaba un SSL de mas.
                $stmtSSL = $pdo->prepare("SELECT id FROM sys_tasks WHERE task_type = 'SSL_LETSENCRYPT' AND status = 'pending' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.domain')) = ?");
                $stmtSSL->execute([$domain]);
                if (!$stmtSSL->fetch()) {
                    $sslPayload = json_encode(['domain' => $domain]);
                    $pdo->prepare("INSERT INTO sys_tasks (task_type, payload, status) VALUES ('SSL_LETSENCRYPT', ?, 'pending')")->execute([$sslPayload]);
                    $msg .= " SSL task enqueued " . ($dnsManaged ? "(DNS API configured)" : "(DNS OK)");
                }
            }
            break;

        case 'SSL_LETSENCRYPT':
            $publicIP = getPublicIP();
            $dnsOk = checkExternalDNS($domain, $publicIP);
            // Comprobamos www explícitamente con DNS de Google
            $dnsWwwOk = checkExternalDNS("www." . $domain, $publicIP);
            $dnsPmaOk = checkExternalDNS("pma." . $domain, $publicIP);
            $dnsPmaFullOk = checkExternalDNS("phpmyadmin." . $domain, $publicIP);

            if (!$dnsOk) {
                // Controlar los intentos para evitar un bucle infinito
                $attempts = isset($payload['attempts']) ? (int)$payload['attempts'] : 0;
                $attempts++;
                
                if ($attempts >= 24) {
                    $pdo->prepare("UPDATE sys_tasks SET status = 'error', result_msg = 'DNS validation failed after 24 attempts. SSL aborted.' WHERE id = ?")->execute([$taskId]);
                    $pdo->prepare("UPDATE sys_sites SET status = 'active' WHERE domain = ?")->execute([$domain]); // Recuperar sitio si estaba pendiente de SSL
                    continue 2;
                }
                
                // Actualizar intentos en payload y volver a poner en pending
                $payload['attempts'] = $attempts;
                $newPayload = json_encode($payload);
                $pdo->prepare("UPDATE sys_tasks SET status = 'pending', payload = ?, result_msg = 'Waiting for DNS (Attempt $attempts/24): $domain -> $publicIP' WHERE id = ?")->execute([$newPayload, $taskId]);
                continue 2; // Saltar al siguiente item del foreach
            }

            // El A de www suele propagarse unos minutos despues del apex. Sin esta
            // espera el certificado se emitia solo para el dominio desnudo y la tarea
            // se cerraba con exito: nadie volvia a mirarlo nunca.
            if (!$dnsWwwOk) {
                $wwwWait = isset($payload['www_wait']) ? (int)$payload['www_wait'] : 0;
                if ($wwwWait < 10) {
                    $payload['www_wait'] = $wwwWait + 1;
                    $waitMsg = "Esperando DNS de www.$domain (intento " . ($wwwWait + 1) . "/10)";
                    $pdo->prepare("UPDATE sys_tasks SET status = 'pending', payload = ?, result_msg = ? WHERE id = ?")
                        ->execute([json_encode($payload), $waitMsg, $taskId]);
                    continue 2;
                }
                // Agotada la espera se emite igual: hay dominios que no tienen www a proposito.
            }

            $email = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : "admin@$domain";
            $domainsArg = "-d " . escapeshellarg($domain);
            
            // Subdominios adicionales si las DNS apuntan a nuestra IP
            if ($dnsWwwOk) $domainsArg .= " -d " . escapeshellarg("www.$domain");
            if ($dnsPmaOk) $domainsArg .= " -d " . escapeshellarg("pma.$domain");
            if ($dnsPmaFullOk) $domainsArg .= " -d " . escapeshellarg("phpmyadmin.$domain");
            
            // Usamos --keep-until-expiring y --expand para que si hay nuevos dominios se añadan, y si no, no se renueve innecesariamente
            // --cert-name fija el lineage a /etc/letsencrypt/live/$domain: sin el, un
            // reintento con distinto juego de nombres puede crear "$domain-0001" y el
            // resto del motor (que lee siempre live/$domain) se queda mirando el viejo.
            $cmd = "$cmd_certbot --apache $domainsArg --cert-name " . escapeshellarg($domain) .
                   " --non-interactive --agree-tos --email " . escapeshellarg($email) .
                   " --keep-until-expiring --expand 2>&1";
            exec($cmd, $output, $resultCode);

            // Verificación robusta: aunque certbot de un código de salida extraño, 
            // si el archivo existe, es que el SSL está operativo.
            if (file_exists("/etc/letsencrypt/live/$domain/fullchain.pem")) {
                $pdo->prepare("UPDATE sys_sites SET ssl_enabled = 1 WHERE domain = ?")->execute([$domain]);
                $msg = "SSL issued or already exists. Files verified.";
                $success = true;

                // --- Regenerar vhost SSL para incluir manejadores (PHP, etc) ---
                $siteData = $pdo->prepare("SELECT document_root, php_enabled FROM sys_sites WHERE domain = ?");
                $siteData->execute([$domain]);
                $site = $siteData->fetch();
                if ($site) {
                    file_put_contents("/etc/apache2/sites-available/$domain-le-ssl.conf", generateVhost($domain, $site['document_root'], $site['php_enabled'], $php_v, true));
                    $msg .= " SSL vhost file regenerated.";
                }

                // --- Compartir SSL con el panel de administración (Puerto 8080) ---
                // Solo si el dominio coincide con el del sitio #1 (el principal)
                $mainSite = $pdo->query("SELECT domain FROM sys_sites WHERE id = 1")->fetchColumn();
                if ($domain === $mainSite) {
                    $adminConf = "/etc/apache2/sites-available/000-admin.conf";
                    if (file_exists($adminConf)) {
                        $confContent = file_get_contents($adminConf);
                        // Añadir directivas SSL para 8080 (opcional, aunque ahora preferimos 8090) y definitivamente para 8090
                        if (strpos($confContent, "SSLEngine on") === false) {
                            $certPath = "/etc/letsencrypt/live/$domain";
                            $sslPart = "\n    SSLEngine on\n";
                            $sslPart .= "    SSLCertificateFile $certPath/fullchain.pem\n";
                            $sslPart .= "    SSLCertificateKeyFile $certPath/privkey.pem\n";
                            if (file_exists("/etc/letsencrypt/options-ssl-apache.conf")) {
                                $sslPart .= "    Include /etc/letsencrypt/options-ssl-apache.conf\n";
                            }
                            
                            // Insertar solo en el bloque VirtualHost de 8090
                            if (strpos($confContent, "<VirtualHost *:8090>") !== false) {
                                // Buscamos el bloque de 8090 y su cierre </VirtualHost> más cercano
                                $pattern = "/(<VirtualHost \*:8090>.*?)(<\/VirtualHost>)/s";
                                $newContent = preg_replace($pattern, "$1$sslPart$2", $confContent, 1);
                                if ($newContent) {
                                    $confContent = $newContent;
                                    file_put_contents($adminConf, $confContent);
                                    $msg .= " Admin panel SSL updated (Port 8090).";
                                }
                            }
                        }
                    }
                }
                shell_exec($cmd_apache_reload);
            }
            break;

        case 'SSL_ISSUE_WILDCARD':
            $email = defined('LETSENCRYPT_EMAIL') ? LETSENCRYPT_EMAIL : (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'admin@' . $domain);
            
            // Rutas a los hooks
            $authHook = "/var/www/admin_panel/src/engine/certbot_dns_auth.php";
            $cleanupHook = "/var/www/admin_panel/src/engine/certbot_dns_cleanup.php";
            
            if (!file_exists($authHook) || !file_exists($cleanupHook)) {
                $msg = "Error: Hooks de DNS no encontrados ($authHook).";
                break;
            }

            // Comando certbot para Wildcard vía DNS-01
            $cmd = "$cmd_certbot certonly --manual --preferred-challenges dns " .
                   "--manual-auth-hook " . escapeshellarg($authHook) . " " .
                   "--manual-cleanup-hook " . escapeshellarg($cleanupHook) . " " .
                   "-d " . escapeshellarg($domain) . " -d " . escapeshellarg("*.$domain") . " " .
                   "--non-interactive --agree-tos --email " . escapeshellarg($email) . " --manual-public-ip-logging-ok --keep-until-expiring 2>&1";
            
            exec($cmd, $output, $resultCode);

            if (file_exists("/etc/letsencrypt/live/$domain/fullchain.pem")) {
                $pdo->prepare("UPDATE sys_sites SET ssl_enabled = 1 WHERE domain = ?")->execute([$domain]);
                $msg = "Wildcard SSL issued successfully via DNS-01.";
                $success = true;
                
                // Regenerar vhost SSL
                $siteData = $pdo->prepare("SELECT document_root, php_enabled FROM sys_sites WHERE domain = ?");
                $siteData->execute([$domain]);
                $site = $siteData->fetch();
                if ($site) {
                    file_put_contents("/etc/apache2/sites-available/$domain-le-ssl.conf", generateVhost($domain, $site['document_root'], $site['php_enabled'], $php_v, true));
                }
                shell_exec($cmd_apache_reload);
            } else {
                $msg = "Certbot Wildcard Error: " . (isset($output) ? end($output) : "Unknown error");
            }
            break;

        case 'SITE_TOGGLE_PHP':
            $newValue = $payload['new_value'];
            $site = $pdo->prepare("SELECT * FROM sys_sites WHERE domain = ?");
            $site->execute([$domain]);
            $site = $site->fetch();
            
            if ($site) {
                // Actualizar vhost HTTP
                file_put_contents("/etc/apache2/sites-available/$domain.conf", generateVhost($domain, $site['document_root'], $newValue, $php_v));
                
                // Actualizar vhost SSL si existe
                $sslConf = "/etc/apache2/sites-available/$domain-le-ssl.conf";
                if (file_exists($sslConf)) {
                    file_put_contents($sslConf, generateVhost($domain, $site['document_root'], $newValue, $php_v, true));
                }
                
                shell_exec($cmd_apache_reload);
                $pdo->prepare("UPDATE sys_sites SET php_enabled = ? WHERE domain = ?")->execute([$newValue, $domain]);
                $msg = "PHP set to " . ($newValue ? 'ON' : 'OFF');
                $success = true;
            }
            break;

        case 'SITE_UPDATE_PHP_SETTINGS':
            $site = $pdo->prepare("SELECT * FROM sys_sites WHERE domain = ?");
            $site->execute([$domain]);
            $site = $site->fetch();
            
            if ($site) {
                // Actualizar vhost HTTP
                file_put_contents("/etc/apache2/sites-available/$domain.conf", generateVhost($domain, $site['document_root'], $site['php_enabled'], $php_v));
                
                // Actualizar vhost SSL si existe
                $sslConf = "/etc/apache2/sites-available/$domain-le-ssl.conf";
                if (file_exists($sslConf)) {
                    file_put_contents($sslConf, generateVhost($domain, $site['document_root'], $site['php_enabled'], $php_v, true));
                }
                
                shell_exec($cmd_apache_reload);
                $msg = "PHP settings updated for $domain.";
                $success = true;
            }
            break;

        case 'SITE_TOGGLE_STATUS':
            $newStatus = $payload['new_status'];
            
            // Protección: el sitio principal (id=1) no se puede desactivar
            $siteId = $payload['id'] ?? null;
            if ($siteId == 1 && $newStatus === 'inactive') {
                $msg = "Error: Site $domain (ID 1) cannot be disabled. Aborting.";
                $success = false;
                break;
            }

            $safeDomainConf = escapeshellarg("$domain.conf");
            $safeDomainSSLConf = escapeshellarg("$domain-le-ssl.conf");
            if ($newStatus === 'active') {
                shell_exec("$cmd_a2ensite $safeDomainConf");
                if (file_exists("/etc/apache2/sites-available/$domain-le-ssl.conf")) shell_exec("$cmd_a2ensite $safeDomainSSLConf");
                $msg = "Site $domain enabled.";
            } else {
                shell_exec("$cmd_a2dissite $safeDomainConf");
                shell_exec("$cmd_a2dissite $safeDomainSSLConf 2>/dev/null");
                $msg = "Site $domain disabled.";
            }
            shell_exec($cmd_apache_reload);
            $pdo->prepare("UPDATE sys_sites SET status = ? WHERE domain = ?")->execute([$newStatus, $domain]);
            $success = true;
            break;

        case 'SITE_DELETE':
            // Protección: el sitio principal (id=1) no se puede eliminar
            $siteId = $payload['id'] ?? null;
            if ($siteId == 1) {
                $msg = "Error: Site $domain (ID 1) cannot be deleted. Aborting.";
                $success = false;
                break;
            }

            $safeDomainConf = escapeshellarg("$domain.conf");
            $safeDomainSSLConf = escapeshellarg("$domain-le-ssl.conf");
            $safeDomainName = escapeshellarg($domain);
            shell_exec("$cmd_a2dissite $safeDomainConf 2>/dev/null");
            shell_exec("$cmd_a2dissite $safeDomainSSLConf 2>/dev/null");
            
            // 1. Revocar y eliminar certificados
            shell_exec("$cmd_certbot delete --cert-name $safeDomainName --non-interactive 2>/dev/null");
            
            // 2. Limpiar archivos de config
            @unlink("/etc/apache2/sites-available/$domain.conf");
            @unlink("/etc/apache2/sites-available/$domain-le-ssl.conf");
            shell_exec($cmd_apache_reload);
            
            // 3. Borrar directorio raíz
            $site = $pdo->prepare("SELECT document_root FROM sys_sites WHERE domain = ?");
            $site->execute([$domain]);
            $path = $site->fetchColumn();
            if ($path && strpos($path, '/var/www/vhosts/') === 0 && strlen($path) > 17) {
                shell_exec("/usr/bin/rm -rf " . escapeshellarg($path));
            }
            
            $pdo->prepare("DELETE FROM sys_sites WHERE domain = ?")->execute([$domain]);
            
            
            // 4. Limpiar bases de datos asociadas
            $dbs = $pdo->prepare("SELECT db_name, db_user FROM sys_databases WHERE site_id = ?");
            $dbs->execute([$siteId]);
            $associatedDbs = $dbs->fetchAll();
            
            $rootPass = trim(@file_get_contents("/root/.hosting_db_root"));
            $auth = $rootPass ? "-u root -p" . escapeshellarg($rootPass) : "-u root";
            
            foreach ($associatedDbs as $db) {
                $safeDbNameDel = str_replace('`', '``', $db['db_name']);
                $safeDbUserDel = str_replace("'", "''", $db['db_user']);
                shell_exec("mariadb $auth -e " . escapeshellarg("DROP DATABASE IF EXISTS `$safeDbNameDel`;"));
                shell_exec("mariadb $auth -e " . escapeshellarg("DROP USER IF EXISTS '$safeDbUserDel'@'127.0.0.1';"));
                shell_exec("mariadb $auth -e " . escapeshellarg("DROP USER IF EXISTS '$safeDbUserDel'@'localhost';"));
            }
            $pdo->prepare("DELETE FROM sys_databases WHERE site_id = ?")->execute([$siteId]);

            $msg = "Site $domain and its databases deleted completely.";
            $success = true;
            break;

        case 'DB_CREATE':
            $dbName = $payload['db_name'];
            $dbUser = $payload['db_user'];
            $dbPass = $payload['db_pass'];
            $siteId = $payload['site_id'];
            
            $rootPass = trim(@file_get_contents("/root/.hosting_db_root"));
            $auth = $rootPass ? "-u root -p" . escapeshellarg($rootPass) : "-u root";
            
            $safeDbName = str_replace('`', '``', $dbName);
            $safeDbUser = str_replace("'", "''", $dbUser);
            $safeDbPass = str_replace("'", "''", $dbPass);
            
            // 1. Crear DB
            shell_exec("mariadb $auth -e " . escapeshellarg("CREATE DATABASE IF NOT EXISTS `$safeDbName`;"));
            // 2. Crear Usuario y Permisos (usando Identificado por para compatibilidad)
            shell_exec("mariadb $auth -e " . escapeshellarg("CREATE USER IF NOT EXISTS '$safeDbUser'@'127.0.0.1' IDENTIFIED BY '$safeDbPass';"));
            shell_exec("mariadb $auth -e " . escapeshellarg("GRANT ALL PRIVILEGES ON `$safeDbName`.* TO '$safeDbUser'@'127.0.0.1';"));
            shell_exec("mariadb $auth -e " . escapeshellarg("CREATE USER IF NOT EXISTS '$safeDbUser'@'localhost' IDENTIFIED BY '$safeDbPass';"));
            shell_exec("mariadb $auth -e " . escapeshellarg("GRANT ALL PRIVILEGES ON `$safeDbName`.* TO '$safeDbUser'@'localhost';"));
            shell_exec("mariadb $auth -e " . escapeshellarg("FLUSH PRIVILEGES;"));
            
            $pdo->prepare("INSERT INTO sys_databases (site_id, db_name, db_user, db_pass) VALUES (?, ?, ?, ?)")
                ->execute([$siteId, $dbName, $dbUser, $dbPass]);
            
            $msg = "Database $dbName created and assigned to site ID $siteId.";
            $success = true;
            break;

        case 'DB_DELETE':
            $dbName = $payload['db_name'];
            $dbUser = $payload['db_user'];
            
            $rootPass = trim(@file_get_contents("/root/.hosting_db_root"));
            $auth = $rootPass ? "-u root -p" . escapeshellarg($rootPass) : "-u root";
            
            $safeDbName = str_replace('`', '``', $dbName);
            $safeDbUser = str_replace("'", "''", $dbUser);
            
            shell_exec("mariadb $auth -e " . escapeshellarg("DROP DATABASE IF EXISTS `$safeDbName`;"));
            shell_exec("mariadb $auth -e " . escapeshellarg("DROP USER IF EXISTS '$safeDbUser'@'127.0.0.1';"));
            shell_exec("mariadb $auth -e " . escapeshellarg("DROP USER IF EXISTS '$safeDbUser'@'localhost';"));
            shell_exec("mariadb $auth -e " . escapeshellarg("FLUSH PRIVILEGES;"));
            
            $pdo->prepare("DELETE FROM sys_databases WHERE db_name = ?")->execute([$dbName]);
            $msg = "Database $dbName deleted.";
            $success = true;
            break;

        case 'DB_CHANGE_PASSWORD':
            $dbUser = $payload['db_user'];
            $newPass = $payload['new_pass'];
            $dbName = $payload['db_name'];
            
            $rootPass = trim(@file_get_contents("/root/.hosting_db_root"));
            $auth = $rootPass ? "-u root -p" . escapeshellarg($rootPass) : "-u root";
            
            $safeDbUser = str_replace("'", "''", $dbUser);
            $safeNewPass = str_replace("'", "''", $newPass);
            
            shell_exec("mariadb $auth -e " . escapeshellarg("ALTER USER '$safeDbUser'@'127.0.0.1' IDENTIFIED BY '$safeNewPass';"));
            shell_exec("mariadb $auth -e " . escapeshellarg("ALTER USER '$safeDbUser'@'localhost' IDENTIFIED BY '$safeNewPass';"));
            shell_exec("mariadb $auth -e " . escapeshellarg("FLUSH PRIVILEGES;"));
            
            $pdo->prepare("UPDATE sys_databases SET db_pass = ? WHERE db_user = ? AND db_name = ?")->execute([$newPass, $dbUser, $dbName]);
            $msg = "Password changed for database user $dbUser.";
            $success = true;
            break;

        case 'MEGA_LOGIN':
            $email = escapeshellarg($payload['email']);
            $password = escapeshellarg($payload['password']);
            
            exec("/usr/bin/mega-login $email $password 2>&1", $output, $resultCode);
            $outStr = implode(" ", $output);
            if ($resultCode === 0 || strpos($outStr, 'Already logged in') !== false || strpos($outStr, 'Login completed') !== false) {
                $pdo->prepare("REPLACE INTO sys_settings (setting_key, setting_value) VALUES ('mega_email', ?)")->execute([$payload['email']]);
                $pdo->prepare("REPLACE INTO sys_settings (setting_key, setting_value) VALUES ('mega_status', 'logged_in')")->execute();
                $msg = "MEGA account linked successfully.";
                $success = true;
            } else {
                $msg = "MEGA login failed: " . $outStr;
                $success = false;
            }
            break;

        case 'MEGA_LOGOUT':
            exec("/usr/bin/mega-logout 2>&1", $output, $resultCode);
            $pdo->prepare("DELETE FROM sys_settings WHERE setting_key = 'mega_email'")->execute();
            $pdo->prepare("DELETE FROM sys_settings WHERE setting_key = 'mega_status'")->execute();
            $msg = "MEGA account logged out.";
            $success = true;
            break;

        case 'MEGA_SYNC_BACKUPS':
            // 1. Escanear la carpeta raíz de backups en MEGA
            exec("/usr/bin/mega-ls /Backups/ 2>&1", $outMegaRoot, $resMegaRoot);
            
            $megaDomains = [];
            if ($resMegaRoot === 0) {
                foreach ($outMegaRoot as $line) {
                    $domain = trim($line);
                    // Validar que parece un dominio (contiene punto, no contiene espacios)
                    if (strpos($domain, '.') !== false && strpos($domain, ' ') === false) {
                        $megaDomains[] = $domain;
                    }
                }
            }
            
            $added = 0;
            $kept = 0;
            $removed = 0;
            $sitesRecreated = 0;
            
            foreach ($megaDomains as $domain) {
                // Verificar si existe el sitio localmente
                $siteChk = $pdo->prepare("SELECT id FROM sys_sites WHERE domain = ?");
                $siteChk->execute([$domain]);
                $siteId = $siteChk->fetchColumn();
                
                if (!$siteId) {
                    // El sitio está en MEGA pero no existe localmente -> Auto-Recuperación
                    $docRoot = "/var/www/vhosts/" . $domain;
                    $pdo->prepare("INSERT INTO sys_sites (domain, document_root, php_enabled, status) VALUES (?, ?, 1, 'pending')")
                        ->execute([$domain, $docRoot]);
                    $siteId = $pdo->lastInsertId();
                    
                    // Encolar su creación
                    $payloadCreate = json_encode(['domain' => $domain, 'path' => $docRoot, 'php_enabled' => 1]);
                    $pdo->prepare("INSERT INTO sys_tasks (task_type, payload, status) VALUES ('SITE_CREATE', ?, 'pending')")
                        ->execute([$payloadCreate]);
                        
                    $sitesRecreated++;
                    echo "Auto-recreating missing site from MEGA: $domain (ID: $siteId)\n";
                }
                
                // Sincronizar Backups para este dominio
                $megaPath = "/Backups/{$domain}";
                exec("/usr/bin/mega-ls " . escapeshellarg($megaPath) . " 2>&1", $outMega, $resMega);
                
                $megaFiles = [];
                if ($resMega === 0) {
                    foreach ($outMega as $line) {
                        $line = trim($line);
                        if (strpos($line, 'backup_') === 0 && strpos($line, '.tar.gz') !== false) {
                            $megaFiles[] = $line;
                        }
                    }
                }
                
                // Limpiar salida para la siguiente iteración
                $outMega = []; 
                
                $localBackups = $pdo->prepare("SELECT id, filename FROM sys_backups WHERE site_id = ?");
                $localBackups->execute([$siteId]);
                $local = $localBackups->fetchAll(PDO::FETCH_ASSOC);
                
                $localMap = [];
                foreach ($local as $l) {
                    $localMap[$l['filename']] = $l['id'];
                }
                
                $foundMap = [];
                foreach ($megaFiles as $filename) {
                    $foundMap[$filename] = true;
                    if (!isset($localMap[$filename])) {
                        $dateStr = date('Y-m-d H:i:s');
                        if (preg_match('/backup_.*_(\d{8})_(\d{6})\.tar\.gz/', $filename, $matches)) {
                            $d = $matches[1];
                            $t = $matches[2];
                            $dateStr = substr($d,0,4).'-'.substr($d,4,2).'-'.substr($d,6,2).' '.substr($t,0,2).':'.substr($t,2,2).':'.substr($t,4,2);
                        }
                        
                        $pdo->prepare("INSERT INTO sys_backups (site_id, filename, mega_path, status, created_at) VALUES (?, ?, ?, 'completed', ?)")
                            ->execute([$siteId, $filename, $megaPath, $dateStr]);
                        $added++;
                    } else {
                        $kept++;
                    }
                }
                
                // Remove local DB records that are no longer in MEGA
                foreach ($localMap as $filename => $bId) {
                    if (!isset($foundMap[$filename])) {
                        $pdo->prepare("DELETE FROM sys_backups WHERE id = ?")->execute([$bId]);
                        $removed++;
                    }
                }
            }
            
            $msg = "Sync completado. Sitios auto-recuperados: $sitesRecreated. Copias añadidas: $added, Alcanzadas: $kept, Eliminadas locales: $removed.";
            $success = true;
            break;

        case 'SITE_BACKUP':
            $siteId = $payload['site_id'];
            $site = $pdo->prepare("SELECT domain, document_root FROM sys_sites WHERE id = ?");
            $site->execute([$siteId]);
            $siteData = $site->fetch();
            
            if (!$siteData) {
                $msg = "Site not found for backup.";
                $success = false;
                break;
            }
            
            $domain = $siteData['domain'];
            $docRoot = $siteData['document_root'];
            $applicationRoot = siteApplicationRoot($domain, $docRoot);
            
            // Obtener TODAS las bases de datos asociadas al sitio
            $stmtDbs = $pdo->prepare("SELECT db_name, db_user, db_pass FROM sys_databases WHERE site_id = ?");
            $stmtDbs->execute([$siteId]);
            $associatedDbs = $stmtDbs->fetchAll();
            
            $timestamp = date('Ymd_His');
            $backupFile = "backup_{$domain}_{$timestamp}.tar.gz";
            $tmpDir = privateWorkdir();
            $tmpTar = $tmpDir . ".tar.gz";
            
            $config = [
                'domain' => $domain,
                'document_root' => $docRoot,
                'application_root' => $applicationRoot,
                'backup_format' => 2,
                'timestamp' => $timestamp,
                'databases' => [] // Nuevo array para múltiples DBs
            ];
            
            $dbFiles = [];
            if (!empty($associatedDbs)) {
                $dbDir = "$tmpDir/databases";
                mkdir($dbDir, 0755, true);
                
                $rootPass = trim(@file_get_contents("/root/.hosting_db_root"));
                $auth = $rootPass ? "-u root -p" . escapeshellarg($rootPass) : "-u root";
                $dumpBin = file_exists('/usr/bin/mariadb-dump') ? '/usr/bin/mariadb-dump' : '/usr/bin/mysqldump';

                foreach ($associatedDbs as $db) {
                    $dbNameArg = escapeshellarg($db['db_name']);
                    $safeFile = "{$db['db_name']}.sql.gz";
                    dumpDatabase($dumpBin, $auth, $db['db_name'], "$dbDir/$safeFile");
                    
                    $config['databases'][] = [
                        'db_name' => $db['db_name'],
                        'db_user' => $db['db_user'],
                        'db_pass' => $db['db_pass']
                    ];
                    $dbFiles[] = "databases/{$safeFile}";
                }
            }
            
            file_put_contents("$tmpDir/config.json", json_encode($config, JSON_PRETTY_PRINT));
            
            // Preserve links without reading arbitrary targets as root.
            copyApplicationTree($applicationRoot, $tmpDir . '/webroot');
            
            $itemsToTar = "config.json webroot";
            if (!empty($dbFiles)) {
                $itemsToTar .= " databases/";
            }
            
            $tarCmd = "tar -czf " . escapeshellarg($tmpTar) . " -C " . escapeshellarg($tmpDir) . " $itemsToTar";
            checkedCommand($tarCmd);
            checkedCommand('tar -tzf ' . escapeshellarg($tmpTar) . ' > /dev/null');
            
            $megaPath = "/Backups/{$domain}";
            exec("/usr/bin/mega-mkdir -p " . escapeshellarg($megaPath) . " 2>&1");
            exec("/usr/bin/mega-put " . escapeshellarg($tmpTar) . " " . escapeshellarg($megaPath) . " 2>&1", $outMega, $resMega);
            
            if ($resMega === 0) {
                $pdo->prepare("INSERT INTO sys_backups (site_id, filename, mega_path, status) VALUES (?, ?, ?, 'completed')")
                    ->execute([$siteId, $backupFile, $megaPath]);
                $msg = "Backup $backupFile uploaded completely (included " . count($associatedDbs) . " DBs).";
                $success = true;
            } else {
                $msg = "MEGA upload failed: " . implode(" ", $outMega);
                $success = false;
            }
            
            shell_exec("rm -rf " . escapeshellarg($tmpDir));
            @unlink($tmpTar);
            
            // Retención: never expire the last good copies after a failed upload.
            if (!$success) break;
            $retentionVal = $pdo->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'backup_retention_days'")->fetchColumn();
            $retention = max(1, min(3650, is_numeric($retentionVal) ? (int)$retentionVal : 7));

            $oldBackups = $pdo->prepare("SELECT id, filename, mega_path FROM sys_backups WHERE site_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $oldBackups->execute([$siteId, $retention]);
            foreach($oldBackups->fetchAll() as $old) {
                $rmPath = escapeshellarg($old['mega_path'] . '/' . $old['filename']);
                exec("/usr/bin/mega-rm $rmPath 2>&1", $rmOutput, $rmCode);
                if ($rmCode !== 0) continue;
                $pdo->prepare("DELETE FROM sys_backups WHERE id = ?")->execute([$old['id']]);
            }
            break;

        case 'SITE_RESTORE':
            $backupId = $payload['backup_id'];
            $backup = $pdo->prepare("SELECT * FROM sys_backups WHERE id = ?");
            $backup->execute([$backupId]);
            $backupData = $backup->fetch();
            
            if (!$backupData) {
                $msg = "Backup record not found.";
                $success = false;
                break;
            }
            
            $siteId = $backupData['site_id'];
            $site = $pdo->prepare("SELECT domain, document_root FROM sys_sites WHERE id = ?");
            $site->execute([$siteId]);
            $siteData = $site->fetch();
            
            if (!$siteData) {
                $msg = "Target site associated with backup not found.";
                $success = false;
                break;
            }
            
            $domain = $siteData['domain'];
            $docRoot = escapeshellarg($siteData['document_root']);
            $remoteFile = escapeshellarg($backupData['mega_path'] . '/' . $backupData['filename']);
            if (basename($backupData['filename']) !== $backupData['filename']) throw new RuntimeException('Nombre de copia inválido.');
            $tmpDir = privateWorkdir();
            $tmpTar = $tmpDir . "/" . $backupData['filename'];
            
            exec("/usr/bin/mega-get $remoteFile " . escapeshellarg($tmpDir . "/") . " 2>&1", $outMega, $resMega);
            
            if ($resMega !== 0 || !file_exists($tmpTar)) {
                $msg = "Failed to download backup from MEGA: " . implode(" ", $outMega);
                $success = false;
                break;
            }
            
            checkedCommand('python3 -c ' . escapeshellarg('import sys,tarfile; t=tarfile.open(sys.argv[1]); t.extractall(sys.argv[2], filter="data")') . ' ' . escapeshellarg($tmpTar) . ' ' . escapeshellarg($tmpDir));
            
            $metadata = is_file("$tmpDir/config.json") ? json_decode(file_get_contents("$tmpDir/config.json"), true) : [];
            $format = (int)($metadata['backup_format'] ?? 1);
            if (!in_array($format, [1, 2], true)) throw new RuntimeException('Formato de copia no compatible.');
            $applicationRoot = siteApplicationRoot($domain, $siteData['document_root']);
            $restoreRoot = $format === 2 ? $applicationRoot : $siteData['document_root'];
            $docRoot = escapeshellarg($restoreRoot);

            $rootPass = trim(@file_get_contents("/root/.hosting_db_root"));
            $auth = $rootPass ? "-u root -p" . escapeshellarg($rootPass) : "-u root";
            
            // 1. Restauración Multi-DB (Nuevo Formato)
            if (is_dir("$tmpDir/databases")) {
                $configPath = "$tmpDir/config.json";
                if (file_exists($configPath)) {
                    $configData = json_decode(file_get_contents($configPath), true);
                    $dbsToRestore = $configData['databases'] ?? [];
                    
                    foreach ($dbsToRestore as $db) {
                        $dbName = $db['db_name'];
                        $dbUser = $db['db_user'];
                        $dbPass = $db['db_pass'];
                        $dbFile = "$tmpDir/databases/{$dbName}.sql.gz";
                        
                        if (file_exists($dbFile)) {
                            if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $dbName) || !preg_match('/^[a-zA-Z0-9_]{1,32}$/D', $dbUser)) throw new RuntimeException('Identificador de base de datos inválido.');
                            $owner = $pdo->prepare('SELECT site_id FROM sys_databases WHERE db_name = ? OR db_user = ?');
                            $owner->execute([$dbName, $dbUser]);
                            foreach ($owner->fetchAll() as $row) if ((int)$row['site_id'] !== (int)$siteId) throw new RuntimeException('La base de datos pertenece a otro sitio.');
                            $sqlPass = "'" . str_replace(["\\", "'"], ["\\\\", "''"], $dbPass) . "'";
                            $sql = "CREATE DATABASE IF NOT EXISTS `$dbName`; ";
                            foreach (['localhost', '127.0.0.1'] as $dbHost) {
                                $sql .= "CREATE USER IF NOT EXISTS '$dbUser'@'$dbHost' IDENTIFIED BY $sqlPass; ";
                                $sql .= "GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'$dbHost'; ";
                            }
                            checkedCommand("mariadb $auth -e " . escapeshellarg($sql));
                            $restoreSql = $tmpDir . '/restore.sql';
                            checkedCommand('gzip -dc -- ' . escapeshellarg($dbFile) . ' > ' . escapeshellarg($restoreSql));
                            checkedCommand("mariadb $auth " . escapeshellarg($dbName) . ' < ' . escapeshellarg($restoreSql));
                            unlink($restoreSql);

                            // Asegurar que sys_databases esté sincronizado localmente
                            $stmtCheck = $pdo->prepare("SELECT id FROM sys_databases WHERE db_name = ?");
                            $stmtCheck->execute([$dbName]);
                            if (!$stmtCheck->fetch()) {
                                $pdo->prepare("INSERT INTO sys_databases (site_id, db_name, db_user, db_pass) VALUES (?, ?, ?, ?)")
                                    ->execute([$siteId, $dbName, $dbUser, $dbPass]);
                            } else {
                                $pdo->prepare("UPDATE sys_databases SET site_id = ?, db_user = ?, db_pass = ? WHERE db_name = ?")
                                    ->execute([$siteId, $dbUser, $dbPass, $dbName]);
                            }
                        }
                    }
                }
            } 
            // 2. Restauración Legacy (Un solo archivo database.sql.gz en la raíz)
            elseif (file_exists("$tmpDir/database.sql.gz")) {
                $dbs = $pdo->prepare("SELECT db_name FROM sys_databases WHERE site_id = ?");
                $dbs->execute([$siteId]);
                $dbData = $dbs->fetch();
                if ($dbData) {
                    $dbNameArg = escapeshellarg($dbData['db_name']);
                    shell_exec("mariadb $auth -e \"DROP DATABASE IF EXISTS $dbNameArg; CREATE DATABASE $dbNameArg;\"");
                    shell_exec("zcat " . escapeshellarg("$tmpDir/database.sql.gz") . " | mariadb $auth $dbNameArg");
                }
            }
            
            if (is_dir("$tmpDir/webroot")) {
                // Remove existing files and copy new ones
                if (is_link($restoreRoot)) throw new RuntimeException('La raíz no puede ser un enlace.');
                checkedCommand("find $docRoot -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +");
                checkedCommand("cp -a " . escapeshellarg("$tmpDir/webroot/.") . " $docRoot/");
                isolateSite($domain, $siteData['document_root'], $php_v);
            }
            
            shell_exec("rm -rf " . escapeshellarg($tmpDir));
            @unlink($tmpTar);
            
            $msg = "Site successfully restored from MEGA.";
            $success = true;
            break;

        case 'SITE_EXPORT':
            $siteId = $payload['site_id'];
            $token = $payload['token'];
            
            $site = $pdo->prepare("SELECT domain, document_root FROM sys_sites WHERE id = ?");
            $site->execute([$siteId]);
            $siteData = $site->fetch();
            
            if (!$siteData) {
                $msg = "Site not found for export.";
                $success = false;
                break;
            }
            
            $domain = $siteData['domain'];
            $docRoot = $siteData['document_root'];
            $applicationRoot = siteApplicationRoot($domain, $docRoot);
            
            // 1. Crear directorio de descargas de forma segura si no existe
            $downloadsDir = "/var/lib/lightweight-hosting/exports";
            if (!is_dir($downloadsDir)) {
                mkdir($downloadsDir, 0700, true);
                shell_exec("/usr/bin/chown www-data:www-data " . escapeshellarg($downloadsDir));
            }
            
            // Limpiar exportaciones huérfanas de más de 1 hora
            $now = time();
            foreach (glob("$downloadsDir/export_*_*.tar.gz") as $oldFile) {
                if (($now - filemtime($oldFile)) > 3600) {
                    @unlink($oldFile);
                }
            }
            
            // 2. Obtener TODAS las bases de datos asociadas al sitio
            $stmtDbs = $pdo->prepare("SELECT db_name, db_user, db_pass FROM sys_databases WHERE site_id = ?");
            $stmtDbs->execute([$siteId]);
            $associatedDbs = $stmtDbs->fetchAll();
            
            $timestamp = date('Ymd_His');
            $exportFile = "export_{$domain}_{$token}.tar.gz";
            $tmpDir = privateWorkdir();
            $tmpTar = "$downloadsDir/$exportFile";
            
            $dbFiles = [];
            if (!empty($associatedDbs)) {
                $dbDir = "$tmpDir/databases";
                mkdir($dbDir, 0755, true);
                
                $rootPass = trim(@file_get_contents("/root/.hosting_db_root"));
                $auth = $rootPass ? "-u root -p" . escapeshellarg($rootPass) : "-u root";
                $dumpBin = file_exists('/usr/bin/mariadb-dump') ? '/usr/bin/mariadb-dump' : '/usr/bin/mysqldump';
                
                foreach ($associatedDbs as $db) {
                    $dbNameArg = escapeshellarg($db['db_name']);
                    $safeFile = "{$db['db_name']}.sql.gz";
                    dumpDatabase($dumpBin, $auth, $db['db_name'], "$dbDir/$safeFile");
                    $dbFiles[] = "databases/{$safeFile}";
                }
            }
            
            // Preserve links without reading arbitrary targets as root.
            copyApplicationTree($applicationRoot, $tmpDir . '/webroot');
            
            $itemsToTar = "webroot";
            if (!empty($dbFiles)) {
                $itemsToTar .= " databases/";
            }
            
            $tarCmd = "tar -czf " . escapeshellarg($tmpTar) . " -C " . escapeshellarg($tmpDir) . " $itemsToTar";
            checkedCommand($tarCmd);
            checkedCommand('tar -tzf ' . escapeshellarg($tmpTar) . ' > /dev/null');
            shell_exec("/usr/bin/chown www-data:www-data " . escapeshellarg($tmpTar));
            
            shell_exec("rm -rf " . escapeshellarg($tmpDir));
            
            if (file_exists($tmpTar) && filesize($tmpTar) > 0) {
                $msg = "Site and databases exported successfully to $exportFile.";
                $success = true;
            } else {
                $msg = "Failed to create compressed export archive.";
                $success = false;
            }
            break;

        case 'SYSTEM_UPDATE':
            $msg = 'Iniciando actualización del sistema desde GitHub...';
            $tempInstall = tempnam(sys_get_temp_dir(), "lwh-update-");
            $repoRaw = "https://raw.githubusercontent.com/m4rg4rit4/Lightweight-Hosting/main";
            
            // 1. Descargar el install.sh actualizado
            $downloadCmd = "curl -fsSL " . escapeshellarg("$repoRaw/install.sh") . " -o " . escapeshellarg($tempInstall);
            exec($downloadCmd, $downloadOutput, $downloadCode);
            
            if ($downloadCode !== 0 || !file_exists($tempInstall)) {
                $msg = "Error al descargar install.sh desde GitHub. Código: $downloadCode";
                $success = false;
                break;
            }
            
            // 2. Ejecutar la instalación / actualización de forma no interactiva, incluyendo apt-get update & upgrade
            $updateCmd = "export DEBIAN_FRONTEND=noninteractive LWH_ENGINE_UPDATE=1 && bash " . escapeshellarg($tempInstall) . " /update /silent 2>&1";
            exec($updateCmd, $updateOutput, $updateCode);
            $outStr = implode("\n", $updateOutput);
            
            // Limpieza del archivo temporal
            @unlink($tempInstall);
            
            if ($updateCode === 0) {
                $msg = "Actualización completada con éxito desde GitHub (incluyendo apt-get update/upgrade).";
                $success = true;
            } else {
                $msg = "Error durante la ejecución de install.sh. Código de salida: $updateCode. Detalle: " . substr($outStr, -500);
                $success = false;
            }
            break;

        default:
            $msg = "Task type not implemented: " . $task['task_type'];
            break;
    }

    } catch (Throwable $e) {
        $success = false;
        $msg = 'Error: ' . $e->getMessage();
    } finally {
        if ($tmpDir && is_dir($tmpDir)) exec('rm -rf -- ' . escapeshellarg($tmpDir));
        if (!$success && $tmpTar && is_file($tmpTar)) unlink($tmpTar);
    }
    $status = $success ? 'success' : 'error';
    $pdo->prepare("UPDATE sys_tasks SET status = ?, result_msg = ? WHERE id = ?")->execute([$status, $msg, $taskId]);
    echo "Task $taskId ($domain): $status - $msg\n";
}

// --- Publicación DNS: tareas persistentes por nodo, en orden y sin descartes ---
try {
    dnsOutboxProcess($pdo, 50);
} catch (Exception $e) {
    echo "Outbox: error procesando la cola de reenvío: " . $e->getMessage() . "\n";
}

// --- Mantenimiento Periódico: Sincronización DNS (Cada 5 minutos) ---
$lastDnsCheck = $pdo->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'last_global_dns_sync_check'")->fetchColumn();
if (!$lastDnsCheck || (time() - strtotime($lastDnsCheck)) > 300) {
    echo "Iniciando comprobación periódica de sincronización DNS...\n";
    
    $dnsServers = getDnsServers();
    if (count($dnsServers) > 1) {
        $primary = $dnsServers[0];
        
        // 1. Estado de zonas de TODOS los servidores.
        //
        // Se compara records_hash, la huella de contenido que calcula la API. Antes se
        // comparaba updated_at, que no sirve: el motor nunca hace UPDATE sobre
        // sys_dns_zones, así que es la fecha de creación, y una zona se crea en el
        // secundario más tarde que en el primario. Los timestamps no coincidían nunca,
        // se entraba al "deep check" de todos los sitios cada 5 minutos, y aun así se
        // comparaba solo name|type|content: un TTL o una prioridad de MX distinta entre
        // nodos no se detectaba jamás. El hash incluye ttl y priority.
        $srvZones = [];
        $hashesAvailable = true;
        foreach ($dnsServers as $srv) {
            $res = dnsApiRequestOnServer($srv['url'], '/api-dns/zones', 'GET', null, $srv['token']);
            if ($res['code'] === 200) {
                $data = json_decode($res['response'], true);
                $raw = $data['zones'] ?? $data['data'] ?? [];
                $srvZones[$srv['id']] = [];
                foreach ($raw as $z) {
                    if (!array_key_exists('records_hash', $z)) $hashesAvailable = false;
                    $srvZones[$srv['id']][$z['domain']] = $z['records_hash'] ?? null;
                }
            } else {
                $srvZones[$srv['id']] = null; // Inaccesible
            }
        }

        $primaryZones = $srvZones[$primary['id']] ?? [];

        if (!$hashesAvailable) {
            echo "Aviso: algún nodo DNS aún no reporta records_hash (binario antiguo). Solo se comprobará el conjunto de zonas.\n";
        }

        // 2. Actualizar estado de los servidores secundarios (Zonas globales)
        foreach ($dnsServers as $idx => $srv) {
            if ($idx === 0) continue;
            
            $srvStatus = 'ok';
            $srvMsg = '';
            
            if ($srvZones[$srv['id']] === null) {
                $srvStatus = 'error';
                $srvMsg = "Inaccesible";
            } else {
                $otherZones = $srvZones[$srv['id']];
                if (count($primaryZones) !== count($otherZones) || !empty(array_diff_key($primaryZones, $otherZones)) || !empty(array_diff_key($otherZones, $primaryZones))) {
                    $srvStatus = 'warning';
                    $srvMsg = "Desincronizado (Zonas)";
                }
            }
            
            $pdo->prepare("UPDATE sys_dns_servers SET sync_status = ?, last_sync_check = NOW() WHERE id = ?")
                ->execute([$srvStatus, $srv['id']]);
        }

        // 3. Verificar cada sitio comparando la huella de contenido entre nodos.
        // Ya no hace falta ningún "deep check": los hashes vienen en la respuesta que
        // acabamos de pedir, así que esto no cuesta ni una petición extra.
        $sites = $pdo->query("SELECT id, domain, dns_sync_status, dns_last_sync FROM sys_sites WHERE status = 'active'")->fetchAll();
        $primaryZoneNames = array_keys($primaryZones);

        foreach ($sites as $site) {
            // Un sitio casi nunca es una zona: 'www.example.com' vive dentro de la zona
            // 'example.com'. Cruzando por nombre exacto, la mayoría de los
            // sitios no se comprobaba nunca y su desincronización pasaba desapercibida.
            $d = findLongestZoneSuffix($site['domain'], $primaryZoneNames);
            if ($d === null) continue;

            $currentStatus = 'ok';

            if ($hashesAvailable) {
                $primaryHash = $primaryZones[$d];

                foreach ($dnsServers as $idx => $srv) {
                    if ($idx === 0) continue;

                    if ($srvZones[$srv['id']] === null) {
                        $currentStatus = 'error_connection';
                        break;
                    }
                    if (!array_key_exists($d, $srvZones[$srv['id']])) {
                        $currentStatus = 'desync'; // la zona ni siquiera existe en este nodo
                        break;
                    }
                    if ($srvZones[$srv['id']][$d] !== $primaryHash) {
                        $currentStatus = 'desync';
                        break;
                    }
                }
            }

            // Solo actualizar si el estado cambia o han pasado más de 24h desde el último check
            if ($currentStatus !== $site['dns_sync_status'] || strtotime($site['dns_last_sync'] ?? '0') < (time() - 86400)) {
                $pdo->prepare("UPDATE sys_sites SET dns_sync_status = ?, dns_last_sync = NOW() WHERE id = ?")
                    ->execute([$currentStatus, $site['id']]);
            }
        }
    }

    
    $pdo->prepare("REPLACE INTO sys_settings (setting_key, setting_value) VALUES ('last_global_dns_sync_check', NOW())")->execute();
    echo "Mantenimiento DNS completado.\n";
}

// --- Mantenimiento Periodico: Auditoria de nombres del certificado (cada 24h) ---
// Un certificado se queda "cojo" (tipicamente sin www) cuando el DNS de ese subdominio
// aun no habia propagado al emitirlo: la tarea se cerraba con exito y nadie volvia a
// revisarlo. Aqui se detecta y se reencola el SSL; el comando ya lleva --expand, asi
// que certbot amplia el certificado existente sin esperar a la renovacion.
// Cadencia diaria a proposito: un intento al dia por dominio no acerca a los limites
// de Let's Encrypt aunque la validacion falle de forma persistente.
$lastSslAudit = $pdo->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'last_ssl_san_audit'")->fetchColumn();
if (!$lastSslAudit || (time() - strtotime($lastSslAudit)) > 86400) {
    echo "Iniciando auditoria de nombres en certificados SSL...\n";
    $auditIP = getPublicIP();

    if ($auditIP) {
        $sslSites = $pdo->query("SELECT domain FROM sys_sites WHERE status = 'active' AND ssl_enabled = 1")->fetchAll();
        foreach ($sslSites as $sslSite) {
            $auditDomain = $sslSite['domain'];
            $missing = getMissingCertNames($auditDomain, $auditIP);
            if (empty($missing)) continue;

            // No duplicar si ya hay SSL en cola o ejecutandose para este dominio.
            // JSON_EXTRACT y no LIKE: MariaDB guarda el JSON tal cual se inserto, asi
            // que un LIKE con el formato equivocado no casaria nunca.
            $dup = $pdo->prepare("SELECT id FROM sys_tasks WHERE task_type IN ('SSL_LETSENCRYPT', 'SSL_ISSUE_WILDCARD') AND status IN ('pending', 'running') AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.domain')) = ?");
            $dup->execute([$auditDomain]);
            if ($dup->fetch()) continue;

            $pdo->prepare("INSERT INTO sys_tasks (task_type, payload, status) VALUES ('SSL_LETSENCRYPT', ?, 'pending')")
                ->execute([json_encode(['domain' => $auditDomain])]);
            echo "  $auditDomain: faltan en el certificado " . implode(', ', $missing) . " -> SSL reencolado.\n";
        }
    } else {
        echo "Aviso: no se pudo determinar la IP publica, auditoria SSL omitida.\n";
    }

    $pdo->prepare("REPLACE INTO sys_settings (setting_key, setting_value) VALUES ('last_ssl_san_audit', NOW())")->execute();
    echo "Auditoria SSL completada.\n";
}
?>

