#!/bin/bash

# ====================================================================
# CONFIGURACIÓN
# ====================================================================
MIN_CONNECTIONS="5"         # Umbral de conexiones SYN_RECV para bloquear
APACHE_SERVICE="apache2"    # Nombre del servicio de Apache
APACHE_PORT="443"           # Puerto para verificar la respuesta de Apache
LOG_FILE="/var/log/sync.log" # Archivo de registro
# ====================================================================

# Función para registrar mensajes
log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> $LOG_FILE
}

# Función para verificar y restaurar Apache si es necesario
check_apache() {
    # 1. Verificar si el servicio de Apache está corriendo
    if ! systemctl is-active --quiet $APACHE_SERVICE; then
        log_message "❌ Apache ($APACHE_SERVICE) NO está corriendo. Intentando iniciar..."
        systemctl start $APACHE_SERVICE
        sleep 3
        if systemctl is-active --quiet $APACHE_SERVICE; then
            log_message "✅ Apache iniciado exitosamente."
        else
            log_message "❌ Falló el inicio de Apache. REVISAR MANUALMENTE."
        fi
        return
    fi

    # 2. Verificar si Apache responde en el puerto de prueba
    if ! curl -k -s --connect-timeout 5 https://localhost:$APACHE_PORT > /dev/null 2>&1; then
        log_message "⚠️ Apache está corriendo, pero NO responde en puerto $APACHE_PORT. Reiniciando..."
        systemctl restart $APACHE_SERVICE
        sleep 5
        if curl -k -s --connect-timeout 5 https://localhost:$APACHE_PORT > /dev/null 2>&1; then
            log_message "✅ Apache restaurado y respondiendo correctamente en puerto $APACHE_PORT."
        else
            log_message "❌ Apache sigue sin responder después del reinicio. REVISAR MANUALMENTE."
        fi
        return
    fi
}

# Ejecutar la verificación de Apache (Descomentar si es necesario, consume recursos ejecutar curl cada minuto)
check_apache

# ====================================================================
# BÚSQUEDA Y BLOQUEO DE IPs
# ====================================================================
# OPTIMIZACIÓN: 
# Usamos 'ss' en lugar de 'netstat' porque es más rápido y requiere menos memoria/CPU.
# Se eliminó la resolución DNS (host) ya que en un ataque de flood las peticiones DNS retrasan el script y consumen muchos recursos.

SUSPECT_IPS_WITH_COUNT=$(ss -nt state syn-recv "( sport = :$APACHE_PORT )" | awk 'NR>1 {print $5}' | sed 's/:[^:]*$//' | sort | uniq -c | awk -v min=$MIN_CONNECTIONS '$1 > min {print $2, $1}')

echo "$SUSPECT_IPS_WITH_COUNT" | while read ip count; do
    if [ -n "$ip" ]; then
        # Verificar si la IP ya está bloqueada (más rápido con iptables -C)
        if ! iptables -C INPUT -s "$ip" -j DROP 2>/dev/null; then
            # Registrar el bloqueo sin resolución DNS para máxima velocidad en mitigación
            log_message "🚫 IP BLOQUEADA: $ip ($count conexiones SYN_RECV)"
            
            # Añadir la regla DROP
            iptables -I INPUT -s "$ip" -j DROP
        fi
    fi
done
