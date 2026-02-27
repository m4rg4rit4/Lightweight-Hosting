
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

echo -e "${GREEN}Iniciando instalación ultra-ligera del sistema de hosting...${NC}"

# Autocuración: Eliminar posibles configs corruptas de intentos previos
rm -f /etc/apt/apt.conf.d/01lean /etc/dpkg/dpkg.cfg.d/01lean

# 1. Verificación de usuario root
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Por favor, ejecuta como root${NC}"
    exit 1
fi

# 2. Configuración de Hostname y FQDN
echo -e "${YELLOW}Configuración del Hostname y Dominio${NC}"
CURRENT_FQDN=$(hostname -f 2>/dev/null || hostname)
read -p "Introduce el FQDN completo [$CURRENT_FQDN]: " FULL_FQDN
FULL_FQDN=${FULL_FQDN:-$CURRENT_FQDN}

if [ -z "$FULL_FQDN" ]; then
    echo -e "${RED}El FQDN no puede estar vacío. Abortando.${NC}"
    exit 1
fi

echo -e "${YELLOW}Configuración del Email del Administrador (para Let's Encrypt)${NC}"
read -p "Introduce el email del administrador [${ADMIN_EMAIL:-admin@$FULL_FQDN}]: " NEW_EMAIL
ADMIN_EMAIL=${NEW_EMAIL:-${ADMIN_EMAIL:-admin@$FULL_FQDN}}

if [ -z "$ADMIN_EMAIL" ]; then
    echo -e "${RED}El email no puede estar vacío. Abortando.${NC}"
    exit 1
fi

SHORT_HOSTNAME=$(echo $FULL_FQDN | cut -d. -f1)

echo -e "${YELLOW}Configurando hostname a: ${NC}${GREEN}$FULL_FQDN${NC}"
hostnamectl set-hostname "$FULL_FQDN"

# Configurar /etc/hosts
sed -i "s/127.0.1.1.*/127.0.1.1\t$FULL_FQDN\t$SHORT_HOSTNAME/" /etc/hosts

# 3. Creación de SWAP (1GB) para evitar bloqueos en 1GB RAM
echo -e "${YELLOW}Creando swap de 1GB para estabilizar la instalación...${NC}"
if [ -z "$(swapon --show)" ]; then
    fallocate -l 1G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
    echo -e "${GREEN}Swap de 1GB creado y activado.${NC}"
else
    echo -e "${YELLOW}Ya existe un swap activo, saltando creación.${NC}"
fi

# 4. Pre-configuración para ahorrar DISCO (10GB Limit)
echo -e "${YELLOW}Configurando optimizaciones de espacio en disco...${NC}"

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
echo -e "${YELLOW}Eliminando servicios y paquetes innecesarios...${NC}"
apt purge -y exim4* rsyslog installation-report reportbug || true
apt autoremove --purge -y

# 6. Actualización del sistema
echo -e "${YELLOW}Actualizando sistema...${NC}"
apt update && apt upgrade -y || { echo -e "${RED}Error al actualizar APT. Abortando.${NC}"; exit 1; }

# 7. Instalación de paquetes esenciales (Mínimos)
echo -e "${YELLOW}Instalando paquetes base...${NC}"
apt install -y ufw curl git unzip cron certbot python3-certbot-apache dnsutils || { echo -e "${RED}Error al instalar paquetes base.${NC}"; exit 1; }

# 8. Instalación de Apache2 (MPM Event)
echo -e "${YELLOW}Instalando y optimizando Apache2...${NC}"
apt install -y apache2 || { echo -e "${RED}Error al instalar Apache2.${NC}"; exit 1; }

# Verificar que los comandos de apache existen antes de usarlos
if command -v a2enmod >/dev/null 2>&1; then
    a2dismod mpm_prefork
    a2enmod mpm_event proxy_fcgi setenvif rewrite ssl http2 brotli
else
    echo -e "${RED}Error: Comandos de Apache no encontrados.${NC}"
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
echo -e "${YELLOW}Instalando PHP-FPM...${NC}"
apt install -y php-fpm php-mysql php-curl php-gd php-mbstring php-xml php-zip || { echo -e "${RED}Error al instalar PHP.${NC}"; exit 1; }

# Detección robusta de la versión de PHP instalada
PHP_VERSION=$(ls /etc/php/ | grep -E '^[0-9.]+$' | head -n 1)

if [ -z "$PHP_VERSION" ]; then
    echo -e "${RED}No se detectó ninguna versión de PHP instalada en /etc/php/.${NC}"
    exit 1
fi

POOL_FILE="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"

if [ -f "$POOL_FILE" ]; then
    sed -i 's/^pm = dynamic/pm = ondemand/' $POOL_FILE
    sed -i 's/^pm.max_children = 5/pm.max_children = 10/' $POOL_FILE
    sed -i 's/^;pm.process_idle_timeout = 10s;/pm.process_idle_timeout = 30s;/' $POOL_FILE
else
    echo -e "${RED}No se encontró el archivo de configuración del pool: $POOL_FILE${NC}"
fi

