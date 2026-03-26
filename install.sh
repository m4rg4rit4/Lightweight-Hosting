
#!/bin/bash

# ==========================================================================
# Hosting Custom Installer - Ultra-Light Version (Debian 13)
# Hardware Target: 1vCore, 1GB RAM, 10GB Disk
# ==========================================================================

# Colores para la salida
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

printf "${GREEN}Iniciando instalación ultra-ligera del sistema de hosting...${NC}\n"

# Detección de flag /update para instalación no interactiva
AUTO_UPDATE=false
if echo "$*" | grep -q "/update"; then
    AUTO_UPDATE=true
    printf "${YELLOW}Modo NO INTERACTIVO activado (/update)${NC}\n"
fi

# Obtener versión local
if [ -f "VERSION" ]; then
    VERSION=$(cat VERSION)
else
    VERSION="1.1.7"
fi
printf "${YELLOW}Versión del Sistema: ${NC}${GREEN}$VERSION${NC}\n"

# Autocuración: Eliminar posibles configs corruptas de intentos previos
rm -f /etc/apt/apt.conf.d/01lean /etc/dpkg/dpkg.cfg.d/01lean

# 1. Verificación de usuario root
if [ "$(id -u)" -ne 0 ]; then 
    printf "${RED}Por favor, ejecuta como root${NC}\n"
    exit 1
fi

# 2. Configuración de Hostname y FQDN
printf "${YELLOW}Configuración del Hostname y Dominio${NC}\n"
CURRENT_FQDN=$(hostname -f 2>/dev/null || hostname)
if [ "$AUTO_UPDATE" = true ]; then
    FULL_FQDN=${FULL_FQDN:-$CURRENT_FQDN}
    printf "${YELLOW}Usando FQDN: ${NC}${GREEN}$FULL_FQDN${NC}\n"
else
    read -p "Introduce el FQDN completo [$CURRENT_FQDN]: " FULL_FQDN
    FULL_FQDN=${FULL_FQDN:-$CURRENT_FQDN}
fi

if [ -z "$FULL_FQDN" ]; then
    printf "${RED}El FQDN no puede estar vacío. Abortando.${NC}\n"
    exit 1
fi

printf "${YELLOW}Configuración del Email del Administrador (para Let's Encrypt)${NC}\n"
if [ "$AUTO_UPDATE" = true ]; then
    ADMIN_EMAIL=${ADMIN_EMAIL:-"admin@$FULL_FQDN"}
    printf "${YELLOW}Usando Email: ${NC}${GREEN}$ADMIN_EMAIL${NC}\n"
else
    read -p "Introduce el email del administrador [${ADMIN_EMAIL:-admin@$FULL_FQDN}]: " NEW_EMAIL
    ADMIN_EMAIL=${NEW_EMAIL:-${ADMIN_EMAIL:-admin@$FULL_FQDN}}
fi

if [ -z "$ADMIN_EMAIL" ]; then
    printf "${RED}El email no puede estar vacío. Abortando.${NC}\n"
    exit 1
fi

SHORT_HOSTNAME=$(echo $FULL_FQDN | cut -d. -f1)
DNS_HOSTNAME=$SHORT_HOSTNAME
DNS_DOMAIN=$(echo $FULL_FQDN | cut -s -d. -f2-)
if [ -z "$DNS_DOMAIN" ]; then
    DNS_DOMAIN=$FULL_FQDN
fi
DNS_ADMIN_EMAIL="admin@$DNS_DOMAIN"
LETSENCRYPT_EMAIL="$ADMIN_EMAIL"


printf "${YELLOW}Configurando hostname a: ${NC}${GREEN}$FULL_FQDN${NC}\n"
hostnamectl set-hostname "$FULL_FQDN"

# Configurar /etc/hosts
sed -i "s/127.0.1.1.*/127.0.1.1\t$FULL_FQDN\t$SHORT_HOSTNAME/" /etc/hosts

# 3. Creación de SWAP (1GB) para evitar bloqueos en 1GB RAM
printf "${YELLOW}Creando swap de 1GB para estabilizar la instalación...${NC}\n"
if [ -z "$(swapon --show)" ]; then
    fallocate -l 1G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
    printf "${GREEN}Swap de 1GB creado y activado.${NC}\n"
else
    printf "${YELLOW}Ya existe un swap activo, saltando creación.${NC}\n"
fi

# 4. Pre-configuración para ahorrar DISCO (10GB Limit)
printf "${YELLOW}Configurando optimizaciones de espacio en disco...${NC}\n"

