<?php
/**
 * Hosting Custom - Task Processor (ISPConfig Style)
 * Runs as root via cron
 */

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
    $found_sockets = glob("/run/php/php*-fpm.sock");
    if (!empty($found_sockets)) {
        $php_socket = $found_sockets[0];
        // Extraer versión del socket si es posible
        if (preg_match('/php([\d\.]+)-fpm/', $php_socket, $matches)) {
            $php_v = $matches[1];
        }
    }
}

require '/var/www/admin_panel/config.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// --- Funciones Auxiliares ---

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

function generateVhost($domain, $document_root, $php_enabled, $php_v, $is_ssl = false) {
    $port = $is_ssl ? 443 : 80;
    $vhost = "<VirtualHost *:$port>\n";
    $vhost .= "    ServerName $domain\n";
    $vhost .= "    ServerAlias www.$domain pma.$domain phpmyadmin.$domain\n";
    $vhost .= "    DocumentRoot $document_root\n";
    $vhost .= "    DirectoryIndex index.php index.html\n";
    $vhost .= "    ErrorLog \${APACHE_LOG_DIR}/" . ($is_ssl ? "ssl_" : "") . "{$domain}_error.log\n";
    $vhost .= "    CustomLog \${APACHE_LOG_DIR}/" . ($is_ssl ? "ssl_" : "") . "{$domain}_access.log combined\n\n";
    
    // Soporte para subdominios mágicos phpmyadmin.dominio.com -> /phpmyadmin
    $vhost .= "    RewriteEngine On\n";
    $vhost .= "    RewriteCond %{HTTP_HOST} ^(pma|phpmyadmin)\. [NC]\n";
    $vhost .= "    RewriteRule ^/(.*)$ /phpmyadmin/$1 [L,PT]\n\n";

    $vhost .= "    <Directory $document_root>\n";
    $vhost .= "        Options -Indexes +FollowSymLinks\n";
    $vhost .= "        AllowOverride All\n";
    $vhost .= "        Require all granted\n";
    $vhost .= "    </Directory>\n\n";
    
    // DB Manager access (Global Alias)
    $vhost .= "    Alias /phpmyadmin /var/www/admin_panel/phpmyadmin\n";
    $vhost .= "    Alias /dbadmin /var/www/admin_panel/dbadmin\n";
    $vhost .= "    <Directory /var/www/admin_panel/phpmyadmin>\n";
    $vhost .= "        Options -Indexes +FollowSymLinks\n";
    $vhost .= "        AllowOverride All\n";
    $vhost .= "        Require all granted\n";
    $vhost .= "        <FilesMatch \.php$>\n";
    $vhost .= "            SetHandler \"proxy:unix:$php_socket|fcgi://localhost\"\n";
    $vhost .= "        </FilesMatch>\n";
    $vhost .= "    </Directory>\n\n";
    
    if ($php_enabled) {
        $vhost .= "    <FilesMatch \.php$>\n";
        $vhost .= "        SetHandler \"proxy:unix:$php_socket|fcgi://localhost\"\n";
        $vhost .= "    </FilesMatch>\n";
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_backups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        site_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        mega_path VARCHAR(255) NOT NULL,
        status ENUM('pending', 'completed', 'failed', 'restoring') DEFAULT 'completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (site_id)
    )");
} catch (Exception $e) {
    // Silencioso si falla por permisos en una ejecución normal, aunque el motor corre como root
}

// 1. Buscar tareas pendientes
$stmt = $pdo->prepare("SELECT * FROM sys_tasks WHERE status = 'pending' ORDER BY created_at ASC");
$stmt->execute();
$tasks = $stmt->fetchAll();

