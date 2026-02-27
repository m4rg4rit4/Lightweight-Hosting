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
BUNDLE_URL="https://github.com/m4rg4rit4/Lightweight-Hosting/archive/refs/heads/main.tar.gz"
TEMP_DIR="/tmp/hosting_install"
ADMIN_PATH="/var/www/admin_panel"
ENGINE_PATH="/usr/local/bin/hosting"

echo -e "${YELLOW}Descargando paquete de hosting desde GitHub...${NC}"

# Limpieza de restos previos
rm -rf $TEMP_DIR /tmp/hosting.tar.gz
mkdir -p $TEMP_DIR

# Descarga
curl -L $BUNDLE_URL -o /tmp/hosting.tar.gz 2>/dev/null

if [ ! -f "/tmp/hosting.tar.gz" ]; then
    echo -e "${RED}Error: No se pudo descargar el archivo .tar.gz desde GitHub.${NC}"
    exit 1
fi

echo -e "${YELLOW}Extrayendo archivos y distribuyendo en el sistema...${NC}"

# Extraer el tar.gz quitando el primer nivel de directorio (Lightweight-Hosting-main/)
tar -xzf /tmp/hosting.tar.gz --strip-components=1 -C $TEMP_DIR

# Asegurar que los directorios de destino existen
mkdir -p $ADMIN_PATH
mkdir -p $ENGINE_PATH

# Copiar contenido de la interfaz (src/admin)
if [ -d "$TEMP_DIR/src/admin" ]; then
    echo -e "${YELLOW}Copiando interfaz (protegiendo config.php)...${NC}"
    # Usamos rsync para copiar todo EXCEPTO el config.php, así no borramos las credenciales generadas
    if command -v rsync >/dev/null 2>&1; then
        rsync -av --exclude='config.php' $TEMP_DIR/src/admin/ $ADMIN_PATH/
    else
        # Fallback si no hay rsync: copiar todo y restaurar el config si el instalador lo acaba de crear
        # o simplemente usar un loop
        for file in $TEMP_DIR/src/admin/*; do
            filename=$(basename "$file")
            if [ "$filename" != "config.php" ]; then
                cp -rf "$file" "$ADMIN_PATH/"
            fi
        done
    fi
    echo -e "${GREEN}- Interfaz de administración actualizada en $ADMIN_PATH${NC}"
else
    echo -e "${RED}- Error: No se encontró la carpeta src/admin en el paquete.${NC}"
fi

# Copiar contenido del motor (src/engine)
if [ -d "$TEMP_DIR/src/engine" ]; then
    cp -r $TEMP_DIR/src/engine/* $ENGINE_PATH/
    echo -e "${GREEN}- Motor de tareas actualizado en $ENGINE_PATH${NC}"
else
    echo -e "${RED}- Error: No se encontró la carpeta src/engine en el paquete.${NC}"
fi

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