# Configuración de APT (solo opciones de APT)
cat <<EOF > /etc/apt/apt.conf.d/01lean
APT::Install-Recommends "0";
APT::Install-Suggests "0";
EOF

# Configuración de Dpkg (exclusiones de archivos para ahorrar espacio)
mkdir -p /etc/dpkg/dpkg.cfg.d/
cat <<EOF > /etc/dpkg/dpkg.cfg.d/01lean
path-exclude /usr/share/doc/*
path-exclude /usr/share/man/*
path-exclude /usr/share/info/*
EOF

# 5. Limpieza de paquetes innecesarios pre-instalación
printf "${YELLOW}Eliminando servicios y paquetes innecesarios...${NC}\n"
apt purge -y exim4* rsyslog installation-report reportbug || true
apt autoremove --purge -y

# 6. Actualización del sistema
printf "${YELLOW}Actualizando sistema...${NC}\n"
apt update && apt upgrade -y || { printf "${RED}Error al actualizar APT. Abortando.${NC}\n"; exit 1; }

# 7. Instalación de paquetes esenciales (Mínimos)
printf "${YELLOW}Instalando paquetes base...${NC}\n"
apt install -y ufw curl git unzip cron certbot python3-certbot-apache dnsutils || { printf "${RED}Error al instalar paquetes base.${NC}\n"; exit 1; }

# Instalación de megacmd para copias de seguridad
printf "${YELLOW}Instalando megacmd...${NC}\n"
curl -sL https://mega.nz/linux/repo/Debian_13/amd64/megacmd-Debian_13_amd64.deb -o /tmp/megacmd.deb
apt install -y /tmp/megacmd.deb || { printf "${RED}Error al instalar megacmd.${NC}\n"; exit 1; }
rm -f /tmp/megacmd.deb

# 8. Instalación de Apache2 (MPM Event)
printf "${YELLOW}Instalando y optimizando Apache2...${NC}\n"
apt install -y apache2 || { printf "${RED}Error al instalar Apache2.${NC}\n"; exit 1; }

# Verificar que los comandos de apache existen antes de usarlos
if command -v a2enmod >/dev/null 2>&1; then
    a2dismod mpm_prefork
    a2enmod mpm_event proxy_fcgi setenvif rewrite ssl http2 brotli
else
    printf "${RED}Error: Comandos de Apache no encontrados.${NC}\n"
    exit 1
fi

# Optimización Apache para 1GB RAM
cat <<EOF > /etc/apache2/mods-available/mpm_event.conf
<IfModule mpm_event_module>
    StartServers             1
    MinSpareThreads          5
    MaxSpareThreads         10
    ThreadLimit             64
    ThreadsPerChild         20
    MaxRequestWorkers       40
    MaxConnectionsPerChild   1000
</IfModule>
EOF

# 9. Instalación de PHP-FPM (Modo OnDemand para RAM)
printf "${YELLOW}Instalando PHP-FPM y extensiones necesarias...${NC}\n"
apt install -y php-fpm php-mysql php-curl php-gd php-mbstring php-xml php-zip php-bcmath php-intl || { printf "${RED}Error al instalar PHP.${NC}\n"; exit 1; }

# Detección robusta de la versión de PHP instalada
PHP_VERSION=$(ls /etc/php/ | grep -E '^[0-9.]+$' | sort -V | tail -n 1)

if [ -z "$PHP_VERSION" ]; then
    printf "${RED}No se detectó ninguna versión de PHP instalada en /etc/php/.${NC}\n"
    exit 1
fi

POOL_FILE="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"

if [ -f "$POOL_FILE" ]; then
    sed -i 's/^pm = dynamic/pm = ondemand/' $POOL_FILE
    sed -i 's/^pm.max_children = 5/pm.max_children = 10/' $POOL_FILE
    sed -i 's/^;pm.process_idle_timeout = 10s;/pm.process_idle_timeout = 30s;/' $POOL_FILE
else
    printf "${RED}No se encontró el archivo de configuración del pool: $POOL_FILE${NC}\n"
fi

PHP_INI_FILE="/etc/php/${PHP_VERSION}/fpm/php.ini"
if [ -f "$PHP_INI_FILE" ]; then
    sed -i 's/upload_max_filesize = 2M/upload_max_filesize = 128M/' $PHP_INI_FILE
    sed -i 's/post_max_size = 8M/post_max_size = 128M/' $PHP_INI_FILE
    sed -i 's/memory_limit = 128M/memory_limit = 256M/' $PHP_INI_FILE
fi

# Habilitar solo los módulos necesarios (sin activar PHP globalmente)
a2enmod proxy_fcgi setenvif

# Asegurar que PHP NO está habilitado globalmente si se ejecutó antes
if [ -f "/etc/apache2/conf-enabled/php${PHP_VERSION}-fpm.conf" ]; then
    a2disconf php${PHP_VERSION}-fpm
fi

# 10. Instalación de MariaDB (Low Memory Profile)
printf "${YELLOW}Instalando MariaDB...${NC}\n"
apt install -y mariadb-server || { printf "${RED}Error al instalar MariaDB.${NC}\n"; exit 1; }

# Definir rutas antes de usarlas
ADMIN_PATH="/var/www/admin_panel"
ENGINE_PATH="/usr/local/bin/hosting"

# Intentar recuperar configuración existente si es una actualización
EXISTING_CONFIG="$ADMIN_PATH/config.php"
ROOT_DB_PASS_FILE="/root/.hosting_db_root"
IS_UPDATE=false

if [ -f "$EXISTING_CONFIG" ]; then
    printf "${GREEN}Detectada instalación existente. Cargando configuración...${NC}\n"
    EXISTING_DB_PASS=$(grep "'DB_PASS'" "$EXISTING_CONFIG" | cut -d"'" -f4)
    EXISTING_ADMIN_EMAIL=$(grep "'ADMIN_EMAIL'" "$EXISTING_CONFIG" | cut -d"'" -f4)
    EXISTING_DB_MANAGER=$(grep "'DB_MANAGER_DIR'" "$EXISTING_CONFIG" | cut -d"'" -f4)
    # Extraer variables de DNS (pueden estar comentadas o no)
    EXISTING_DNS_TOKEN=$(grep "DNS_TOKEN" "$EXISTING_CONFIG" | sed -E "s/.*'DNS_TOKEN', '([^']*)'.*/\1/")
    EXISTING_DNS_SERVER=$(grep "DNS_SERVER" "$EXISTING_CONFIG" | sed -E "s/.*'DNS_SERVER', '([^']*)'.*/\1/")
    
    if [ ! -z "$EXISTING_DB_PASS" ]; then
        DB_ADMIN_PASS="$EXISTING_DB_PASS"
        IS_UPDATE=true
    fi
    if [ ! -z "$EXISTING_ADMIN_EMAIL" ]; then
        ADMIN_EMAIL="$EXISTING_ADMIN_EMAIL"
    fi
