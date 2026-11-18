# Revisión comercial aislada de Laravel

Esta herramienta levanta la aplicación real con SQLite, almacenamiento, clave y sesión propios, sin leer el `.env` principal. Nunca apunta a MariaDB ni importa en `usbstock_dev`. No es un seeder global. No desplegar `tools/` ni usar este catálogo como inventario comercial.

## Preparación

Se requieren PHP 8.2 con GD/WebP y SQLite, dependencias del proyecto y frontend compilado (`npm run build`).

El archivo histórico privado debe estar en `storage/app/private/qa/csv-policy/provider-original.csv`. El mapeo privado `storage/app/private/qa/commercial/legacy-photo-map.jsonl` se exporta de `usbstock2` con consultas READ ONLY, campos id/model_id/model/sku/image/thumbnail y base explícita. No contiene clientes ni precios. El script nunca abre conexiones a esa base ni descarga las URLs de imagen.

`export-photo-map.ps1` reproduce exclusivamente esa exportación de referencias mediante el cliente MariaDB y el archivo de configuración local autorizados. No ejecuta código heredado. `prepare.php` consume el manifiesto ya exportado; no conecta a MariaDB.

Ejecutar desde `E:\proyectos\usbstock\site`:

```powershell
& 'C:\xampp\php\php.exe' tools/commercial-review/prepare.php
```

Revisa migraciones con `--pretend` y migra únicamente su archivo `storage/app/private/qa/commercial/review.sqlite`. Crea un usuario de QA y vendedores ficticios dentro de esa instancia; no cambia credenciales existentes. Conserva la contraseña nueva exclusivamente en `storage/app/private/qa/commercial/access.json`, excluido de Git. Si existe `summary.json`, conserva datos ya preparados. Si se interrumpe durante preparación, puede retomarse: los registros se buscan por claves exactas y las imágenes son idempotentes.

## Abrir la revisión

Usar rutas absolutas con el servidor PHP en Windows:

```powershell
& 'C:\xampp\php\php.exe' -S 127.0.0.1:4388 -t E:\proyectos\usbstock\site\public E:\proyectos\usbstock\site\tools\commercial-review\router.php
```

- Inicio: http://127.0.0.1:4388/
- Catálogo: http://127.0.0.1:4388/catalogo
- Solicitud: http://127.0.0.1:4388/solicitar-cotizacion (consultar rutas en `routes/commerce.php` si cambian).
- Acceso administrativo: http://127.0.0.1:4388/admin/login; utilizar el archivo privado `access.json` sin copiarlo a documentación o Git.

Cerrar con Ctrl+C al terminar. El router rechaza clientes no loopback. Los scripts de QA abren y cierran su propio servidor. No usar esta configuración para atender clientes ni para publicar el sitio.

La banda superior identifica el catálogo como revisión: nombres/fotos de referencia histórica, stock y costos sintéticos. Las categorías con sufijo QA solo organizan la revisión. Los formularios persisten en `review.sqlite` y los PDF se guardan en el almacenamiento privado de la instancia.

## Protección y verificación

`bootstrap.php` fija SQLite al archivo exacto, bloquea otros drivers/URLs, separa sesiones/archivos, desactiva FTP/Scheduler/aplicación automática/Telegram/correo comercial y bloquea HTTP externo. No modifica la guardia estricta de tests general. Rechaza configuración Laravel cacheada para no heredar desarrollo.

`LegacyProductPhoto` requiere fuente codexv0, modelo y SKU existentes exactos, ID entero, JPEG local bajo la raíz y SHA-256 coincidente. Conserva original privado antes de usar DocumentService, sin sustituir fotografías existentes. Las portadas de QA se eligen por el primer SKU histórico exacto en orden léxico que tiene foto; la selección queda en `covers.json` y requiere revisión antes de una importación real.

Manifiestos/capturas/reportes permanecen privados y excluidos de Git. La documentación canónica está en `E:\proyectos\usbstock\xtras\docs\INTEGRACION_COMERCIAL_DISENO_B_USBSTOCK.md` (fuera de este repositorio).

## Repetir la comprobación

`qa.cjs` utiliza Playwright y Edge instalados en la máquina; `PLAYWRIGHT_MODULE` y `EDGE_EXECUTABLE` permiten indicar sus rutas. Ejecutar desde `site` con el puerto 4388 libre. Abre y cierra su servidor, bloquea peticiones externas y crea solicitudes/cotizaciones solamente en QA. `QA_WIDTHS=1440,820,390` selecciona anchos; `QA_REPORT` permite conservar reportes de repeticiones. No ejecutar varias instancias a la vez ni exceder deliberadamente el límite de solicitudes públicas.

Después, `php tools/commercial-review/verify-persistence.php` consulta la SQLite aislada con `PRAGMA query_only=ON` y comprueba contactos, cantidades, impresión, asociación auditada, revisión, snapshots, hashes PDF/fotografías, stock y ausencia de outbox. Los resultados se guardan en `persistence-report.json` sin contraseñas. `check-local.php` comprueba únicamente destino y banderas de la configuración normal, sin mostrar secretos ni realizar conexiones.
