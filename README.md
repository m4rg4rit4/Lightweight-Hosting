# 🚀 Lightweight-Hosting

**Sistema de hosting ultra-ligero** diseñado para hardware extremadamente limitado (1vCore, 1GB RAM) ejecutándose sobre **Debian 13**.

Este componente es el núcleo del ecosistema **Lightweight**.

## Endurecimiento del panel y del motor

Los endpoints comprueban sesión y CSRF antes de procesar acciones. El instalador
migra la contraseña del panel a un hash y protege el código y la configuración
contra escritura por PHP. Las conexiones DNS requieren HTTPS y certificados
válidos; los nodos con certificados autofirmados necesitan una CA de confianza.

La primera ejecución del motor migra los sitios existentes a usuarios y pools
PHP independientes, con sesiones privadas. Esta migración cambia propietarios y
permisos y reinicia Apache y PHP-FPM. Debe probarse antes en un servidor de ensayo
con copia recuperable. Los sitios conservan `.htaccess` activo: cambios en
reescrituras, redirecciones, cabeceras, caché y controles de acceso se aplican
sin regenerar el vhost. El perfil permite `All` con las opciones limitadas a
`Indexes,MultiViews,SymLinksIfOwnerMatch`; `FollowSymLinks` y `ExecCGI` no se
pueden activar desde un sitio. La migración adapta automáticamente las opciones
`FollowSymLinks` a `SymLinksIfOwnerMatch`, conservando una copia privada del
`.htaccess` anterior fuera del directorio público. Las demás reglas siguen vivas.

La asignación del handler PHP permanece bajo control del vhost. El módulo
pequeño `mod_lwh_guard` bloquea en la fase de fixups los proxies distribuidos
(`[P]`) y handlers de otros pools; un proxy inverso necesita configuración
administrativa específica. El instalador compila el módulo desde el código
incluido utilizando `apache2-dev`, sin añadir otro servicio en ejecución.
La referencia de compatibilidad es el vhost y el valor `allow_override` de
[ISPConfig 3.3.2p1](https://www.ispconfig.org/ispconfig/download/).
Los pools arrancan bajo demanda, con dos procesos por pool y un máximo global de
cuatro procesos FPM para el perfil de 1 GB. En servidores con más memoria, el
instalador calcula un límite global mayor y permite hasta diez procesos por pool.

Se admiten raíces públicas anidadas dentro del contenedor propio, por ejemplo
`/var/www/vhosts/example.com/public`. El aislamiento, las exportaciones y los
backups abarcan toda la aplicación, incluidos `storage`, `vendor` y su
configuración, conservando enlaces internos. Los backups nuevos incluyen formato
de archivo; las copias antiguas mantienen su restauración sobre la raíz pública.
Se validan todas las rutas antes de comenzar la migración de propietarios.

El update nocturno y los backups mantienen las 03:00 en la zona horaria del
servidor. El instalador y el motor comparten un bloqueo para evitar solaparse.
Una tarea interrumpida se marca con error para revisión, sin repetir operaciones
destructivas automáticamente. Las exportaciones se guardan fuera del directorio
público; los enlaces antiguos a `/downloads/` quedan bloqueados.

Los backups comprueban el volcado, la compresión y la subida antes de aplicar
retención. Un borrado remoto fallido conserva su registro. Las copias SQL usan
`--single-transaction` (consistencia para tablas transaccionales); archivos y
bases de datos no constituyen una instantánea atómica conjunta. La restauración
real con MariaDB, MEGA y servicios Debian debe validarse en el servidor de ensayo.

Validación local adicional: `python tests/security_http_test.py` (requiere PHP y
Node) y `php tests/backup_failure_test.php`, además de la prueba de cola DNS.

## 🚀 Instalación Rápida desde GitHub

Para instalar el panel de hosting en un servidor **Debian 13** limpio, ejecuta el siguiente comando como **root**:

```bash
curl -sSL https://raw.githubusercontent.com/m4rg4rit4/Lightweight-Hosting/main/install.sh | bash
```

Alternativamente, puedes realizar la instalación clonando el repositorio:

```bash
git clone https://github.com/m4rg4rit4/Lightweight-Hosting.git
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