fi

# Recuperar o generar contraseña de Root de MariaDB
if [ -f "$ROOT_DB_PASS_FILE" ]; then
    DB_ROOT_PASS=$(cat "$ROOT_DB_PASS_FILE")
    printf "${GREEN}Contraseña de MariaDB Root recuperada.${NC}\n"
else
    DB_ROOT_PASS=$(openssl rand -base64 24)
    echo "$DB_ROOT_PASS" > "$ROOT_DB_PASS_FILE"
    chmod 600 "$ROOT_DB_PASS_FILE"
fi

# Optimización MariaDB para 1GB RAM
if [ -d /etc/mysql/mariadb.conf.d/ ]; then
    cat <<EOF > /etc/mysql/mariadb.conf.d/99-low-memory.cnf
[mysqld]
# Desactivar Performance Schema ahorra ~40-100MB RAM
performance_schema = OFF
innodb_buffer_pool_size = 128M
innodb_log_file_size = 32M
max_connections = 20
key_buffer_size = 8M
thread_cache_size = 4
query_cache_size = 0
query_cache_type = 0
EOF
else
    printf "${YELLOW}Aviso: No se encontró el directorio de configuración de MariaDB.${NC}\n"
fi

# Configurar contraseña de root y seguridad básica
printf "${YELLOW}Configurando acceso de MariaDB...${NC}\n"
systemctl start mariadb

# Crear archivo temporal .my.cnf para operar durante la instalación
# Probamos primero si podemos entrar sin contraseña (unix_socket)
if mariadb -e "status" >/dev/null 2>&1; then
    printf "${YELLOW}Configurando contraseña de root inicial...${NC}\n"
    mariadb -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$DB_ROOT_PASS';"
fi

# Crear/Actualizar el .my.cnf para que el resto del script funcione
cat <<EOF > /root/.my.cnf
[client]
user=root
password=$DB_ROOT_PASS
EOF
chmod 600 /root/.my.cnf

# Ahora el script puede ejecutar comandos como 'mariadb' sin que le deniegue el acceso
mariadb -e "DELETE FROM mysql.user WHERE User='';"
mariadb -e "DROP DATABASE IF EXISTS test;"
mariadb -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"

# Crear Base de Datos y Usuario de Administración
printf "${YELLOW}Creando/Actualizando base de datos de administración...${NC}\n"
if [ "$IS_UPDATE" = false ]; then
    DB_ADMIN_PASS=$(openssl rand -base64 18)
