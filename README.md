# Lightweight-Hosting 🚀

Un sistema de hosting ultra-ligero diseñado para hardware extremadamente limitado (1vCore, 1GB RAM) ejecutándose sobre **Debian 13**.

## 🛠️ Características Principales
- **Apache2 MPM Event**: Optimizado para bajo consumo de memoria.
- **PHP-FPM OnDemand**: Los procesos de PHP solo se activan cuando hay tráfico.
- **MariaDB Low Memory Profile**: Desactivación de Performance Schema y optimización de buffers.
- **Arquitectura Segura**: El panel web solo escribe en base de datos. Un motor de tareas ejecutado por root procesa los cambios vía Cron.
- **Aislamiento**: Cada sitio puede tener su propia configuración (PHP, SSL via Let's Encrypt).
- **Gestión de Estados**: Posibilidad de activar/desactivar sitios completos y soporte individual para PHP.
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


