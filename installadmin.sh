#!/bin/bash
# ==========================================================================
# Hosting Custom - Bundle Installer/Updater
# Handles decompression and distribution of Admin Panel and Task Engine
# ==========================================================================

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Configuración de URLs y Rutas
REPO_RAW="https://raw.githubusercontent.com/m4rg4rit4/Lightweight-Hosting/main"
ADMIN_PATH="/var/www/admin_panel"
ENGINE_PATH="/usr/local/bin/hosting"

printf "${YELLOW}Actualizando archivos desde GitHub...${NC}\n"

# Crear directorio temporal para descargas
TEMP_DIR=$(mktemp -d /tmp/hosting_update_XXXXXX)

# 1. Descargar Panel de Administración y Motor a /tmp
printf "${YELLOW}Descargando archivos desde GitHub a /tmp...${NC}\n"
curl -sSL "$REPO_RAW/src/admin/index.php" -o "$TEMP_DIR/index.php"
curl -sSL "$REPO_RAW/src/admin/header.php" -o "$TEMP_DIR/header.php"
curl -sSL "$REPO_RAW/src/admin/dns.php" -o "$TEMP_DIR/dns.php"
curl -sSL "$REPO_RAW/src/admin/backups.php" -o "$TEMP_DIR/backups.php"
curl -sSL "$REPO_RAW/src/admin/databases.php" -o "$TEMP_DIR/databases.php"
curl -sSL "$REPO_RAW/src/admin/filemanager.php" -o "$TEMP_DIR/filemanager.php"
curl -sSL "$REPO_RAW/src/admin/tasks.php" -o "$TEMP_DIR/tasks.php"
curl -sSL "$REPO_RAW/src/admin/tasks_status.php" -o "$TEMP_DIR/tasks_status.php"
curl -sSL "$REPO_RAW/src/admin/config.php.template" -o "$TEMP_DIR/config.php.template"
curl -sSL "$REPO_RAW/src/engine/server.php" -o "$TEMP_DIR/server.php"
curl -sSL "$REPO_RAW/src/engine/index.html.template" -o "$TEMP_DIR/index.html.template"
curl -sSL "$REPO_RAW/src/engine/sync_monitor.sh" -o "$TEMP_DIR/sync_monitor.sh"
curl -sSL "$REPO_RAW/src/engine/auto_backup.php" -o "$TEMP_DIR/auto_backup.php"

if [ ! -f "$TEMP_DIR/index.php" ] || [ ! -f "$TEMP_DIR/server.php" ]; then
    printf "${RED}Error: No se pudieron descargar los archivos esenciales.${NC}\n"
    rm -rf "$TEMP_DIR"
    exit 1
fi

# 2. Desplegar archivos a su destino final
printf "${YELLOW}Desplegando archivos...${NC}\n"
mkdir -p "$ADMIN_PATH" "$ENGINE_PATH"
cp "$TEMP_DIR/index.php" "$ADMIN_PATH/index.php"
cp "$TEMP_DIR/header.php" "$ADMIN_PATH/header.php"
cp "$TEMP_DIR/dns.php" "$ADMIN_PATH/dns.php"
cp "$TEMP_DIR/backups.php" "$ADMIN_PATH/backups.php"
cp "$TEMP_DIR/databases.php" "$ADMIN_PATH/databases.php"
cp "$TEMP_DIR/filemanager.php" "$ADMIN_PATH/filemanager.php"
cp "$TEMP_DIR/tasks.php" "$ADMIN_PATH/tasks.php"
cp "$TEMP_DIR/tasks_status.php" "$ADMIN_PATH/tasks_status.php"
cp "$TEMP_DIR/config.php.template" "$ADMIN_PATH/config.php.template"
cp "$TEMP_DIR/server.php" "$ENGINE_PATH/server.php"
cp "$TEMP_DIR/index.html.template" "$ENGINE_PATH/index.html.template"
cp "$TEMP_DIR/sync_monitor.sh" "$ENGINE_PATH/sync_monitor.sh" 2>/dev/null || true
cp "$TEMP_DIR/auto_backup.php" "$ENGINE_PATH/auto_backup.php" 2>/dev/null || true

# Ajustar permisos de propiedad y ejecución
chown -R www-data:www-data $ADMIN_PATH
chmod -R 755 $ADMIN_PATH

# El motor de tareas debe ser ejecutable y privado para root
if [ -f "$ENGINE_PATH/server.php" ]; then
    chmod 700 $ENGINE_PATH/server.php
    chown root:root $ENGINE_PATH/server.php
fi

# Limpieza final
rm -rf "$TEMP_DIR" /tmp/hosting.tar.gz

printf "${GREEN}====================================================${NC}\n"
printf "${GREEN} Proceso de despliegue de archivos completado.${NC}\n"
printf "${GREEN}====================================================${NC}\n"
