# Dominio comercial USB Stock

La aplicación incluye catálogo, CSV del proveedor con revisión y aplicación, administración comercial, solicitudes públicas y cotizaciones PDF con versiones históricas. Las migraciones son aditivas; no ejecutar `migrate:fresh` en desarrollo o producción.

Los precios de venta e impresión son manuales hasta confirmar las escalas. `legacy_v1` calcula subtotal de cantidad × (venta + impresión), IVA sobre subtotal, resta descuento y agrega envío. Cada emisión conserva snapshot y PDF privado; una revisión no elimina versiones.

La configuración comercial debe contener emisor y condiciones reales. SUPPLIER_FTP_ENABLED, SUPPLIER_SYNC_ENABLED, SUPPLIER_SYNC_AUTO_APPLY y SUPPLIER_TELEGRAM_ENABLED quedan desactivados por defecto. COMMERCE_NOTIFY_EMAIL es opcional y utiliza Outbox. No configurar destinatarios o credenciales de terceros sin autorización.

Validación: `php artisan test --compact`; pruebas MariaDB exclusivamente con `php tests/Support/run_mariadb_tests.php` y archivo privado `.env.testing.mariadb`. El runner exige base y usuario dedicados y comprueba la finalización de nueve pruebas. No ejecutar ambas suites a la vez. `npm run build`, `composer validate --strict` y `php vendor/bin/pint --dirty --test` completan los controles locales.

## Preparación del ensayo FTP real

supplier:trial hace preflight sin red por defecto. Requiere configuración privada en storage/app/private/supplier-trial/connection.env, creada desde config/supplier-trial.env.example sin sobrescribir archivos existentes. Host, usuario y contraseña todavía están pendientes. No introducir secretos en Git o argumentos.

Solo después de autorización expresa, supplier:trial --download --actor=1 --baseline=RUTA_PRIVADA_MARZO realiza dos consultas de metadatos y una descarga del archivo permitido. Fuerza lectura, inhibe avisos, verifica huellas del catálogo, guarda CSV/hash/informe comparativo y restaura la configuración temporal. No acepta --apply. supplier:sync --dry-run comparte el bloqueo de aplicación y supresión de avisos.

El cliente controla EPSV/PASV, tamaño/fecha remota antes y después, transferencia binaria y presupuesto de 120 s. Detiene metadata ausente, cambios, bytes incompletos, autenticación/certificado y timeout. Se corrigieron aliases de error ausentes en PHP 8.2 Windows. Tres handles por intento, cerrados en finally; un solo intento en el ensayo. No se validó conectividad real ni disponibilidad FTPS/SIZE/MDTM.

supplier:compare BASELINE CURRENT compara por SKU normalizado sin red ni consultas de catálogo. Guarda listas de SKU y resumen, sin costos individuales/textos. Diferencia cambios reales de orden/notación; invalida toda comparación si un CSV falla. La prueba con la copia histórica consigo misma dio cero diferencias, sin afirmar que exista una entrega actual.

Validación: 273 pruebas generales / 2,868 aserciones y 9 MariaDB / 864 aserciones; **282 aprobadas / 3,732 aserciones**, cero fallos. Las nueve MariaDB se omiten en la suite general y se ejecutan después en el runner aislado. Revalidación final de servicios modificados: 22 / 510, sin duplicar el total. Matriz de 14 escenarios de transferencia con catálogo intacto y sin avisos. Composer validate --strict, check-platform-reqs --no-dev, Pint y git diff --check aprobados. Esquema dev/test: 364 columnas, 170 entradas de índices, 26 claves foráneas y cero diferencias; desarrollo conserva 25 migraciones y 38 tablas.

Documentación canónica fuera de Git: ENSAYO_FTP_CONTROLADO_USBSTOCK.md, ENSAYO_REAL_PROVEEDOR_USBSTOCK.md y PREPARACION_IONOS_USBSTOCK.md. La importación de marzo como inventario actual ya no es el procedimiento propuesto. La primera importación requiere una entrega nueva revisada, respaldo restaurable, hash y permiso independiente. Las alertas frente a marzo requieren revisión aunque el catálogo de desarrollo esté vacío.

No hubo conexiones al proveedor, importación real, Telegram, Scheduler, despliegue ni DNS. El catálogo de desarrollo sigue vacío y con revisión 0. Las diferencias de users/sessions respecto al snapshot de la fase previa no se revierten ni se atribuyen a la importación; esta fase no generó corridas reales.

## Historial — política CSV implementada

La política versión 2 consolida duplicados idénticos normalizados y conserva todas sus referencias y el CSV original. Conflictos rechazan el archivo completo. Texto vacío retiene texto válido previo con procedencia o deja contenido pendiente; stock cero sigue válido. No se modificaron umbrales económicos, bloqueo, revisión, caducidad, idempotencia, personalizaciones ni snapshots de cotización.

