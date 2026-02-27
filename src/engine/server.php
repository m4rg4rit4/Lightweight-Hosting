<?php
/**
 * Hosting Custom - Task Processor (ISPConfig Style)
 * Runs as root via cron
 */

// 0. Detectar entorno y utilidades
$php_version = file_exists('/etc/php/') ? array_filter(scandir('/etc/php/'), function($v) { return is_numeric($v[0]); }) : [];
$php_v = !empty($php_version) ? reset($php_version) : '8.2';

require '/var/www/admin_panel/config.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// --- Funciones Auxiliares ---

function getPublicIP() {
    $ip = shell_exec("curl -s https://api.ipify.org");
    return trim($ip);
}

function checkExternalDNS($domain, $expectedIP) {
    // Usar dig con el resolver de Google para evitar caché local/hosts
    $output = shell_exec("dig @8.8.8.8 " . escapeshellarg($domain) . " +short");
    $ips = array_filter(explode("\n", trim($output)));
    return in_array($expectedIP, $ips);
}

function generateVhost($domain, $document_root, $php_enabled, $php_v, $is_ssl = false) {
    $port = $is_ssl ? 443 : 80;
    $vhost = "<VirtualHost *:$port>\n";
    $vhost .= "    ServerName $domain\n";
    $vhost .= "    ServerAlias www.$domain pma.$domain phpmyadmin.$domain\n";
    $vhost .= "    DocumentRoot $document_root\n";
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
    $vhost .= "            SetHandler \"proxy:unix:/run/php/php$php_v-fpm.sock|fcgi://localhost\"\n";
    $vhost .= "        </FilesMatch>\n";
    $vhost .= "    </Directory>\n\n";
    
    if ($php_enabled) {
        $vhost .= "    <FilesMatch \.php$>\n";
        $vhost .= "        SetHandler \"proxy:unix:/run/php/php$php_v-fpm.sock|fcgi://localhost\"\n";
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
                shell_exec("chown www-data:www-data $path");
            }

            // Copiar index.html inicial si el directorio está vacío
            $indexPath = rtrim($path, '/') . '/index.html';
            $indexPhpPath = rtrim($path, '/') . '/index.php';
            $templatePath = __DIR__ . '/index.html.template';

            if (!file_exists($indexPath) && !file_exists($indexPhpPath) && file_exists($templatePath)) {
                copy($templatePath, $indexPath);
                shell_exec("chown www-data:www-data " . escapeshellarg($indexPath));
            }
            file_put_contents("/etc/apache2/sites-available/$domain.conf", generateVhost($domain, $path, $php, $php_v));
            shell_exec("a2ensite $domain.conf && systemctl reload apache2");
            $pdo->prepare("UPDATE sys_sites SET status = 'active' WHERE domain = ?")->execute([$domain]);
            $msg = "Site $domain created.";
            $success = true;
            break;

        case 'SSL_LETSENCRYPT':
            $publicIP = getPublicIP();
            $dnsOk = checkExternalDNS($domain, $publicIP);
            // Comprobamos www explícitamente con DNS de Google
            $dnsWwwOk = checkExternalDNS("www." . $domain, $publicIP);
            $dnsPmaOk = checkExternalDNS("pma." . $domain, $publicIP);
            $dnsPmaFullOk = checkExternalDNS("phpmyadmin." . $domain, $publicIP);

            if (!$dnsOk) {
                // Volver a poner en pending para el siguiente minuto
                $pdo->prepare("UPDATE sys_tasks SET status = 'pending', result_msg = 'Waiting for DNS: $domain -> $publicIP' WHERE id = ?")->execute([$taskId]);
                continue 2; // Saltar al siguiente item del foreach
            }

            $email = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : "admin@$domain";
            $domainsArg = "-d " . escapeshellarg($domain);
            
            // Subdominios adicionales si las DNS apuntan a nuestra IP
            if ($dnsWwwOk) $domainsArg .= " -d " . escapeshellarg("www.$domain");
            if ($dnsPmaOk) $domainsArg .= " -d " . escapeshellarg("pma.$domain");
            if ($dnsPmaFullOk) $domainsArg .= " -d " . escapeshellarg("phpmyadmin.$domain");
            
            // Usamos --keep-until-expiring para evitar que certbot falle si el cert ya es reciente
            $cmd = "certbot --apache $domainsArg --non-interactive --agree-tos --email " . escapeshellarg($email) . " --redirect --keep-until-expiring 2>&1";
            exec($cmd, $output, $resultCode);

            // Verificación robusta: aunque certbot de un código de salida extraño, 
            // si el archivo existe, es que el SSL está operativo.
            if (file_exists("/etc/letsencrypt/live/$domain/fullchain.pem")) {
                $pdo->prepare("UPDATE sys_sites SET ssl_enabled = 1 WHERE domain = ?")->execute([$domain]);
                $msg = "SSL issued or already exists. Files verified.";
                $success = true;
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
                
                shell_exec("systemctl reload apache2");
                $pdo->prepare("UPDATE sys_sites SET php_enabled = ? WHERE domain = ?")->execute([$newValue, $domain]);
                $msg = "PHP set to " . ($newValue ? 'ON' : 'OFF');
                $success = true;
            }
            break;

        case 'SITE_TOGGLE_STATUS':
            $newStatus = $payload['new_status'];
            if ($newStatus === 'active') {
                shell_exec("a2ensite $domain.conf");
                if (file_exists("/etc/apache2/sites-available/$domain-le-ssl.conf")) shell_exec("a2ensite $domain-le-ssl.conf");
                $msg = "Site $domain enabled.";
            } else {
                shell_exec("a2dissite $domain.conf");
                shell_exec("a2dissite $domain-le-ssl.conf 2>/dev/null");
                $msg = "Site $domain disabled.";
            }
            shell_exec("systemctl reload apache2");
            $pdo->prepare("UPDATE sys_sites SET status = ? WHERE domain = ?")->execute([$newStatus, $domain]);
            $success = true;
            break;

        case 'SITE_DELETE':
            shell_exec("a2dissite $domain.conf 2>/dev/null");
            shell_exec("a2dissite $domain-le-ssl.conf 2>/dev/null");
            
            // 1. Revocar y eliminar certificados
            shell_exec("certbot delete --cert-name $domain --non-interactive 2>/dev/null");
            
            // 2. Limpiar archivos de config
            @unlink("/etc/apache2/sites-available/$domain.conf");
            @unlink("/etc/apache2/sites-available/$domain-le-ssl.conf");
            shell_exec("systemctl reload apache2");
            
            // 3. Borrar directorio raíz
            $site = $pdo->prepare("SELECT document_root FROM sys_sites WHERE domain = ?");
            $site->execute([$domain]);
            $path = $site->fetchColumn();
            if ($path && strpos($path, '/var/www/vhosts/') === 0 && strlen($path) > 17) {
                shell_exec("rm -rf " . escapeshellarg($path));
            }
            
            $pdo->prepare("DELETE FROM sys_sites WHERE domain = ?")->execute([$domain]);
            $msg = "Site $domain deleted completely.";
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