fi

mariadb -e "CREATE DATABASE IF NOT EXISTS dbadmin;"
# Forzamos 127.0.0.1 para evitar bloqueos por el plugin unix_socket de localhost
if [ "$IS_UPDATE" = false ]; then
    mariadb -e "DROP USER IF EXISTS 'dbadmin'@'localhost';"
    mariadb -e "DROP USER IF EXISTS 'dbadmin'@'127.0.0.1';"
    mariadb -e "CREATE USER 'dbadmin'@'127.0.0.1' IDENTIFIED BY '$DB_ADMIN_PASS';"
    mariadb -e "ALTER USER 'dbadmin'@'127.0.0.1' IDENTIFIED VIA mysql_native_password USING PASSWORD('$DB_ADMIN_PASS');"
else
    printf "${GREEN}Reutilizando usuario dbadmin existente.${NC}\n"
    mariadb -e "GRANT ALL PRIVILEGES ON dbadmin.* TO 'dbadmin'@'127.0.0.1' IDENTIFIED BY '$DB_ADMIN_PASS';"
fi
mariadb -e "GRANT ALL PRIVILEGES ON dbadmin.* TO 'dbadmin'@'127.0.0.1';"
mariadb -e "FLUSH PRIVILEGES;"

# Crear tabla de tareas (estilo ISPConfig)
mariadb -D dbadmin -e "CREATE TABLE IF NOT EXISTS sys_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_type VARCHAR(50) NOT NULL,
    payload JSON NOT NULL,
    status ENUM('pending', 'running', 'success', 'error') DEFAULT 'pending',
    result_msg TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);"

# Crear tabla de sitios
mariadb -D dbadmin -e "CREATE TABLE IF NOT EXISTS sys_sites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL UNIQUE,
    document_root VARCHAR(255) NOT NULL,
    php_enabled BOOLEAN DEFAULT 1,
    ssl_enabled BOOLEAN DEFAULT 0,
    backup_frequency ENUM('none', 'daily', 'weekly') DEFAULT 'none',
    status ENUM('active', 'inactive', 'pending', 'ssl_pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);"

# Crear tabla de bases de datos
mariadb -D dbadmin -e "CREATE TABLE IF NOT EXISTS sys_databases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    db_name VARCHAR(64) NOT NULL UNIQUE,
    db_user VARCHAR(32) NOT NULL,
    db_pass VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (site_id)
);"

# Crear tabla de configuraciones del sistema (MEGA etc)
mariadb -D dbadmin -e "CREATE TABLE IF NOT EXISTS sys_settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);"

# Crear tabla de copias de seguridad
mariadb -D dbadmin -e "CREATE TABLE IF NOT EXISTS sys_backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    mega_path VARCHAR(255) NOT NULL,
    status ENUM('pending', 'completed', 'failed', 'restoring') DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (site_id)
);"
mariadb -e "FLUSH PRIVILEGES;"

# Crear primer sitio (el host principal)
printf "${YELLOW}Configurando el sitio principal para $FULL_FQDN...${NC}\n"
# El document_root por defecto será /var/www/html para el sitio principal
MAIN_ROOT="/var/www/html"
mkdir -p "$MAIN_ROOT"
chown -R www-data:www-data "$MAIN_ROOT"

# Insertar en base de datos
mariadb -u root -p"$DB_ROOT_PASS" -D dbadmin -e "INSERT IGNORE INTO sys_sites (domain, document_root, php_enabled, status) VALUES ('$FULL_FQDN', '$MAIN_ROOT', 1, 'pending');"
# Encolar creación del sitio
PAYLOAD_CREATE="{\"domain\": \"$FULL_FQDN\", \"path\": \"$MAIN_ROOT\", \"php_enabled\": 1}"
mariadb -u root -p"$DB_ROOT_PASS" -D dbadmin -e "INSERT INTO sys_tasks (task_type, payload, status) VALUES ('SITE_CREATE', '$PAYLOAD_CREATE', 'pending');"
# Encolar SSL (el motor verificará si la IP apunta antes de procesarlo)
PAYLOAD_SSL="{\"domain\": \"$FULL_FQDN\"}"
mariadb -u root -p"$DB_ROOT_PASS" -D dbadmin -e "INSERT INTO sys_tasks (task_type, payload, status) VALUES ('SSL_LETSENCRYPT', '$PAYLOAD_SSL', 'pending');"

# Eliminar credenciales temporales
rm -f /root/.my.cnf

