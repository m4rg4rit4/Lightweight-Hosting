---
description: Reglas y restricciones de hardware del proyecto
---

# Restricciones de Hardware (Hard Limits)
- **CPU**: 1 vCore - Priorizar procesos eficientes.
- **RAM**: 1GB - Usar modos `ondemand`, desactivar `performance_schema`, evitar procesos residentes pesados.
- **Disco**: 10GB (Total) - Limpieza agresiva de caché de paquetes (`apt clean`), evitar logs masivos, no instalar documentación/man-pages.
- **OS**: Debian 13

# Estilo de Código y Patrones
- Scripts en Bash puro para instaladores.
- **Patrón de Procesamiento**: Desacoplar la Web del Sistema. La Web escribe en DB (`sys_tasks`), un Cron de PHP ejecutado por root procesa y aplica los cambios.
- **Seguridad**: Ningún proceso web (www-data) debe tener permisos de `sudo`. El motor de tareas (`server.php`) es el único con privilegios elevados.
- Mantener compatibilidad con UTF-8 siempre (Powershell/Linux).
- Configuración de Apache2 con mod_proxy_fcgi y aislamiento de PHP.
