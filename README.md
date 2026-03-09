# Lightweight-Hosting 🚀

Un sistema de hosting ultra-ligero diseñado para hardware extremadamente limitado (1vCore, 1GB RAM) ejecutándose sobre **Debian 13**.

## 🛠️ Características Principales
- **Apache2 MPM Event**: Optimizado para bajo consumo de memoria.
- **PHP-FPM OnDemand**: Los procesos de PHP solo se activan cuando hay tráfico.
- **MariaDB Low Memory Profile**: Desactivación de Performance Schema y optimización de buffers.
- **Base de Datos Dedicada**: Base de datos `dbadmin` con las siguientes tablas:
  - `sys_sites`: Registro maestro de los dominios instalados.
  - `sys_tasks`: Cola de tareas para el motor de procesamiento.
  - `sys_databases`, `sys_backups`, `sys_settings`: Gestión de recursos y configuración global.
- **Gestión DNS Jerárquica (Plesk Style)**: 
  - Agrupación automática de subdominios y sitios bajo dominios raíz.
  - Panel unificado de zona para visualizar registros DNS y servicios vinculados (Web, BBDD, Archivos) en un solo lugar.
  - Exportación de zonas en formato BIND9 integrada.
- **Acceso a Datos**: Archivo `/var/www/admin_panel/config.php` que incluye una función centralizada `getPDO()`.
- **Aislamiento**: Cada sitio puede tener su propia configuración (PHP, SSL via Let's Encrypt).
- **Gestión de Estados**: Posibilidad de activar/desactivar sitios completos y soporte individual para PHP.
- **Soporte DNS Jerárquico**: Integración con [Lightweight-Hosting-DNS](https://github.com/m4rg4rit4/Lightweight-Hosting-DNS) con una interfaz profesional que agrupa subdominios bajo sus dominios raíz (estilo Plesk/ISPConfig).
- **Gestión Centralizada**: Panel único para administrar registros DNS, sitios web, bases de datos y archivos vinculados a un mismo dominio.
- **Backups Automáticos & Sync**: Programación de respaldos diarios/semanales con sincronización automática a **MEGA**.
- **Auto-Recuperación**: Capacidad para recrear sitios e infraestructura automáticamente a partir de backups en la nube.
- **Seguridad Endurecida**: Protección nativa contra Path Traversal, inyecciones de código y validación estricta de duplicados.
- **Doble Panel Admin**: Acceso vía puerto 8080 (HTTP) y 8090 (HTTPS) securizado automáticamente.

## 📋 Requisitos de Hardware
- **CPU**: 1 vCore (Prioridad en eficiencia).
- **RAM**: 1 GB.
- **Disco**: 10 GB (Optimizado con limpieza agresiva de caché).

## 🚀 Instalación
Para instalar el sistema en un servidor Debian 13 limpio, ejecuta el siguiente comando como root (o con sudo):

```bash
curl -sSL https://raw.githubusercontent.com/m4rg4rit4/Lightweight-Hosting/main/install.sh -o install.sh && chmod +x install.sh && sudo ./install.sh
```

Alternativamente, puedes clonar el repositorio:

```bash
git clone https://github.com/m4rg4rit4/Lightweight-Hosting.git
cd Lightweight-Hosting
chmod +x install.sh
sudo ./install.sh
```

## 🏗️ Estructura del Proyecto
- `src/admin`: Interfaz web de administración (PHP).
- `src/engine`: Motor de procesamiento de tareas (Background worker).
- `install.sh`: Script principal de instalación y optimización del SO.
- `installadmin.sh`: Script para despliegue y actualizaciones de la interfaz.

## 🤝 Repositorios Relacionados
- [Lightweight-Hosting-DNS](https://github.com/m4rg4rit4/Lightweight-Hosting-DNS) - Sistema de gestión de DNS para este panel.