summary JSON almacena warnings separados de errors, recibidos/únicos, duplicados, procedencia por SKU/modelo y pendientes de contenido. No se requirió migración adicional. Publicar exige descripción comercial válida y autorización products.manage; todos los modelos nuevos quedan sin publicar. Catálogo, fichas y sitemap comprueban contenido efectivo. Se conserva la carga progresiva existente.

La copia real pasó SupplierSync::preview en SQLite :memory:: 819 recibidos, 818 SKU únicos, 362 modelos, un duplicado consolidado, 17 descripciones vacías, 85 variantes con stock cero, 18 advertencias y cero errores. TEC216–TEC220 quedarían pendientes de contenido. Los 819 registros ocupan 823 líneas físicas de datos (824 con cabecera), por cuatro descripciones multilínea; las referencias de staging son registros CSV. No se aplicaron datos reales ni se creó corrida en usbstock_dev. tests/Support/preview_supplier_isolated.php reproduce el ensayo desde un archivo privado, con guardas y sin opción apply.

La administración distingue advertencias de rechazo y aprobación por anomalías; Telegram incorpora los nuevos conteos, probado mediante dobles. FTP/Scheduler/aplicación automática/Telegram siguen desactivados. El contrato FTP:21 /FTP/Existencias-ftp-precio.csv se verificó con doble de cURL sin conexiones externas. Preparación de la primera aplicación autorizada, administrador ID 1 revisado, hash y respaldo propuesto en ../xtras/docs/ENSAYO_REAL_PROVEEDOR_USBSTOCK.md.

Validación final: **266 pruebas generales / 2,488 aserciones**, más **9 MariaDB / 864 aserciones**: **275 aprobadas / 3,352 aserciones**, cero fallos. Las nueve de MariaDB se omiten deliberadamente en la suite general y pasan en su runner dedicado. Composer validate --strict, auditoría PHP (cero advisories y paquetes abandonados), Pint, build Vite y git diff --check aprobados. La auditoría Composer pudo completarse sin escribir su caché de usuario.

Revisión visual en Edge, escritorio 1440 y móvil 390: cuatro vistas (válida con advertencias, inválida, anomalías, producto pendiente), ocho combinaciones, sin errores JavaScript ni desbordamiento de página. Confirmación modal abre/cierra; archivos inválidos no ofrecen aplicar. HTML de Laravel sobre fixtures aisladas, con recursos locales; no prueba una conexión real al proveedor. Se ajustó la presentación de advertencias para lectura móvil.

Esquema dev/test: 364 columnas, 170 entradas de índices y 26 relaciones foráneas, cero diferencias. Comparación de hashes de contenido de las **38 tablas de usbstock_dev: ninguna cambió**; siguen cero productos, variantes, cotizaciones y corridas, revisión 0. No se ejecutaron migraciones ni seeders en desarrollo. El catálogo continuo conservó 209 bloques sin duplicados para 5,000 modelos/20,000 variantes, cuatro SQL máximos por bloque y p95 HTTP interno 37.74 ms; el primer escenario frío de 100 modelos alcanzó 1,175.46 ms. Son mediciones locales, no latencia del hosting.

## Historial: catálogo continuo

/catalogo entrega 24 modelos en servidor y continúa con IntersectionObserver mediante GET /catalogo/bloques (120 peticiones/minuto). Cursor autenticado, ID descendente, sin OFFSET ni COUNT global. Filtros cancelan e invalidan peticiones anteriores; URL e historial sincronizados, enlace de continuación accesible sin JavaScript y restauración limitada al regresar, previa comprobación de revisión. Un cambio de inventario/publicación invalida el cursor y pide actualizar. No se devuelven costos ni variantes completas.

Se agregaron URLs /catalogo/categoria/SLUG, categorías públicas al sitemap y miniaturas WebP privadas de hasta 480 px, con carga lazy, proporción reservada y comprobación de publicación. La administración mantiene paginación convencional; incluye filtro de fotografía y búsqueda por SKU. La migración 000005 solo añade índices de visibilidad/capacidad/stock; revisar --pretend y destino antes de aplicarla.

Los datos de rendimiento se generan exclusivamente en pruebas aisladas: 100/1,000/5,000 modelos, hasta 20,000 variantes. Informes privados en storage/app/private/qa/catalog. Las fixtures de navegador dicen expresamente que son sintéticas y no se siembran en desarrollo.

La copia CSV conserva un duplicado idéntico y 17 descripciones vacías de cinco modelos; quince de esas filas tienen stock. Se propuso consolidar duplicados absolutamente idénticos con advertencia/evidencia y revisar por separado el texto vacío. La política sigue pendiente de aprobación: SupplierCsv no cambió. FTP, Scheduler y Telegram permanecen desactivados. El concepto PDF B es una recomendación provisional, sin activación ni cambios monetarios/históricos.

Guías canónicas adicionales fuera de Git: CATALOGO_CONTINUO_USBSTOCK.md y POLITICA_PROPUESTA_CSV_USBSTOCK.md, en ../xtras/docs. El runner MariaDB ahora verifica ocho pruebas, incluida la medición de rendimiento.

