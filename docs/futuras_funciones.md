# Estudio de Opciones Básicas del Hosting y Funciones Futuras

Este documento analiza las funciones fundamentales que debe incluir el panel de hosting, adaptado de forma estricta a las limitaciones técnicas (1 vCore, 1GB RAM, 10GB Disco) y a la arquitectura de procesamiento de tareas descrita en `reglas.md`.

## 1. Gestión de Dominios y DNS
- **Añadir/Eliminar Dominios:** Creación del registro en el sistema con su correspondiente directorio público (`DocumentRoot`).
- **Configuración de Registros DNS:** Posibilidad de gestionar zonas (A, CNAME, TXT, MX). En un entorno de 1GB de RAM, se recomienda depender de un servidor DNS ligero o usar APIs externas (ej. Cloudflare) en lugar de un servidor Bind9 completo local.
- **Redirecciones:** Funcionalidad para redirigir tráfico de un dominio a otro a nivel de servidor web.

## 2. Gestión del Servidor Web (Apache2 + PHP)
- **VirtualHosts Automatizados:** Generación automática de los archivos de configuración de Apache para cada dominio, utilizando `mod_proxy_fcgi`.
- **Aislamiento PHP-FPM:** Cada cuenta dispondrá de su propio pool de procesos PHP-FPM.
  - *Restricción Hardware:* Obligatorio configurar en modo `ondemand` para evitar consumir los escasos 1GB de RAM cuando no haya peticiones.
- **Certificados SSL Automáticos:** Integración con Certbot (Let's Encrypt) para solicitar, desplegar y renovar certificados sin intervención manual.

## 3. Gestión de Bases de Datos (MariaDB)
- **Creación de DBs y Usuarios:** Interfaces para crear bases de datos, contraseñas y asignar permisos granulares.
- **Optimización de Recursos:** 
  - *Restricción Hardware:* El servicio MariaDB debe instalarse desactivando `performance_schema` y usando buffers pequeños para evitar penalizar la memoria global.
- **Herramienta de Control:** Soporte para **phpMyAdmin** (estándar completo) o **Adminer** (extra-ligero), seleccionable durante la instalación.

## 4. Gestión de Archivos (File Manager Web)
- **Gestor Web Integrado:** En lugar de implementar servicios pesados como FTP o complejos sistemas de SSH enjaulado, se optará por un gestor de archivos directamente en el panel de administración.
- **Carga Drag-and-Drop:** Interfaz moderna que permita arrastrar y soltar ficheros para subirlos al `DocumentRoot` del sitio.
- **Funciones Básicas:** Subir, eliminar, renombrar y descomprimir archivos `.zip` (para despliegues rápidos) sin necesidad de clientes externos como FileZilla.
- *Ventaja:* Ahorro sustancial de memoria RAM al no tener demonios de FTP/SSH adicionales corriendo en segundo plano.

## 5. Cuentas de Correo (Enfoque Ligero)
- **Redirecciones de Email (Forwarders):** Debido a que correr un stack de correo completo (Postfix + Dovecot + SpamAssassin + ClamAV) tumbaría un servidor de 1GB de RAM, la mejor opción base es ofrecer solo redireccionamiento a buzones externos (ej. hacia Gmail/Outlook) usando una configuración ligera de buzones virtuales en Postfix o similar.
- **Envío SMTP Externo:** Promover/Configurar el envío de correo usando servicios de terceros (Relays).

## 6. Monitoreo y automatizaciones (Cron)
- **Consumo de Recursos:** Visor del estado del servidor (Disco total < 10GB, RAM < 1GB).
- **Gestión de tareas Cron:** Opción de añadir tareas rutinarias.
  - *Restricción de Arquitectura:* Las tareas se añaden a `sys_tasks` (frontend sin permisos de root), y el motor `server.php` inyecta las rutinas en el crontab del usuario pertinente, respetando los privilegios aislados.

---

## Lista de Tareas para Implementación Futura (TODO)

Esta es la ruta de desarrollo para integrar las opciones descritas siguiendo la filosofía de separación (Frontend Web -> Base de datos -> Motor root PHP):

- [x] **Fase 1: Infraestructura de Tareas (Motor)**
  - [x] Crear tabla de base de datos `sys_tasks` (id, user_id, action, payload, status, created_at, processed_at).
  - [x] Desarrollar `server.php` (ejecutado por root vía cron cada minuto) para leer e interpretar `sys_tasks`.

- [ ] **Fase 2: Módulo de Gestión de Archivos (File Manager)**
  - [ ] Implementar visor de directorios en el panel de administración.
  - [ ] Desarrollar handler de subida (Upload) con soporte para arrastrar ficheros (Drag-and-Drop).
  - [ ] Integrar funciones de manipulación de archivos (borrar, renombrar, descomprimir) mediante el motor `server.php` para asegurar permisos de `www-data`.

- [x] **Fase 3: Módulo de Web y PHP**
  - [x] Handler en `server.php` para la creación de pools PHP-FPM (`ondemand`).
  - [x] Handler para generar `<VirtualHost>` en Apache2, habilitando Proxy a ese pool FPM y recargando Apache (graceful).

- [x] **Fase 4: Módulo de Bases de Datos**
  - [x] Ajustar la configuración `my.cnf` inicial para entornos de 1GB RAM (deshabilitar `performance_schema`).
  - [x] Handler en `server.php` para invocar sentencias SQL (`CREATE DATABASE`, `GRANT PRIVILEGES`) de forma segura.
  - [x] Interfaz de administración de bases de datos vinculada a sitios.

- [x] **Fase 5: Módulo de SSL y Dominios**
  - [x] Inclusión de peticiones estáticas `.well-known/acme-challenge` para certbot.
  - [x] Handler para disparar la ejecución de Certbot vía task y actualizar el VirtualHost con las rutas a los certificados Let's Encrypt.
  - [x] Rediseño de Gestión DNS jerárquica y agrupación de sitios (Plesk Style).
