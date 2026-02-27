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

echo -e "${YELLOW}Actualizando archivos desde GitHub...${NC}"

# 1. Actualizar Panel de Administración
echo -e "${YELLOW}Actualizando interfaz (protegiendo config.php)...${NC}"
mkdir -p $ADMIN_PATH
curl -sSL "$REPO_RAW/src/admin/index.php" -o "$ADMIN_PATH/index.php"
curl -sSL "$REPO_RAW/src/admin/config.php.template" -o "$ADMIN_PATH/config.php.template"
echo -e "${GREEN}- Interfaz de administración actualizada en $ADMIN_PATH${NC}"

# 2. Actualizar Motor de Tareas
echo -e "${YELLOW}Actualizando motor de tareas...${NC}"
mkdir -p $ENGINE_PATH
curl -sSL "$REPO_RAW/src/engine/server.php" -o "$ENGINE_PATH/server.php"
curl -sSL "$REPO_RAW/src/engine/index.html.template" -o "$ENGINE_PATH/index.html.template"
echo -e "${GREEN}- Motor de tareas actualizado en $ENGINE_PATH${NC}"

# Ajustar permisos de propiedad y ejecución
chown -R www-data:www-data $ADMIN_PATH
chmod -R 755 $ADMIN_PATH

# El motor de tareas debe ser ejecutable y privado para root
if [ -f "$ENGINE_PATH/server.php" ]; then
    chmod 700 $ENGINE_PATH/server.php
    chown root:root $ENGINE_PATH/server.php
fi

# Limpieza final
rm -rf $TEMP_DIR /tmp/hosting.tar.gz

echo -e "${GREEN}====================================================${NC}"
echo -e "${GREEN} Proceso de despliegue de archivos completado.${NC}"
echo -e "${GREEN}====================================================${NC}"
