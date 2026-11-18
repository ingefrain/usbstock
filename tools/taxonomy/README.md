# Revisión local de taxonomía y navegación

Procedimiento específico de USB Stock, 18/11/2026. [Guía completa fuera de Git](../../../xtras/docs/CATEGORIAS_NAVEGACION_WHATSAPP_USBSTOCK.md). No ejecutar contra otro entorno.

1. Restringir la carpeta `storage/app/private/taxonomy-20261118` a la cuenta de trabajo, SYSTEM y Administradores; sin herencia, enlaces ni permisos de grupos amplios. Los scripts rechazan permisos amplios y sobrescrituras.
2. `export.ps1`: exportación estática de tres tablas de `usbstock2`, en transacción READ ONLY con el cliente/defaults-file aprobado. No ejecuta código heredado. El archivo de la corrida original ya existe: conservarlo.
3. `backup.ps1 -Stage before`: DDL, filas y hashes privados de las tablas afectadas y protegidas. Comprobar el manifiesto antes de DDL/DML.
4. Revisar `php artisan migrate --pretend` y el destino. `php tools/taxonomy/local.php migrate` aplica únicamente la migración aditiva de categorías bajo guardas de entorno.
5. `php tools/taxonomy/local.php preview`: revisar el JSON privado completo. `apply` exige que el nuevo plan coincida, aplica en transacción y repite para comprobar idempotencia. `verify` informa conteos sin cambios.
6. PHPUnit usa SQLite en memoria; `php tests/Support/run_mariadb_tests.php` usa exclusivamente la base y cuenta de prueba dedicadas. Nunca ejecutar `migrate:fresh` sobre desarrollo.
7. `php tools/taxonomy/local.php browser` genera una sesión local temporal de administrador y la lista pública esperada en la carpeta privada. No imprime la cookie. `node tools/taxonomy/browser.cjs` revisa Edge contra Laravel existente en 8000 mediante proxy 4390; `--resume` conserva grupos ya aprobados. No hay envíos comerciales, cambios de catálogo o llamadas corporativas reales. JSON de asesores simulado solo mediante interceptación del navegador, con nombres de QA. Cierra sesión, borra el acceso temporal y detiene su proxy al terminar correctamente.
8. `backup.ps1 -Stage after`: comparar hashes de productos, variantes, documentos, cotizaciones, versiones, solicitudes y outbox. La única diferencia de dominio autorizada es taxonomía más revisión/auditoría y registro de migración. Conservar ambos respaldos; no restaurar destructivamente.

Las pruebas de navegador bloquean todas las salidas no locales, incluso fotografías corporativas y navegación WhatsApp. La suite no certifica el servicio externo ni genera métricas reales. Las pruebas fallidas conservan diagnóstico y sesión temporal para su revisión; cerrar esa sesión al terminar antes de retirar el archivo de acceso.

La configuración corporativa es privada, desactivada por defecto y distinta de FTP. Consultar `.env.example` y `config/commerce.php`. No copiar claves heredadas ni imprimir `.env`.
