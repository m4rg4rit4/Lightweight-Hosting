# 🚀 Lightweight-Hosting

**Sistema de hosting ultra-ligero** diseñado para hardware extremadamente limitado (1vCore, 1GB RAM) ejecutándose sobre **Debian 13**.

Este componente es el núcleo del ecosistema **Lightweight**.

## Estado de las actualizaciones

El instalador de **v1.4.48** descarga sus componentes desde ese tag.
Autoupdate congelado (salida 0, sin red ni instalaciones) hasta validar
la release en host13 antes de desplegar en producción.

Se restaura el modelo ligero anterior: las webs usan **un único pool PHP-FPM
compartido como www-data**, en modo ondemand. No se crean usuarios ni pools por
sitio ni se amplían los límites de concurrencia anteriores.

Se conservan las correcciones independientes de autenticación, CSRF, validación
de rutas, TLS DNS y comprobación de backups. Los backups nuevos abarcan el
contenedor completo de aplicaciones con public anidado, conservan sus enlaces
internos en forma portable y no aplican retención si falla la copia nueva.

La recuperación de las máquinas de prueba usa sus configuraciones y metadatos
anteriores. Las actualizaciones no se reanudan hasta validar el perfil compartido.

## 🚀 Instalación Rápida desde GitHub

Para instalar el panel de hosting en un servidor **Debian 13** limpio, ejecuta el siguiente comando como **root**:

```bash
curl -fsSL https://raw.githubusercontent.com/m4rg4rit4/Lightweight-Hosting/v1.4.48/install.sh -o install.sh && bash install.sh
```

Alternativamente, puedes realizar la instalación clonando el repositorio:

```bash
git clone --branch v1.4.48 --depth 1 https://github.com/m4rg4rit4/Lightweight-Hosting.git
cd Lightweight-Hosting
chmod +x install.sh
sudo ./install.sh
```

## 📖 Documentación

La documentación técnica completa, incluyendo arquitectura, gestión de tareas, SSL y guías de instalación, se encuentra centralizada en:

👉 **[Documentación Principal del Proyecto](../Documentacion/README.md)**

---
Desarrollado con ❤️ para el proyecto [Lightweight](https://github.com/m4rg4rit4/Lightweight).

## Tareas DNS y asignación de zonas

Cada escritura DNS se guarda antes de enviarse, con una tarea por servidor. El motor procesa las tareas en orden y conserva los fallos para reintentarlos. La página **Tareas** muestra el estado por nodo; guardar un cambio confirma su encolado y la publicación se confirma después de la recarga de BIND.

En el detalle de una zona se puede **Asignar a este servidor**, **Cambiar servidor** y **Liberar zona**. Se muestra el propietario y la versión en cada DNS, además de las actualizaciones pendientes. Liberar conserva los registros y el sitio web. Si los nodos discrepan, **Confirmar asignación en ambos DNS** propaga la decisión del principal.

Todos los Hosting deben configurar el mismo primer DNS como autoridad de asignación. Las reclamaciones se comprueban contra la versión mostrada al usuario; una decisión concurrente se marca como conflicto y no se replica ni bloquea los cambios posteriores. Las réplicas llevan una versión para impedir que un reintento antiguo deshaga una transferencia o liberación posterior.

Actualizar primero Go en todos los DNS y después el panel, las utilidades, el motor y los hooks DNS-01. Las tablas se amplían sin descartar las tareas existentes. El cron de `server.php` debe permanecer activo.

Validación local: `php tests/dns_outbox_test.php` y comprobación de sintaxis con `php -l` en los archivos modificados. Las pruebas simulan fallos de nodo, orden de operaciones, reintentos, fan-out de decisiones y conflictos.

En la versión 1.4.42, dejar vacía la IP al crear una zona usa la IP pública del Hosting. La opción **Solo zona DNS** omite A/AAAA y `www` de forma explícita. El editor SOA permite crear la fila si falta, además de editarla cuando existe. La creación de un sitio web y la creación de su zona DNS siguen siendo operaciones distintas; la delegación en el registrador debe apuntar a los NS configurados.
