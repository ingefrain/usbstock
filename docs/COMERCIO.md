# Dominio comercial USB Stock

La aplicación incluye catálogo, CSV del proveedor con revisión y aplicación, administración comercial, solicitudes públicas y cotizaciones PDF con versiones históricas. Las migraciones son aditivas; no ejecutar `migrate:fresh` en desarrollo o producción.

Los precios de venta e impresión son manuales hasta confirmar las escalas. `legacy_v1` calcula subtotal de cantidad × (venta + impresión), IVA sobre subtotal, resta descuento y agrega envío. Cada emisión conserva snapshot y PDF privado; una revisión no elimina versiones.

La configuración comercial debe contener emisor y condiciones reales. SUPPLIER_FTP_ENABLED, SUPPLIER_SYNC_ENABLED, SUPPLIER_SYNC_AUTO_APPLY y SUPPLIER_TELEGRAM_ENABLED quedan desactivados por defecto. COMMERCE_NOTIFY_EMAIL es opcional y utiliza Outbox. No configurar destinatarios o credenciales de terceros sin autorización.

Validación: `php artisan test --compact`; pruebas MariaDB exclusivamente con `php tests/Support/run_mariadb_tests.php` y archivo privado `.env.testing.mariadb`. El runner exige base y usuario dedicados y comprueba la finalización de siete pruebas. No ejecutar ambas suites a la vez. `npm run build`, `composer validate --strict` y `php vendor/bin/pint --dirty --test` completan los controles locales.

## Consolidación del 17/11/2026

Scheduler del proveedor: 00:00 America/Mexico_City, condicionado a habilitación explícita. En UTC, schedule:list muestra 06:00. Corrida persistida antes de descargar, bloqueo con vencimiento, clave diaria única y reintentos limitados. Los fallos aparecen en /admin/supplier-sync sin requerir archivo. Anomalías pendientes impiden aplicaciones automáticas posteriores; se aprueban o cierran con motivo y permiso. No se borran ausentes ni personalizaciones. Se conservan diferencias resumidas y CSV privado completo.

El cliente legado usa FTP convencional:21 y /FTP/Existencias-ftp-precio.csv. El nuevo descargador exige protocolo explícito ftp/ftps-explicit/ftps-implicit, sin degradación automática. FTPS disponible en el servidor: no verificado. El ensayo real preparado es `php artisan supplier:sync --fetch --dry-run --actor=ID_AUTORIZADO`, exclusivamente después de aprobación y configuración privada. No activa calendario, aplicación ni notificaciones.

La copia CSV local tiene 819 filas y estructura correcta, pero la validación completa detecta un duplicado idéntico y 17 descripciones vacías (801 filas válidas/18 errores). Se rechaza el archivo completo. Resolver con proveedor o mediante política aprobada antes de automatizar; no se cambiaron validaciones para ocultar estas anomalías.

Telegram reutiliza el cliente y Outbox existentes. supplier_sync_notifications conserva texto seguro y relación corrida/evento incluso antes de descargar; el resultado de entrega no cambia el resultado del catálogo. `supplier:notify UUID` reconcilia con Outbox solo después de habilitación aprobada; un worker activo puede enviar inmediatamente. Entrega incierta no se reintenta automáticamente. No hubo conexiones ni envíos reales.

Propuestas A corporativo, B catálogo y C minimalista en /admin/pdf-designs: solo local/testing y permisos quotes.view + commerce.configure. Parámetros items=1|3|10|35 y format=html|pdf. Preferencia de revisión auditada sin cambiar emisión. `php artisan quotes:design-samples` genera doce parejas HTML/PDF privadas sin sembrar datos. 63 páginas A4 renderizadas y revisadas, con fotos locales, tarjetas redondeadas, descuentos y condiciones extensas. La paginación de navegador difiere de mPDF; los PDF mPDF son la referencia de selección.

Los borradores admiten foto específica por variante y vista previa autorizada. Al emitir se congelan copias de las fotos y hashes por versión. La plantilla actual usbstock-1 todavía no las muestra y sigue intacta; no se regeneran versiones históricas. legacy_v1 no cambió. La cotización administrativa heredada captura precios manuales; las dos escalas divergentes quedan documentadas y probadas, sin selección automática.

Verificación: 230 pruebas generales/2,192 aserciones, más siete MariaDB/58; total 237 aprobadas/2,250. 24 migraciones aplicadas, 140 rutas y comparación dev/test sin diferencias en 364 columnas, 162 entradas de índices y 26 relaciones. Sin DEMO, cambios de credenciales, push ni despliegue. Nueva migración de trazabilidad conserva datos y bloquea rollback destructivo; cualquier reversión requiere revisión.

La documentación operativa canónica está en `../xtras/docs`: AVANCE_DESARROLLO_INTENSIVO, RECONOCIMIENTO_SISTEMAS_USBSTOCK, ARQUITECTURA_COMERCIAL_USBSTOCK, REGLAS_COMERCIALES_USBSTOCK, OPERACION_COMERCIAL_USBSTOCK y PREPARACION_IONOS_USBSTOCK. Consolidación: CONTRATO_FTP_SINCRONIZACION_USBSTOCK, TELEGRAM_SINCRONIZACION_USBSTOCK, PROPUESTAS_PDF_USBSTOCK, COMPATIBILIDAD_MPDF_USBSTOCK, REGLAS_COMERCIALES_PENDIENTES_USBSTOCK y ENSAYO_REAL_PROVEEDOR_USBSTOCK. Esa carpeta está fuera de este repositorio; conservarla en el respaldo del proyecto.

Pendientes de producción: reglas automáticas, datos comerciales reales, privacidad/retención, aceptación del PDF, integración FTP/SMTP autorizada, migración ensayada, licencias y despliegue. No hubo push ni despliegue durante la implementación.