# Habilitar solo los módulos necesarios (sin activar PHP globalmente)
a2enmod proxy_fcgi setenvif

# Asegurar que PHP NO está habilitado globalmente si se ejecutó antes
if [ -f "/etc/apache2/conf-enabled/php${PHP_VERSION}-fpm.conf" ]; then
    a2disconf php${PHP_VERSION}-fpm
fi

# 10. Instalación de MariaDB (Low Memory Profile)
echo -e "${YELLOW}Instalando MariaDB...${NC}"
apt install -y mariadb-server || { echo -e "${RED}Error al instalar MariaDB.${NC}"; exit 1; }

# Definir rutas antes de usarlas
ADMIN_PATH="/var/www/admin_panel"
ENGINE_PATH="/usr/local/bin/hosting"

# Intentar recuperar configuración existente si es una actualización
EXISTING_CONFIG="$ADMIN_PATH/config.php"
ROOT_DB_PASS_FILE="/root/.hosting_db_root"
IS_UPDATE=false

if [ -f "$EXISTING_CONFIG" ]; then
    echo -e "${GREEN}Detectada instalación existente. Cargando configuración...${NC}"
    EXISTING_DB_PASS=$(grep "'DB_PASS'" "$EXISTING_CONFIG" | cut -d"'" -f4)
    EXISTING_ADMIN_EMAIL=$(grep "'ADMIN_EMAIL'" "$EXISTING_CONFIG" | cut -d"'" -f4)
    EXISTING_DB_MANAGER=$(grep "'DB_MANAGER_DIR'" "$EXISTING_CONFIG" | cut -d"'" -f4)
    
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
    echo -e "${GREEN}Contraseña de MariaDB Root recuperada.${NC}"
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
    echo -e "${YELLOW}Aviso: No se encontró el directorio de configuración de MariaDB.${NC}"
fi

# Configurar contraseña de root y seguridad básica
echo -e "${YELLOW}Configurando acceso de MariaDB...${NC}"
systemctl start mariadb

# Crear archivo temporal .my.cnf para operar durante la instalación
# Probamos primero si podemos entrar sin contraseña (unix_socket)
if mariadb -e "status" >/dev/null 2>&1; then
    echo -e "${YELLOW}Configurando contraseña de root inicial...${NC}"
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
echo -e "${YELLOW}Creando/Actualizando base de datos de administración...${NC}"
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
    echo -e "${GREEN}Reutilizando usuario dbadmin existente.${NC}"
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
    status ENUM('active', 'inactive', 'pending', 'ssl_pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);"
mariadb -e "FLUSH PRIVILEGES;"

# Eliminar credenciales temporales
rm -f /root/.my.cnf

# 11. Firewall (ufw - nativo nftables en Debian moderno)
echo -e "${YELLOW}Configurando Firewall...${NC}"
ufw allow OpenSSH
ufw allow 'Apache Full'
ufw allow 8080/tcp
ufw --force enable

# 12. Descarga y configuración de archivos del sistema
echo -e "${YELLOW}Descargando archivos del panel y motor desde GitHub...${NC}"

# Asegurar que los directorios existen
mkdir -p "$ADMIN_PATH" "$ENGINE_PATH"

# Definir URL base para archivos raw
REPO_RAW="https://raw.githubusercontent.com/m4rg4rit4/Lightweight-Hosting/main"

# Descargar archivos del Panel de Administración
curl -sSL "$REPO_RAW/src/admin/index.php" -o "$ADMIN_PATH/index.php"
curl -sSL "$REPO_RAW/src/admin/config.php.template" -o "$ADMIN_PATH/config.php.template"

# Descargar archivos del Motor
curl -sSL "$REPO_RAW/src/engine/server.php" -o "$ENGINE_PATH/server.php"

# Descargar y preparar script de actualización (opcional pero recomendado)
curl -sSL "$REPO_RAW/installadmin.sh" -o "./installadmin.sh"
chmod +x ./installadmin.sh

if [ ! -f "$ADMIN_PATH/index.php" ] || [ ! -f "$ENGINE_PATH/server.php" ]; then
    echo -e "${RED}Error: No se pudieron descargar los archivos esenciales desde GitHub.${NC}"
    exit 1
fi

# Configurar Apache para escuchar en 8080
if ! grep -q "Listen 8080" /etc/apache2/ports.conf; then
    echo "Listen 8080" >> /etc/apache2/ports.conf
fi

# Inyectar configuración dinámica (respetando lo existente si es update)
# Preguntar por el gestor de base de datos
CURRENT_MANAGER=${EXISTING_DB_MANAGER:-"phpmyadmin"}
echo -e "${YELLOW}Selecciona el Gestor de Base de Datos [Actual: $CURRENT_MANAGER]:${NC}"
echo -e "1) phpMyAdmin (Completo, más pesado)"
echo -e "2) Adminer (Ligero, un solo archivo)"
read -p "Opción [1-2]: " DB_MANAGER_OPT

if [ "$DB_MANAGER_OPT" == "2" ]; then
    DB_MANAGER_DIR="dbadmin"
    # Limpiar phpmyadmin si existía para ahorrar espacio
    [ "$CURRENT_MANAGER" == "phpmyadmin" ] && rm -rf "$ADMIN_PATH/phpmyadmin"
else
    # Si no se elige 2, por defecto es 1 o se mantiene el actual si era ya pma
    DB_MANAGER_DIR="phpmyadmin"
    # Limpiar adminer si existía para ahorrar espacio
    [ "$CURRENT_MANAGER" == "dbadmin" ] && rm -rf "$ADMIN_PATH/dbadmin"
fi

cat <<EOF > $ADMIN_PATH/config.php
<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'dbadmin');
define('DB_USER', 'dbadmin');
define('DB_PASS', '$DB_ADMIN_PASS');
define('ADMIN_EMAIL', '$ADMIN_EMAIL');
define('DB_MANAGER_DIR', '$DB_MANAGER_DIR');

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
if [ "$DB_MANAGER_DIR" == "phpmyadmin" ]; then
    echo -e "${YELLOW}Instalando phpMyAdmin (vía descarga directa)...${NC}"
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
    echo -e "${YELLOW}Instalando Adminer...${NC}"
    mkdir -p "$ADMIN_PATH/dbadmin"
    curl -L https://www.adminer.org/latest.php -o "$ADMIN_PATH/dbadmin/index.php"
    chown -R www-data:www-data "$ADMIN_PATH/dbadmin"