# 11. Firewall (ufw - nativo nftables en Debian moderno)
printf "${YELLOW}Configurando Firewall...${NC}\n"
ufw allow OpenSSH
ufw allow 'Apache Full'
ufw allow 8080/tcp
ufw allow 8090/tcp
ufw --force enable

# 12. Descarga y configuración de archivos del sistema
printf "${YELLOW}Descargando archivos del panel y motor desde GitHub...${NC}\n"

# Crear directorio temporal para descargas
TEMP_DIR=$(mktemp -d /tmp/hosting_XXXXXX)

# Asegurar que los directorios finales existen
mkdir -p "$ADMIN_PATH" "$ENGINE_PATH"

# Definir URL base para archivos raw
REPO_RAW="https://raw.githubusercontent.com/m4rg4rit4/Lightweight-Hosting/main"

# Descargar archivos a /tmp primero
curl -sSL "$REPO_RAW/src/admin/index.php" -o "$TEMP_DIR/index.php"
curl -sSL "$REPO_RAW/src/admin/tasks.php" -o "$TEMP_DIR/tasks.php"
curl -sSL "$REPO_RAW/src/admin/tasks_status.php" -o "$TEMP_DIR/tasks_status.php"
curl -sSL "$REPO_RAW/src/admin/filemanager.php" -o "$TEMP_DIR/filemanager.php"
curl -sSL "$REPO_RAW/src/admin/databases.php" -o "$TEMP_DIR/databases.php"
curl -sSL "$REPO_RAW/src/admin/backups.php" -o "$TEMP_DIR/backups.php"
curl -sSL "$REPO_RAW/src/admin/header.php" -o "$TEMP_DIR/header.php"
curl -sSL "$REPO_RAW/src/admin/dns.php" -o "$TEMP_DIR/dns.php"
curl -sSL "$REPO_RAW/src/admin/admin-style.css" -o "$TEMP_DIR/admin-style.css"
curl -sSL "$REPO_RAW/src/admin/config.php.template" -o "$TEMP_DIR/config.php.template"
curl -sSL "$REPO_RAW/src/engine/server.php" -o "$TEMP_DIR/server.php"
curl -sSL "$REPO_RAW/src/engine/index.html.template" -o "$TEMP_DIR/index.html.template"
curl -sSL "$REPO_RAW/src/engine/sync_monitor.sh" -o "$TEMP_DIR/sync_monitor.sh"
curl -sSL "$REPO_RAW/src/engine/auto_backup.php" -o "$TEMP_DIR/auto_backup.php"
curl -sSL "$REPO_RAW/installadmin.sh" -o "$TEMP_DIR/installadmin.sh"

if [ ! -f "$TEMP_DIR/index.php" ] || [ ! -f "$TEMP_DIR/tasks.php" ] || [ ! -f "$TEMP_DIR/tasks_status.php" ] || [ ! -f "$TEMP_DIR/server.php" ]; then
    printf "${RED}Error: No se pudieron descargar los archivos esenciales desde GitHub.${NC}\n"
    rm -rf "$TEMP_DIR"
    exit 1
fi

# Mover archivos a su destino final
cp "$TEMP_DIR/index.php" "$ADMIN_PATH/index.php"
cp "$TEMP_DIR/tasks.php" "$ADMIN_PATH/tasks.php"
cp "$TEMP_DIR/tasks_status.php" "$ADMIN_PATH/tasks_status.php"
cp "$TEMP_DIR/filemanager.php" "$ADMIN_PATH/filemanager.php"
cp "$TEMP_DIR/databases.php" "$ADMIN_PATH/databases.php"
cp "$TEMP_DIR/backups.php" "$ADMIN_PATH/backups.php"
cp "$TEMP_DIR/header.php" "$ADMIN_PATH/header.php"
cp "$TEMP_DIR/dns.php" "$ADMIN_PATH/dns.php"
cp "$TEMP_DIR/admin-style.css" "$ADMIN_PATH/admin-style.css"
cp "$TEMP_DIR/config.php.template" "$ADMIN_PATH/config.php.template"
cp "$TEMP_DIR/server.php" "$ENGINE_PATH/server.php"
cp "$TEMP_DIR/index.html.template" "$ENGINE_PATH/index.html.template"
cp "$TEMP_DIR/sync_monitor.sh" "$ENGINE_PATH/sync_monitor.sh"
cp "$TEMP_DIR/auto_backup.php" "$ENGINE_PATH/auto_backup.php"
cp "$TEMP_DIR/installadmin.sh" "./installadmin.sh"
chmod +x ./installadmin.sh

# Limpiar directorio temporal
rm -rf "$TEMP_DIR"

