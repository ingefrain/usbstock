# Catálogo real de octubre en desarrollo

Herramientas operativas para la importación autorizada del 21 de octubre de 2026. No son seeders ni pruebas automatizadas. Solo aceptan Laravel `local`, MariaDB `usbstock_dev` en `127.0.0.1:3307`, administrador existente 1 y todas las integraciones desactivadas. Rechazan configuración cacheada, URL, socket, prefijos y conexiones alternativas antes de abrir PDO. No cambian `.env`.

La fase premium anterior está en `b9f1625`. Estas herramientas conservan diseño, cálculos y plantilla PDF oficial. Informe completo: `E:/proyectos/usbstock/xtras/docs/PRIMERA_IMPORTACION_DESARROLLO_USBSTOCK.md` (fuera del repositorio Git de `site`).

## Procedimiento autorizado

Ejecutar desde `E:/proyectos/usbstock/site`, con PHP `C:/xampp/php/php.exe`. No volver a importar para revisar las pantallas: el catálogo ya está cargado.

1. `powershell -NoProfile -File tools/development-catalog/backup.ps1`: verifica destino e integraciones; genera SQL sin DROP, respalda privados pertinentes y restaura en una base **nueva** `usbstock_restore_oct_<UTC>`. Compara dumps por SHA-256 y cada archivo restaurado. No borra esa base. Rechaza objetos especiales no contemplados. Excluye QA, temporales PDF y su propio directorio.
2. `php tools/development-catalog/import.php preflight`: backup verificado, fuente exacta, migraciones y entorno.
3. `php tools/development-catalog/import.php preview`: única vista previa persistida mediante `SupplierSync`, política CSV v2, comparación contra marzo y copia privada sin alterar el original. Si ya existe, se detiene.
4. Revisar `import-run.json` privado. `php tools/development-catalog/import.php apply` utiliza esa corrida. Rechaza errores/anomalías; no aprueba cambios importantes ni desactiva ausentes. La repetición de una corrida completada es idempotente.
5. `export-photo-map.ps1` lee solamente el mapeo histórico autorizado, en transacción de solo lectura. No ejecutar PHP heredado. `php tools/development-catalog/photos.php inspect` verifica archivos y correspondencias exactas. `php tools/development-catalog/photos.php attach 5` incorpora lotes reanudables mediante `LegacyProductPhoto`, `DocumentService` y `ProductThumbnail`. Repetir hasta el mensaje de finalización; conserva fotos personalizadas. El servicio verifica el hash original en cada nueva copia. La fuente secundaria es referencia, no reemplazo automático.
6. `php tools/development-catalog/publish.php 10`: publicación local por lotes usando el controlador administrativo; repetir hasta el informe final. Solo contenido válido de esta corrida, con auditoría explícita. No autoriza publicación en producción.
7. `php tools/development-catalog/verify.php`: compara los 872 registros con la base, verifica hashes originales/WebP/miniaturas, idempotencia, integraciones y outbox. No imprime costos ni credenciales.

Windows suspendió algunos procesos prolongados de copia/publicación en esta sesión; los lotes acotados permitieron completar y reanudar sin duplicados. No detener servidores PHP ajenos a estas herramientas.

## Revisión comercial con datos ficticios

`review-context.php` valida productos reales publicados; `browser-session.php` prepara una sesión privada temporal del administrador existente sin leer ni cambiar su contraseña. **`browser-access.json` es un secreto de sesión**: no mostrarlo, compartirlo ni copiarlo a Git. La sesión se usa únicamente en loopback y se cierra al finalizar la revisión.

Con el Laravel local existente en 8000, `node tools/development-catalog/review.cjs --reuse-laravel` ejecuta Edge/Playwright a través de un proxy loopback 4390 que sirve estáticos de forma estable. Conserva y reutiliza el servidor del usuario; solo cierra su proxy. Sin esa opción puede iniciar un PHP propio en 4391. `--existing-server` accede directamente a 8000, pero durante esta revisión hubo entregas intermitentes incompletas de CSS con el servidor integrado de Windows.

**Crea solicitudes, vendedor, cliente y cotización ficticios en desarrollo**, además de dos versiones PDF; requiere la autorización de esta tarea. No es una suite que deba ejecutarse en CI ni una prueba redirigida a desarrollo. No repetir sin revisar los registros ya creados. `--resume` omite grupos ya aprobados: revisar primero los efectos de cualquier grupo fallido antes de reanudarlo. `--resume --refresh-public` renueva exclusivamente las capturas públicas de una revisión ya completada; no crea operaciones comerciales. Solo completa datos de emisor si están vacíos, señalándolos como prueba sin validez comercial. Bloquea peticiones externas y no envía mensajes.

`verify-commercial.php` comprueba la persistencia de esas dos solicitudes y la cotización en una transacción de solo lectura. `pdf-review.php b1` (o b2/b3) renderiza una propuesta privada desde el snapshot de la versión 2 y sus fotos verificadas. **El PDF oficial aún no dibuja fotografías**, aunque las conserva en el histórico; las propuestas sí las muestran. No se activa ninguna plantilla ni se modifica un PDF emitido.

Las suites existentes mantienen su aislamiento:

```powershell
php artisan test --compact
php tests/Support/run_mariadb_tests.php
composer validate --no-check-publish
composer check-platform-reqs
php vendor/bin/pint --test app/Commerce/DevelopmentCatalogEnvironment.php tests/Feature/DevelopmentCatalogEnvironmentTest.php tools/development-catalog
npm run build
```

SQLite en memoria para la suite general; MariaDB `usbstock_test` con cuenta dedicada para concurrencia. No cambiar sus destinos ni credenciales. No ejecutar `migrate:fresh` en desarrollo.

## Evidencia y límites

Todo el material de datos está en `storage/app/private/development-import/october-2026`, excluido de Git y fuera del directorio público. Incluye backup/restauración, CSV, mapeos, informes, capturas y PDF ficticios. No copiar privados a `public`. La sesión temporal se cerró y su archivo de acceso fue eliminado.

**Permiso Windows pendiente:** la comprobación final encontró concesiones locales amplias (Authenticated Users/Users). Windows rechazó cambiarlas por falta de privilegios, incluso en la ejecución autorizada; `Set-Acl` solicitó SeSecurityPrivilege e `icacls` devolvió acceso denegado. No se cambiaron propietarios ni se ampliaron permisos. Desde PowerShell **como administrador**, ejecutar:

```powershell
Set-Location E:\proyectos\usbstock\site
.\tools\development-catalog\secure-evidence.ps1
.\tools\development-catalog\secure-evidence.ps1 -VerifyOnly
```

El script restringe solo esta carpeta de evidencias y sus backups, preserva las cuentas nominativas/SYSTEM/administradores, no modifica credenciales ni datos, y exige `verified=true`. El backup reproducible ahora se detiene antes de generar SQL si no consigue comprobar esos permisos. `permissions-verification.json` registra el estado observado; no se presume que esté resuelto.

Los 361 modelos publicados son de referencia local; 354 tienen portada y 7 carecen de ella. La banda de desarrollo solo aparece en `local`; QA conserva su banda diferenciada. Taxonomía, capacidades, vigencia comercial de fotografías y aprobación de producción quedan pendientes. La fórmula `legacy_v1` y plantilla `usbstock-2-letter` no cambian; nuevas emisiones Letter vertical, históricos preservados. No hay FTP, Scheduler, Telegram, correo comercial, push ni despliegue en este procedimiento.
