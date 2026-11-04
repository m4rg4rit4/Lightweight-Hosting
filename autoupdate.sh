#!/bin/bash
# Autoupdate script for Lightweight-Hosting

REPO_RAW=${LWH_HOSTING_REPO_RAW:-https://raw.githubusercontent.com/m4rg4rit4/Lightweight-Hosting/main}
VERSION_LOCK="/var/www/admin_panel/config.php"

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Obtener version local desde config.php
if [ -f "$VERSION_LOCK" ]; then
    LOCAL_VERSION=$(grep "SYSTEM_VERSION" "$VERSION_LOCK" | cut -d"'" -f4)
else
    LOCAL_VERSION="0.0.0"
fi

# Obtener version remota desde GitHub
REMOTE_VERSION=$(curl -fsSL "$REPO_RAW/VERSION" | tr -d '[:space:]')

if [ -z "$REMOTE_VERSION" ]; then
    echo -e "${RED}Error: No se pudo obtener la versión remota.${NC}"
    exit 1
fi
if ! [[ "$REMOTE_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || ! [[ "$LOCAL_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo 'Error: formato de versión no válido.' >&2
    exit 1
fi

echo -e "${YELLOW}Comprobando actualizaciones de Lightweight-Hosting...${NC}"
echo -e "Versión local:  ${GREEN}$LOCAL_VERSION${NC}"
echo -e "Versión remota: ${GREEN}$REMOTE_VERSION${NC}"

# A stale mirror/cache must never downgrade an already installed release.
if dpkg --compare-versions "$REMOTE_VERSION" gt "$LOCAL_VERSION"; then
    echo -e "${YELLOW}¡Nueva versión detectada! Iniciando actualización...${NC}"
    
    TEMP_INSTALL=$(mktemp /tmp/lwh-update-XXXXXXXX) || exit 1
    trap 'rm -f "$TEMP_INSTALL"' EXIT
    curl -fsSL "$REPO_RAW/install.sh" -o "$TEMP_INSTALL" || exit 1
    
    if [ -f "$TEMP_INSTALL" ]; then
        bash -n "$TEMP_INSTALL" && bash "$TEMP_INSTALL" /update /silent || exit 1
        echo -e "${GREEN}Actualización completada a la versión $REMOTE_VERSION.${NC}"
    else
        echo -e "${RED}Error al descargar el instalador.${NC}"
    fi
else
    echo -e "${GREEN}El sistema ya está actualizado.${NC}"
fi
