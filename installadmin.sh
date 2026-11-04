#!/bin/bash
set -eu
TEMP_INSTALL=$(mktemp /tmp/lwh-update-XXXXXXXX)
trap 'rm -f "$TEMP_INSTALL"' EXIT
curl -fsSL https://raw.githubusercontent.com/m4rg4rit4/Lightweight-Hosting/main/install.sh -o "$TEMP_INSTALL"
bash -n "$TEMP_INSTALL"
bash "$TEMP_INSTALL" /update /silent