fi

# Crear VirtualHost para el puerto 8080
cat <<EOF > /etc/apache2/sites-available/000-admin.conf
<VirtualHost *:8080>
    DocumentRoot $ADMIN_PATH
    ErrorLog \${APACHE_LOG_DIR}/admin_error.log
    CustomLog \${APACHE_LOG_DIR}/admin_access.log combined

    <Directory $ADMIN_PATH>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        <FilesMatch \.php$>
            SetHandler "proxy:unix:/run/php/php${PHP_VERSION}-fpm.sock|fcgi://localhost"
        </FilesMatch>
    </Directory>
</VirtualHost>
EOF

# Desactivar alias antiguo y activar nuevo sitio
rm -f /etc/apache2/conf-available/adminer.conf
a2disconf adminer 2>/dev/null
a2ensite 000-admin.conf

# 13. Configuración del Motor de Tareas (Cron)
echo -e "${YELLOW}Configurando motor de tareas y cronjob...${NC}"
chmod 700 $ENGINE_PATH/server.php

# Configurar Cron (Comprobando si ya existe)
if crontab -l 2>/dev/null | grep -q "$ENGINE_PATH/server.php"; then
    echo -e "${GREEN}El cronjob ya está configurado. Saltando.${NC}"
else
    (crontab -l 2>/dev/null; echo "* * * * * /usr/bin/php $ENGINE_PATH/server.php >> /var/log/hosting_engine.log 2>&1") | crontab -
    echo -e "${GREEN}Cronjob añadido con éxito.${NC}"
fi

# 14. Limpieza final de DISCO
echo -e "${YELLOW}Limpiando archivos temporales y caché de paquetes...${NC}"
apt autoremove -y
apt clean
rm -rf /var/lib/apt/lists/*

# Reinicio de servicios
systemctl enable cron
systemctl restart cron
systemctl restart apache2

# Reinicio inteligente de PHP-FPM
# Intentar detectar de nuevo por si acaso
CURRENT_PHP=$(ls /etc/php/ | grep -E '^[0-9.]+$' | head -n 1)
if [ ! -z "$CURRENT_PHP" ]; then
    systemctl restart php${CURRENT_PHP}-fpm
else
    # Fallback: intentar reiniciar cualquier servicio php-fpm que exista
    systemctl restart php*-fpm 2>/dev/null || echo -e "${RED}Aviso: No se pudo reiniciar PHP-FPM detectado.${NC}"
fi

systemctl restart mariadb

echo -e "${GREEN}====================================================${NC}"
echo -e "${GREEN} INSTALACIÓN COMPLETADA CON ÉXITO${NC}"
echo -e "${GREEN}====================================================${NC}"
echo -e "CPU: 1 vCore | RAM: 1GB | DISCO: 10GB Limit"
echo -e "Apache MPM: Event (Optimizado)"
echo -e "PHP-FPM: OnDemand (Ahorro de RAM activo)"
echo -e "MariaDB: Performance Schema OFF (Ahorro de RAM activo)"
echo -e ""
echo -e "${YELLOW}DATOS DE ACCESO IMPORTANTES:${NC}"
echo -e "MariaDB Root Password: ${GREEN}$DB_ROOT_PASS${NC}"
echo -e "Adminer URL: ${YELLOW}http://$FULL_FQDN:8080/dbadmin/${NC}"
echo -e "Admin DB User: ${GREEN}dbadmin${NC}"
echo -e "Admin DB Pass: ${GREEN}$DB_ADMIN_PASS${NC}"
echo -e "Admin Config:  ${YELLOW}$ADMIN_PATH/config.php${NC}"
echo -e "${YELLOW}Por favor, guarda estos datos en un lugar seguro.${NC}"
echo -e "${GREEN}====================================================${NC}"