Validación final del catálogo: 238 pruebas generales/2,291 aserciones más ocho MariaDB/849: total 246 aprobadas/3,140. Cinco escenarios Edge sin errores JavaScript, Composer/Pint/build aprobados. 25 migraciones aplicadas, 143 rutas y esquema dev/test sin diferencias (364 columnas, 170 entradas de índices, 26 relaciones). Con 5,000 modelos/20,000 variantes: 209 bloques, cuatro SQL por bloque, JSON máximo 23,937 bytes y p95 HTTP interno 34.76 ms; no es latencia de red del hosting.

## Historial: consolidación del 17/11/2026

Scheduler del proveedor: 00:00 America/Mexico_City, condicionado a habilitación explícita. En UTC, schedule:list muestra 06:00. Corrida persistida antes de descargar, bloqueo con vencimiento, clave diaria única y reintentos limitados. Los fallos aparecen en /admin/supplier-sync sin requerir archivo. Anomalías pendientes impiden aplicaciones automáticas posteriores; se aprueban o cierran con motivo y permiso. No se borran ausentes ni personalizaciones. Se conservan diferencias resumidas y CSV privado completo.

El cliente legado usa FTP convencional:21 y /FTP/Existencias-ftp-precio.csv. El nuevo descargador exige protocolo explícito ftp/ftps-explicit/ftps-implicit, sin degradación automática. FTPS disponible en el servidor: no verificado. El ensayo real preparado es `php artisan supplier:sync --fetch --dry-run --actor=ID_AUTORIZADO`, exclusivamente después de aprobación y configuración privada. No activa calendario, aplicación ni notificaciones.

La copia CSV local tiene 819 filas y estructura correcta, pero la validación completa detecta un duplicado idéntico y 17 descripciones vacías (801 filas válidas/18 errores). Se rechaza el archivo completo. Resolver con proveedor o mediante política aprobada antes de automatizar; no se cambiaron validaciones para ocultar estas anomalías.

Telegram reutiliza el cliente y Outbox existentes. supplier_sync_notifications conserva texto seguro y relación corrida/evento incluso antes de descargar; el resultado de entrega no cambia el resultado del catálogo. `supplier:notify UUID` reconcilia con Outbox solo después de habilitación aprobada; un worker activo puede enviar inmediatamente. Entrega incierta no se reintenta automáticamente. No hubo conexiones ni envíos reales.

Propuestas A corporativo, B catálogo y C minimalista en /admin/pdf-designs: solo local/testing y permisos quotes.view + commerce.configure. Parámetros items=1|3|10|35 y format=html|pdf. Preferencia de revisión auditada sin cambiar emisión. `php artisan quotes:design-samples` genera doce parejas HTML/PDF privadas sin sembrar datos. 63 páginas A4 renderizadas y revisadas, con fotos locales, tarjetas redondeadas, descuentos y condiciones extensas. La paginación de navegador difiere de mPDF; los PDF mPDF son la referencia de selección.

Los borradores admiten foto específica por variante y vista previa autorizada. Al emitir se congelan copias de las fotos y hashes por versión. La plantilla actual usbstock-1 todavía no las muestra y sigue intacta; no se regeneran versiones históricas. legacy_v1 no cambió. La cotización administrativa heredada captura precios manuales; las dos escalas divergentes quedan documentadas y probadas, sin selección automática.

Verificación: 230 pruebas generales/2,192 aserciones, más siete MariaDB/58; total 237 aprobadas/2,250. 24 migraciones aplicadas, 140 rutas y comparación dev/test sin diferencias en 364 columnas, 162 entradas de índices y 26 relaciones. Sin DEMO, cambios de credenciales, push ni despliegue. Nueva migración de trazabilidad conserva datos y bloquea rollback destructivo; cualquier reversión requiere revisión.

La documentación operativa canónica está en `../xtras/docs`: AVANCE_DESARROLLO_INTENSIVO, RECONOCIMIENTO_SISTEMAS_USBSTOCK, ARQUITECTURA_COMERCIAL_USBSTOCK, REGLAS_COMERCIALES_USBSTOCK, OPERACION_COMERCIAL_USBSTOCK y PREPARACION_IONOS_USBSTOCK. Consolidación: CONTRATO_FTP_SINCRONIZACION_USBSTOCK, TELEGRAM_SINCRONIZACION_USBSTOCK, PROPUESTAS_PDF_USBSTOCK, COMPATIBILIDAD_MPDF_USBSTOCK, REGLAS_COMERCIALES_PENDIENTES_USBSTOCK y ENSAYO_REAL_PROVEEDOR_USBSTOCK. Esa carpeta está fuera de este repositorio; conservarla en el respaldo del proyecto.

Pendientes de producción: reglas automáticas, datos comerciales reales, privacidad/retención, aceptación del PDF, integración FTP/SMTP autorizada, migración ensayada, licencias y despliegue. No hubo push ni despliegue durante la implementación.
