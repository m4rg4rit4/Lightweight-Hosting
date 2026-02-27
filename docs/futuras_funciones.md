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
- **Herramienta de Control:** Despliegue de un administrador de base de datos extra-ligero (ej. Adminer, ya que phpMyAdmin es más pesado).

## 4. Gestión de Archivos (FTP / SFTP)
- **Acceso SFTP enjaulado (Chroot):** Como alternativa al uso de servicios puramente FTP como ProFTPD (que consumen memoria extra en background), se recomienda usar OpenSSH con configuración `ChrootDirectory` para cada usuario.
- **Gestor Web de Archivos (File Manager):** Interfaz ligera desde el propio panel para subir, borrar y descomprimir `.zip` sin necesidad de cliente externo.

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

- [ ] **Fase 1: Infraestructura de Tareas (Motor)**
  - [ ] Crear tabla de base de datos `sys_tasks` (id, user_id, action, payload, status, created_at, processed_at).
  - [ ] Desarrollar `server.php` (ejecutado por root vía cron cada minuto) para leer e interpretar `sys_tasks`.

- [ ] **Fase 2: Módulo de Usuarios y Permisos**
  - [ ] Handler en `server.php` para la acción `create_user`: generar usuario local de Linux, definir contraseñas y preparar el `ChrootDirectory` de SFTP.

- [ ] **Fase 3: Módulo de Web y PHP**
  - [ ] Handler en `server.php` para la creación de pools PHP-FPM (`ondemand`).
  - [ ] Handler para generar `<VirtualHost>` en Apache2, habilitando Proxy a ese pool FPM y recargando Apache (graceful).

- [ ] **Fase 4: Módulo de Bases de Datos**
  - [ ] Ajustar la configuración `my.cnf` inicial para entornos de 1GB RAM (deshabilitar `performance_schema`).
  - [ ] Handler en `server.php` para invocar sentencias SQL (`CREATE DATABASE`, `GRANT PRIVILEGES`) de forma segura.

- [ ] **Fase 5: Módulo de SSL y Dominios**
  - [ ] Inclusión de peticiones estáticas `.well-known/acme-challenge` para certbot.
  - [ ] Handler para disparar la ejecución de Certbot vía task y actualizar el VirtualHost con las rutas a los certificados Let's Encrypt.
