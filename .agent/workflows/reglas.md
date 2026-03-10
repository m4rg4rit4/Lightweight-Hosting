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
- **Idempotencia del Instalador**: El script `install.sh` debe ser re-ejecutable. Debe detectar valores previos (FQDN, Email, contraseñas, gestor de BD) y persistir el acceso a MariaDB para evitar bloqueos en actualizaciones sucesivas.

# Integridad del Instalador
- **Sincronización**: Cada vez que se cree un nuevo archivo esencial (`.php`, `.template`, etc.) en `src/admin` o `src/engine`, es OBLIGATORIO actualizar el script `install.sh` para que incluya la descarga y copia de dicho archivo. De lo contrario, las nuevas instalaciones o actualizaciones fallarán por falta de dependencias.

# Gestión de Repositorio
- **GitHub**: Es OBLIGATORIO subir todos los cambios al repositorio de GitHub al finalizar cada sesión de trabajo o tras implementar una funcionalidad clave.
- **Procedimiento de Subida Seguro (Windows/PowerShell)**:
  1. Ejecutar comandos de forma individual (evitar `&&` que puede fallar en ciertos shells).
  2. `git add .`
  3. `git commit -m "Mensaje en ASCII"` (Evitar tildes o caracteres especiales en el commit si el shell no está en UTF-8 para prevenir errores de parsing).
  4. `git push origin main`

# Importante, actualizar siempre la documentación