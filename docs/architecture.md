# Arquitectura del Sistema de Hosting Custom - Versión Ultra-Ligera (Debian 13)

## Introducción
El objetivo es crear un panel de control y sistema de instalación **extremedamente ligero**, diseñado específicamente para VPS de entrada con recursos muy limitados:
- **CPU**: 1 vCore
- **RAM**: 1GB
- **Disco**: 10GB (Incluyendo el Sistema Operativo)

## Componentes del Sistema

### 1. Instalador Core (Minimalista)
- Script en Bash puro sin dependencias externas pesadas.
- **Gestión de Hostname**: Configuración automática de FQDN y resolución en `/etc/hosts`.
- **Estabilidad**: Creación de 1GB de SWAP persistente para evitar OOM durante instalaciones pesadas.
- **Limpieza de Disco**: Configuración de `dpkg` y `apt` para omitir documentación (`man`, `doc`) y limpieza de caché sistemática.
- **Instalador Inteligente**: Detecta instalaciones previas y reutiliza la configuración de `config.php` (DB credentials, email). Evita la duplicación de cronjobs en actualizaciones.
- **Sistema de Entrega**: Descarga de un paquete consolidado `hosting.tar.gz` (comprimido con Gzip) que contiene el código fuente de la interfaz y el motor.

### 2. Capa de Servidor Web (Optimizado para 1GB)
- **Apache2 + MPM Event**: Configuración agresiva para bajo consumo (límites estrictos en `MaxRequestWorkers`).
- **Aislamiento de PHP**: PHP desactivado globalmente en Apache. Solo se habilita explícitamente sitio por sitio o mediante handlers específicos.

- **Entorno de Administración (Puertos 8080/8090)**:
  - **Puerto 8080**: Acceso vía **HTTP plano** (siempre sin SSL) para compatibilidad y configuración inicial.
  - **Puerto 8090**: Acceso vía **HTTPS (SSL)**. El motor securiza este puerto automáticamente en cuanto el dominio principal obtiene su certificado Let's Encrypt.
  - **Interfaz Web (index.php)**: Panel minimalista para el administrador que permite:
  - Listar dominios existentes y su estado (Activo/Inactivo).
  - Activar/Desactivar sitios (vía `a2ensite`/`a2dissite`).
  - Añadir nuevos dominios definiendo el `DocumentRoot` y activación de PHP.
- **Base de Datos Dedicada**: Base de datos `dbadmin` con las siguientes tablas:
  - `sys_sites`: Registro maestro de los dominios instalados.
  - `sys_tasks`: Cola de tareas para el motor de procesamiento.
  - `sys_databases`, `sys_backups`, `sys_settings`: Gestión integral de recursos y configuración.
- **Gestión DNS Jerárquica (Plesk Style)**: 
  - Agrupación automática de subdominios y sitios bajo dominios raíz.
  - Panel unificado de zona para visualizar registros DNS y servicios vinculados (Web, BBDD, Archivos) en un solo lugar.
  - Exportación de zonas en formato BIND9 integrada.
- **Acceso a Datos**: Archivo `/var/www/admin_panel/config.php` que incluye una función centralizada `getPDO()`.
- **Gestor DB**: **Adminer** integrado para mantenimiento directo de tablas.

## Gestión de Sitios y SSL

El sistema permite la creación y gestión de hosts virtuales de Apache:
- **Apache 2.4**: Servidor web principal optimizado con `mpm_event` y `php-fpm`.
- **PHP 8.2+**: Procesado vía PHP-FPM en modo `ondemand` para minimizar el consumo de memoria.
- **MariaDB**: Base de datos de administración y para usuarios.
- **Certbot**: Gestión automatizada de certificados SSL vía Let's Encrypt.
- **Aislamiento**: Cada sitio tiene su propio `DocumentRoot`.
- **PHP Individual**: Activación/desactivación de PHP por sitio. Al desactivar PHP en un sitio, Apache deja de procesar archivos `.php` para ese dominio mediante la eliminación del `SetHandler` en su vhost, requiriendo un `reload`.
- **Estado del Sitio**: Los sitios pueden marcarse como "Inactivos", lo que ejecuta `a2dissite` y un `reload` de Apache, deshabilitando el acceso por completo.
- **SSL (Let's Encrypt)**: Soporte integrado para solicitar y configurar automáticamente certificados SSL gratuitos.
  - **Puerto 8090 Seguro**: Al emitir el SSL para el dominio principal, el motor securiza automáticamente el puerto 8090 del panel de administración, manteniendo el puerto 8080 siempre en HTTP para evitar bloqueos por errores de protocolo.
  - **Pre-verificación DNS**: Validación mediante un resolver externo (Google 8.8.8.8) para asegurar la propagación antes de invocar Certbot, evitando fallos y rate-limits.
  - **Sincronización**: Los cambios en la configuración (ej. activar/desactivar PHP) se aplican automáticamente tanto al VirtualHost HTTP como al HTTPS (`-le-ssl.conf`).
  - **Ciclo de Vida**: Al eliminar un sitio, el sistema revoca y limpia automáticamente los certificados en Certbot.


### 4. Motor de Procesamiento (Estilo ISPConfig)
- **Cola de Tareas (DB)**: La interfaz web no ejecuta comandos directamente. En su lugar, escribe las configuraciones deseadas en tablas de la base de datos `dbadmin` (ej: `sys_tasks`).
- **Cron de PHP**: Un script PHP (`server.php`) ejecutado por cron cada minuto (o vía daemon ligero) con permisos de root.
- **Flujo de Ejecución**:
  1. El cron lee las tareas pendientes (`status = 'pending'`).
  2. Ejecuta los cambios en el sistema (crear directorios, escribir archivos de Apache, reiniciar servicios).
  3. Actualiza el registro indicando éxito o error (`status = 'success/error'`).
- **Ventajas**: Mayor seguridad (la web no necesita sudo), trazabilidad de errores y capacidad de reintento.

### 5. Entorno de Aplicación
- **PHP-FPM (Modo OnDemand)**: 
  - Procesos creados solo bajo demanda y eliminados tras inactividad.
  - Conexión vía Unix Sockets (`/run/php/php*-fpm.sock`).

### 5. Base de Datos (Low Memory Profile)
- **MariaDB**: Optimizada para 1GB RAM.
  - `performance_schema = OFF` (Ahorro de ~80MB RAM).
  - `innodb_buffer_pool_size = 128M`.

### 6. Seguridad
- **Firewall**: `ufw` configurado con puertos 22 (SSH), 80 (HTTP), 443 (HTTPS), 8080 (Admin HTTP) y 8090 (Admin HTTPS).
- **Hardening DB**: Eliminación automática de usuarios anónimos, DB test y configuración de password segura para root.

## Roadmap Actualizado
1. [x] Script de preparación de Debian (SWAP, Hostname, Limpieza).
2. [x] Automatización de Apache2 (MPM Event) + PHP-FPM (OnDemand).
3. [x] Panel de administración aislado en puerto 8080 y 8090 (SSL).
4. [x] Instalación de Adminer integrada.
5. [x] Gestión de activación/desactivación de sitios (`a2ensite`/`a2dissite`).

