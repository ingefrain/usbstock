# Revisión visual premium

Ejecutar desde `E:\proyectos\usbstock\site`. Reutiliza la instancia SQLite y el almacenamiento privado ya preparados por `tools/commercial-review`; no reinicializa datos ni lee `.env` para servir QA. Ver su README para acceso y aislamiento. Las capturas, PDFs e informes quedan excluidos de Git en `storage/app/private/qa`.

Requiere PHP/GD/SQLite, dependencias Composer, build Vite, Node y Edge/Playwright locales. No instala paquetes. Las rutas de Node y Playwright se pueden adaptar mediante `PLAYWRIGHT_MODULE` y `EDGE_EXECUTABLE`. Los scripts arrancan Laravel real y detienen sus servidores en `finally`. Puertos 4388 y 4389 libres; ejecutar **un solo script de navegador a la vez**.

```powershell
# Aplicación de revisión interactiva; cerrar con Ctrl+C.
& 'E:\software\node\node.exe' tools/commercial-review/server.cjs
```

Inicio `http://127.0.0.1:4388/`, catálogo `/catalogo`, acceso `/admin/login`, laboratorio `/admin/pdf-designs`. La cuenta está exclusivamente en el archivo privado `storage/app/private/qa/commercial/access.json`; no copiar credenciales a documentación ni consola. El proxy local solo sirve assets públicos permitidos y deriva las rutas a PHP; evita resets de archivos estáticos observados con `php -S` en Windows. No es un servidor de despliegue.

## Comprobaciones de navegador

```powershell
$env:VISUAL_PHASE = 'after'
& 'E:\software\node\node.exe' tools/visual-review/audit.cjs
& 'E:\software\node\node.exe' tools/visual-review/quality.cjs
& 'E:\software\node\node.exe' tools/visual-review/followup.cjs
& 'E:\software\node\node.exe' tools/visual-review/final.cjs
& 'E:\software\node\node.exe' tools/visual-review/gallery.cjs
& 'E:\software\node\node.exe' tools/visual-review/verify-galleries.cjs
```

`audit.cjs` revisa 46 combinaciones de vista/ancho. No repetir la fase `before`: las capturas anteriores son evidencia del estado inicial. `quality.cjs` observa movimiento real, hover, foco, modalidades, reduced-motion, contraste renderizado y Dropzone. La prueba CSV usa un archivo sintético en vista previa, sin aplicar inventario. `followup.cjs` crea dos solicitudes ficticias consecutivas únicamente en QA; no repetir deliberadamente dentro del límite de frecuencia. `FOLLOWUP_ONLY` filtra por nombre; `QUALITY_ONLY` permite repetir un grupo. `final.cjs` verifica CLS, bloques 24→48, alternativa sin JS y PDFs por rutas autorizadas. `reference.cjs` es una consulta externa de solo lectura a Xeions; no forma parte del runner local sin red.

Para el flujo comercial integral usar `tools/commercial-review/qa.cjs` (solicitud → costos → cotización → emisión → revisión histórica). `QA_WIDTHS=1440,820,390` controla anchos; `QA_REPORT` conserva repeticiones. Después ejecutar `php tools/commercial-review/verify-persistence.php`. No tocar las bases de desarrollo o heredadas para estas pruebas.

Pruebas PHP: `php artisan test --compact`; las integraciones MariaDB se omiten allí y se ejecutan con `php tests/Support/run_mariadb_tests.php`, que exige el destino y usuario dedicados ya configurados. Nunca sustituir las guardas ni usar una base de desarrollo para completar esas pruebas. Ver `tests/bootstrap.php` antes de adaptar la configuración.

## PDF y galerías privadas

```powershell
& 'C:\xampp\php\php.exe' artisan quotes:design-samples --lab
$env:PYTHONIOENCODING = 'utf-8'
& 'C:\Users\ing_e\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe' tools/visual-review/render-premium.py
& 'E:\software\node\node.exe' tools/visual-review/gallery.cjs
```

La generación solo permite local/testing; usa fixtures sin consultar productos/clientes y no emite cotizaciones. Requiere Poppler `pdftoppm` en PATH y Python con Pillow/pdfplumber. `render-premium.py` crea PNG de **cada página** a 110 dpi, hojas de contacto y verificación de Letter, partidas, totales, condiciones, pies y límites de texto. La extracción de captions B3 se realiza por columna para evitar intercalar la descripción vecina. Revisar también visualmente todas las hojas de contacto; las aserciones no sustituyen esa revisión.

- Comparación web: `storage/app/private/qa/visual/index.html`.
- Galería PDF: `storage/app/private/qa/designs/index.html`.
- Evidencia PDF: `premium-manifest.json`, `premium-verification.json`, `rendered/`, `contacts/`.
- Evidencia final web: `after-audit.json`, `quality.json`, `final-browser.json`, `followup-extra.json`, `consecutive-final.log`.

Las galerías se abren directamente como archivos locales; no requieren mantener servidores activos y no se publican bajo `/storage`. Los enlaces PDF corresponden al archivo real mPDF; HTML es únicamente una inspección de composición. B1/B2/B3 **no** modifican la plantilla oficial `usbstock-2-letter` ni el cálculo `legacy_v1`.

La fuente web Manrope se sirve localmente bajo OFL, incluida en `public/brand/fonts/OFL-Manrope.txt`. Sin librerías nuevas de animación. Informe canónico y decisiones de gerencia: `E:\proyectos\usbstock\xtras\docs\EVOLUCION_VISUAL_USBSTOCK.md` (fuera de Git).