# Configurar Apache para escuchar en 8080
if ! grep -q "Listen 8080" /etc/apache2/ports.conf; then
    echo "Listen 8080" >> /etc/apache2/ports.conf
fi
if ! grep -q "Listen 8090" /etc/apache2/ports.conf; then
    echo "Listen 8090" >> /etc/apache2/ports.conf
fi

# Inyectar configuración dinámica (respetando lo existente si es update)
# Preguntar por el gestor de base de datos
CURRENT_MANAGER=${EXISTING_DB_MANAGER:-"phpmyadmin"}
if [ "$AUTO_UPDATE" = true ]; then
    DB_MANAGER_OPT="1" # Por defecto phpMyAdmin en updates automáticos si no se especifica
    # Si ya existía uno, lo respetamos
    if [ "$CURRENT_MANAGER" = "dbadmin" ]; then
        DB_MANAGER_OPT="2"
    fi
    printf "${YELLOW}Usando Gestor de BD: ${NC}${GREEN}$CURRENT_MANAGER${NC}\n"
else
    printf "${YELLOW}Selecciona el Gestor de Base de Datos [Actual: $CURRENT_MANAGER]:${NC}\n"
    printf "1) phpMyAdmin (Completo, más pesado)\n"
    printf "2) Adminer (Ligero, un solo archivo)\n"
    read -p "Opción [1-2]: " DB_MANAGER_OPT
fi

if [ "$DB_MANAGER_OPT" = "2" ]; then
    DB_MANAGER_DIR="dbadmin"
    # Limpiar phpmyadmin si existía para ahorrar espacio
    [ "$CURRENT_MANAGER" = "phpmyadmin" ] && rm -rf "$ADMIN_PATH/phpmyadmin"
else
    # Si no se elige 2, por defecto es 1 o se mantiene el actual si era ya pma
    DB_MANAGER_DIR="phpmyadmin"
    # Limpiar adminer si existía para ahorrar espacio
    [ "$CURRENT_MANAGER" = "dbadmin" ] && rm -rf "$ADMIN_PATH/dbadmin"
fi

cat <<EOF > $ADMIN_PATH/config.php
<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'dbadmin');
define('DB_USER', 'dbadmin');
define('DB_PASS', '$DB_ADMIN_PASS');
define('ADMIN_EMAIL', '$ADMIN_EMAIL');
define('DNS_HOSTNAME', '$DNS_HOSTNAME');
define('DNS_DOMAIN', '$DNS_DOMAIN');
define('DNS_ADMIN_EMAIL', '$DNS_ADMIN_EMAIL');
define('LETSENCRYPT_EMAIL', '$ADMIN_EMAIL');
define('DB_MANAGER_DIR', '$DB_MANAGER_DIR');
define('SYSTEM_VERSION', '$VERSION');
define('HOSTING_INSTALLED', 'true');
EOF

# DNS Alternativo (Si están definidos)
if [ ! -z "$EXISTING_DNS_TOKEN" ]; then
    echo "define('DNS_TOKEN', '$EXISTING_DNS_TOKEN');" >> $ADMIN_PATH/config.php
else
    echo "// define('DNS_TOKEN', '{{DNS_TOKEN}}');" >> $ADMIN_PATH/config.php
fi

if [ ! -z "$EXISTING_DNS_SERVER" ]; then
    echo "define('DNS_SERVER', '$EXISTING_DNS_SERVER');" >> $ADMIN_PATH/config.php
else
    echo "// define('DNS_SERVER', '{{DNS_SERVER}}');" >> $ADMIN_PATH/config.php
fi

cat <<EOF >> $ADMIN_PATH/config.php