foreach ($tasks as $task) {
    $taskId = $task['id'];
    $payload = json_decode($task['payload'], true);
    $domain = $payload['domain'];
    $success = false;
    $msg = '';

    // Marcar como en ejecución (excepto si queremos reintentar silenciosamente)
    $pdo->prepare("UPDATE sys_tasks SET status = 'running' WHERE id = ?")->execute([$taskId]);

    switch ($task['task_type']) {
        case 'SITE_CREATE':
            $path = $payload['path'];
            $php = $payload['php_enabled'];
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
                shell_exec("/usr/bin/chown www-data:www-data " . escapeshellarg($path));
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
                    $content = file_get_contents($indexPath);
                    if (strpos($content, "Debian") !== false || strpos($content, "Apache2 Debian Default Page") !== false) {
                        unlink($indexPath);
                    }
                }
                
                if (!file_exists($indexPath) && !file_exists($indexPhpPath)) {
                    copy($templatePath, $indexPath);
                    shell_exec("/usr/bin/chown www-data:www-data " . escapeshellarg($indexPath));
                }
            }
            file_put_contents("/etc/apache2/sites-available/$domain.conf", generateVhost($domain, $path, $php, $php_v));
            $safeDomainConf = escapeshellarg("$domain.conf");
            shell_exec("$cmd_a2ensite $safeDomainConf && $cmd_apache_reload");
            $pdo->prepare("UPDATE sys_sites SET status = 'active' WHERE domain = ?")->execute([$domain]);
            $msg = "Site $domain created.";
            $success = true;

            // --- Auto-encolar SSL si la IP ya apunta aquí ---
            $publicIP = getPublicIP();
            if ($publicIP && checkExternalDNS($domain, $publicIP)) {
                // Verificar si ya hay una tarea SSL pendiente para este dominio para no duplicar
                $stmtSSL = $pdo->prepare("SELECT id FROM sys_tasks WHERE task_type = 'SSL_LETSENCRYPT' AND payload LIKE ? AND status = 'pending'");
                $stmtSSL->execute(['%"domain": "' . $domain . '"%']);
                if (!$stmtSSL->fetch()) {
                    $sslPayload = json_encode(['domain' => $domain]);
                    $pdo->prepare("INSERT INTO sys_tasks (task_type, payload, status) VALUES ('SSL_LETSENCRYPT', ?, 'pending')")->execute([$sslPayload]);
                    $msg .= " SSL task enqueued automatically (DNS OK).";
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

            $email = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : "admin@$domain";
            $domainsArg = "-d " . escapeshellarg($domain);
            
            // Subdominios adicionales si las DNS apuntan a nuestra IP
            if ($dnsWwwOk) $domainsArg .= " -d " . escapeshellarg("www.$domain");
            if ($dnsPmaOk) $domainsArg .= " -d " . escapeshellarg("pma.$domain");
            if ($dnsPmaFullOk) $domainsArg .= " -d " . escapeshellarg("phpmyadmin.$domain");
            
            // Usamos --keep-until-expiring para evitar que certbot falle si el cert ya es reciente
            $cmd = "$cmd_certbot --apache $domainsArg --non-interactive --agree-tos --email " . escapeshellarg($email) . " --redirect --keep-until-expiring 2>&1";
            exec($cmd, $output, $resultCode);

            // Verificación robusta: aunque certbot de un código de salida extraño, 
            // si el archivo existe, es que el SSL está operativo.
            if (file_exists("/etc/letsencrypt/live/$domain/fullchain.pem")) {
                $pdo->prepare("UPDATE sys_sites SET ssl_enabled = 1 WHERE domain = ?")->execute([$domain]);
                $msg = "SSL issued or already exists. Files verified.";
                $success = true;

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
                                    shell_exec($cmd_apache_reload);
                                    $msg .= " Admin panel SSL updated (Port 8090).";
                                }
                            }
                        }
                    }
                }
            } else {
                $msg = "Certbot error: " . (isset($output) ? end($output) : "Unknown error");
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
            
            $dbs = $pdo->prepare("SELECT db_name, db_user, db_pass FROM sys_databases WHERE site_id = ?");
            $dbs->execute([$siteId]);
            $dbData = $dbs->fetch();
            
            $timestamp = date('Ymd_His');
            $backupFile = "backup_{$domain}_{$timestamp}.tar.gz";
            $tmpDir = "/tmp/backup_{$domain}_{$timestamp}";
            $tmpTar = "/tmp/$backupFile";
            
            mkdir($tmpDir, 0755, true);
            
            $config = [
                'domain' => $domain,
                'document_root' => $docRoot,
                'timestamp' => $timestamp
            ];
            
            if ($dbData) {
                $config['db_name'] = $dbData['db_name'];
                $config['db_user'] = $dbData['db_user'];
                $config['db_pass'] = $dbData['db_pass'];
                
                $rootPass = trim(@file_get_contents("/root/.hosting_db_root"));
                $auth = $rootPass ? "-u root -p" . escapeshellarg($rootPass) : "-u root";
                $dbNameArg = escapeshellarg($dbData['db_name']);
                
                // Using mariadb-dump (or mysqldump for compatibility)
                if (file_exists('/usr/bin/mariadb-dump')) {
                    shell_exec("mariadb-dump $auth {$dbNameArg} | gzip > {$tmpDir}/database.sql.gz");
                } else {
                    shell_exec("mysqldump $auth {$dbNameArg} | gzip > {$tmpDir}/database.sql.gz");
                }
            }
            
            file_put_contents("$tmpDir/config.json", json_encode($config, JSON_PRETTY_PRINT));
            
            // Symlink to save space during tar (dereference with -h)
            symlink($docRoot, "$tmpDir/webroot");
            
            $tarCmd = "tar -czhf " . escapeshellarg($tmpTar) . " -C " . escapeshellarg($tmpDir) . " config.json webroot";
            if (file_exists("$tmpDir/database.sql.gz")) {
                $tarCmd = "tar -czhf " . escapeshellarg($tmpTar) . " -C " . escapeshellarg($tmpDir) . " config.json database.sql.gz webroot";
            }
            shell_exec($tarCmd);
            
            $megaPath = "/Backups/{$domain}";
            exec("/usr/bin/mega-mkdir -p " . escapeshellarg($megaPath) . " 2>&1");
            exec("/usr/bin/mega-put " . escapeshellarg($tmpTar) . " " . escapeshellarg($megaPath) . " 2>&1", $outMega, $resMega);
            
            if ($resMega === 0 || strpos(implode(" ", $outMega), 'uploaded') !== false) {
                $pdo->prepare("INSERT INTO sys_backups (site_id, filename, mega_path, status) VALUES (?, ?, ?, 'completed')")
                    ->execute([$siteId, $backupFile, $megaPath]);
                $msg = "Backup $backupFile uploaded completely.";
                $success = true;
            } else {
                $msg = "MEGA upload failed: " . implode(" ", $outMega);
                $success = false;
            }
            
            shell_exec("rm -rf " . escapeshellarg($tmpDir));
            @unlink($tmpTar);
            
            // Retention
            $retention = $pdo->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'backup_retention_days'")->fetchColumn();
            if ($retention && is_numeric($retention)) {
                $oldBackups = $pdo->prepare("SELECT id, filename, mega_path FROM sys_backups WHERE site_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
                $oldBackups->execute([$siteId, $retention]);
                foreach($oldBackups->fetchAll() as $old) {
                    $rmPath = escapeshellarg($old['mega_path'] . '/' . $old['filename']);
                    exec("/usr/bin/mega-rm $rmPath 2>&1");
                    $pdo->prepare("DELETE FROM sys_backups WHERE id = ?")->execute([$old['id']]);
                }
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
            
            $docRoot = escapeshellarg($siteData['document_root']);
            $remoteFile = escapeshellarg($backupData['mega_path'] . '/' . $backupData['filename']);
            $tmpTar = "/tmp/" . $backupData['filename'];
            $tmpDir = "/tmp/restore_dir_" . uniqid();
            
            exec("/usr/bin/mega-get $remoteFile " . escapeshellarg("/tmp/") . " 2>&1", $outMega, $resMega);
            
            if (!file_exists($tmpTar)) {
                $msg = "Failed to download backup from MEGA: " . implode(" ", $outMega);
                $success = false;
                break;
            }
            
            mkdir($tmpDir, 0755, true);
            shell_exec("tar -xzf " . escapeshellarg($tmpTar) . " -C " . escapeshellarg($tmpDir));
            
            if (file_exists("$tmpDir/database.sql.gz")) {
                $dbs = $pdo->prepare("SELECT db_name FROM sys_databases WHERE site_id = ?");
                $dbs->execute([$siteId]);
                $dbData = $dbs->fetch();
                if ($dbData) {
                    $rootPass = trim(@file_get_contents("/root/.hosting_db_root"));
                    $auth = $rootPass ? "-u root -p" . escapeshellarg($rootPass) : "-u root";
                    $dbNameArg = escapeshellarg($dbData['db_name']);
                    
                    shell_exec("mariadb $auth -e \"DROP DATABASE IF EXISTS $dbNameArg; CREATE DATABASE $dbNameArg;\"");
                    shell_exec("zcat " . escapeshellarg("$tmpDir/database.sql.gz") . " | mariadb $auth $dbNameArg");
                }
            }
            
            if (is_dir("$tmpDir/webroot")) {
                // Remove existing files and copy new ones
                shell_exec("sh -c 'rm -rf $docRoot/.[!.]* $docRoot/*'");
                shell_exec("cp -a $tmpDir/webroot/. $docRoot/");
                shell_exec("chown -R www-data:www-data $docRoot");
            }
            
            shell_exec("rm -rf " . escapeshellarg($tmpDir));
            @unlink($tmpTar);
            
            $msg = "Site successfully restored from MEGA.";
            $success = true;
            break;

        default:
            $msg = "Task type not implemented: " . $task['task_type'];
            break;
    }

    $status = $success ? 'success' : 'error';
    $pdo->prepare("UPDATE sys_tasks SET status = ?, result_msg = ? WHERE id = ?")->execute([$status, $msg, $taskId]);
    echo "Task $taskId ($domain): $status - $msg\n";
}
?>
