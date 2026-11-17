# Dominio comercial USB Stock

La aplicación incluye catálogo, CSV del proveedor con revisión y aplicación, administración comercial, solicitudes públicas y cotizaciones PDF con versiones históricas. Las migraciones son aditivas; no ejecutar `migrate:fresh` en desarrollo o producción.

Los precios de venta e impresión son manuales hasta confirmar las escalas. `legacy_v1` calcula subtotal de cantidad × (venta + impresión), IVA sobre subtotal, resta descuento y agrega envío. Cada emisión conserva snapshot y PDF privado; una revisión no elimina versiones.

La configuración comercial debe contener emisor y condiciones reales. `SUPPLIER_FTP_ENABLED`, `SUPPLIER_SYNC_ENABLED` y `SUPPLIER_SYNC_AUTO_APPLY` quedan desactivados por defecto. `COMMERCE_NOTIFY_EMAIL` es opcional y utiliza Outbox. No configurar destinatarios o credenciales de terceros sin autorización.

Validación: `php artisan test --compact`; pruebas MariaDB exclusivamente con `php tests/Support/run_mariadb_tests.php` y archivo privado `.env.testing.mariadb`. El runner exige base y usuario dedicados y comprueba la finalización de seis pruebas. `npm run build`, `composer validate --strict` y `php vendor/bin/pint --dirty --test` completan los controles locales.

La documentación operativa canónica está en `../xtras/docs`: AVANCE_DESARROLLO_INTENSIVO, RECONOCIMIENTO_SISTEMAS_USBSTOCK, ARQUITECTURA_COMERCIAL_USBSTOCK, REGLAS_COMERCIALES_USBSTOCK, OPERACION_COMERCIAL_USBSTOCK y PREPARACION_IONOS_USBSTOCK. Esa carpeta está fuera de este repositorio; conservarla en el respaldo del proyecto.

Pendientes de producción: reglas automáticas, datos comerciales reales, privacidad/retención, aceptación del PDF, integración FTP/SMTP autorizada, migración ensayada, licencias y despliegue. No hubo push ni despliegue durante la implementación.