function getPDO() {
    static \$pdo;
    if (!\$pdo) {
        \$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    return \$pdo;
}
?>
EOF

chown -R www-data:www-data $ADMIN_PATH
chmod -R 755 $ADMIN_PATH

# Instalación del gestor de base de datos
if [ "$DB_MANAGER_DIR" = "phpmyadmin" ]; then
    printf "${YELLOW}Instalando phpMyAdmin (vía descarga directa)...${NC}\n"
    PMA_VER="5.2.1"
    PMA_URL="https://files.phpmyadmin.net/phpMyAdmin/${PMA_VER}/phpMyAdmin-${PMA_VER}-all-languages.tar.gz"
    curl -sSL "$PMA_URL" -o /tmp/pma.tar.gz
    mkdir -p "$ADMIN_PATH/phpmyadmin"
    tar -xzf /tmp/pma.tar.gz -C "$ADMIN_PATH/phpmyadmin" --strip-components=1
    rm /tmp/pma.tar.gz
    
    # Configuración básica para phpMyAdmin
    PMA_SECRET=$(openssl rand -base64 32)
    cat <<EOF > "$ADMIN_PATH/phpmyadmin/config.inc.php"
<?php
\$cfg['blowfish_secret'] = '$PMA_SECRET';
\$i = 0; \$i++;
\$cfg['Servers'][\$i]['auth_type'] = 'cookie';
\$cfg['Servers'][\$i]['host'] = '127.0.0.1';
\$cfg['Servers'][\$i]['compress'] = false;
\$cfg['Servers'][\$i]['AllowNoPassword'] = false;
?>
EOF
    chown -R www-data:www-data "$ADMIN_PATH/phpmyadmin"
else
    printf "${YELLOW}Instalando Adminer...${NC}\n"
    mkdir -p "$ADMIN_PATH/dbadmin"
    curl -L https://www.adminer.org/latest.php -o "$ADMIN_PATH/dbadmin/index.php"
    chown -R www-data:www-data "$ADMIN_PATH/dbadmin"
fi

# Crear VirtualHost para el puerto 8080 (Admin + PHPMyAdmin)
# Detección robusta del socket de PHP
# En Debian 13, php-fpm-socket-helper suele crear /run/php/php-fpm.sock
REAL_PHP_SOCKET=$(ls /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -n 1)
if [ -z "$REAL_PHP_SOCKET" ]; then
    # Fallback 1: El socket genérico de Debian 13
    if [ -S "/run/php/php-fpm.sock" ]; then
        REAL_PHP_SOCKET="/run/php/php-fpm.sock"
    else
        # Fallback 2: El socket basado en la versión
        REAL_PHP_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
    fi
fi

cat <<EOF > /etc/apache2/sites-available/000-admin.conf
<VirtualHost *:8080>
    DocumentRoot $ADMIN_PATH
    DirectoryIndex index.php
    ErrorLog \${APACHE_LOG_DIR}/admin_error.log
    CustomLog \${APACHE_LOG_DIR}/admin_access.log combined

    # Alias específico para que la raíz del 8080 pueda redirigir o tener acceso directo
    Alias /phpmyadmin $ADMIN_PATH/phpmyadmin
    Alias /dbadmin $ADMIN_PATH/dbadmin

    <Directory $ADMIN_PATH>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        <FilesMatch \.php$>
            SetHandler "proxy:unix:$REAL_PHP_SOCKET|fcgi://localhost/"
        </FilesMatch>
    </Directory>

    # Configuración específica para el gestor de BD en 8080
    <Directory $ADMIN_PATH/phpmyadmin>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <Directory $ADMIN_PATH/dbadmin>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

# VirtualHost para el puerto 8090 (SSL Version)
<VirtualHost *:8090>
    DocumentRoot $ADMIN_PATH
    DirectoryIndex index.php
    ErrorLog \${APACHE_LOG_DIR}/admin_ssl_error.log
    CustomLog \${APACHE_LOG_DIR}/admin_ssl_access.log combined

    Alias /phpmyadmin $ADMIN_PATH/phpmyadmin
    Alias /dbadmin $ADMIN_PATH/dbadmin

    <Directory $ADMIN_PATH>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        <FilesMatch \.php$>
            SetHandler "proxy:unix:$REAL_PHP_SOCKET|fcgi://localhost/"
        </FilesMatch>
    </Directory>

    # Configuración específica para el gestor de BD en 8090
    <Directory $ADMIN_PATH/phpmyadmin>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <Directory $ADMIN_PATH/dbadmin>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF

# Configuración global para el gestor de BD (accesible desde cualquier dominio/subdominio)
cat <<EOF > /etc/apache2/conf-available/hosting-dbmanager.conf
# Alias globales para acceso desde cualquier sitio
Alias /phpmyadmin $ADMIN_PATH/phpmyadmin
Alias /dbadmin $ADMIN_PATH/dbadmin

<Directory $ADMIN_PATH/phpmyadmin>
    DirectoryIndex index.php
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
    <FilesMatch \.php$>
        SetHandler "proxy:unix:$REAL_PHP_SOCKET|fcgi://localhost/"
    </FilesMatch>
</Directory>

<Directory $ADMIN_PATH/dbadmin>
    DirectoryIndex index.php
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
    <FilesMatch \.php$>
        SetHandler "proxy:unix:$REAL_PHP_SOCKET|fcgi://localhost/"
    </FilesMatch>
</Directory>
EOF

# Activar configuraciones
a2enconf hosting-dbmanager
a2ensite 000-admin.conf

# 13. Configuración del Motor de Tareas (Cron)
printf "${YELLOW}Configurando motor de tareas y cronjob...${NC}\n"
chmod 700 $ENGINE_PATH/server.php
chmod 700 $ENGINE_PATH/sync_monitor.sh
chmod 700 $ENGINE_PATH/auto_backup.php

# Configurar Cron (Comprobando si ya existe)
if crontab -l 2>/dev/null | grep -q "$ENGINE_PATH/server.php"; then
    printf "${GREEN}El cronjob del engine ya está configurado. Saltando.${NC}\n"
else
    (crontab -l 2>/dev/null; echo "* * * * * PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin /usr/bin/php $ENGINE_PATH/server.php >> /var/log/hosting_engine.log 2>&1") | crontab -
    printf "${GREEN}Cronjob del engine añadido con éxito.${NC}\n"
fi

if crontab -l 2>/dev/null | grep -q "$ENGINE_PATH/sync_monitor.sh"; then
    printf "${GREEN}El cronjob del monitor de sync ya está configurado. Saltando.${NC}\n"
else
    (crontab -l 2>/dev/null; echo "* * * * * PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin /bin/bash $ENGINE_PATH/sync_monitor.sh > /dev/null 2>&1") | crontab -
    printf "${GREEN}Cronjob del monitor de sync añadido con éxito.${NC}\n"
fi

# Configurar Auto-Backup (Diariamente a las 03:00)
if crontab -l 2>/dev/null | grep -q "$ENGINE_PATH/auto_backup.php"; then
    printf "${GREEN}El cronjob de auto_backup ya está configurado. Saltando.${NC}\n"
else
    (crontab -l 2>/dev/null; echo "0 3 * * * PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin /usr/bin/php $ENGINE_PATH/auto_backup.php >> /var/log/hosting_backup.log 2>&1") | crontab -
    printf "${GREEN}Cronjob de auto_backup añadido con éxito (Diariamente a las 03:00).${NC}\n"
fi

# 14. Limpieza final de DISCO
printf "${YELLOW}Limpiando archivos temporales y caché de paquetes...${NC}\n"
apt autoremove -y
apt clean
rm -rf /var/lib/apt/lists/*

# Reinicio de servicios
systemctl enable cron
systemctl restart cron
a2dissite 000-default.conf || true
systemctl restart apache2

# Reinicio inteligente de PHP-FPM
# Intentar detectar de nuevo por si acaso
CURRENT_PHP=$(ls /etc/php/ | grep -E '^[0-9.]+$' | head -n 1)
if [ ! -z "$CURRENT_PHP" ]; then
    systemctl restart php${CURRENT_PHP}-fpm
else
    # Fallback: intentar reiniciar cualquier servicio php-fpm que exista
    systemctl restart php*-fpm 2>/dev/null || printf "${RED}Aviso: No se pudo reiniciar PHP-FPM detectado.${NC}\n"
fi

systemctl restart mariadb

printf "${GREEN}====================================================${NC}\n"
printf "${GREEN} INSTALACIÓN COMPLETADA CON ÉXITO (v$VERSION)${NC}\n"
printf "${GREEN}====================================================${NC}\n"
printf "CPU: 1 vCore | RAM: 1GB | DISCO: 10GB Limit\n"
printf "Apache MPM: Event (Optimizado)\n"
printf "PHP-FPM: OnDemand (Ahorro de RAM activo)\n"
printf "MariaDB: Performance Schema OFF (Ahorro de RAM activo)\n"
printf "\n"
printf "${YELLOW}DATOS DE ACCESO IMPORTANTES:${NC}\n"
printf "MariaDB Root Password: ${GREEN}$DB_ROOT_PASS${NC}\n"
printf "Panel de Control:     ${YELLOW}http://$FULL_FQDN:8080/${NC}\n"
printf "Panel (Seguro):       ${YELLOW}https://$FULL_FQDN:8090/${NC}\n"
printf "Gestor DB (Directo):  ${YELLOW}http://$FULL_FQDN:8080/$DB_MANAGER_DIR/${NC}\n"
printf "Gestor DB (Alias):    ${YELLOW}http://$FULL_FQDN/$DB_MANAGER_DIR/${NC}\n"
printf "Admin DB User: ${GREEN}dbadmin${NC}\n"
printf "Admin DB Pass: ${GREEN}$DB_ADMIN_PASS${NC}\n"
printf "Admin Config:  ${YELLOW}$ADMIN_PATH/config.php${NC}\n"
printf "${YELLOW}Por favor, guarda estos datos en un lugar seguro.${NC}\n"
printf "${GREEN}====================================================${NC}\n"
