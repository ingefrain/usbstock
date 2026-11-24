# Revisión visual aislada con catálogo real

Herramienta privada del experimento `experiment/visual-gsap`. Sirve la aplicación Laravel real con una copia puntual del catálogo de desarrollo. No conecta la web de revisión a MariaDB y no modifica catálogo, clientes, solicitudes, cotizaciones, credenciales ni archivos del proyecto estable. No desplegar estas herramientas.

Desde `E:/proyectos/usbstock/visual-gsap`:

```powershell
./tools/visual-gsap/export-catalog.ps1
& 'C:/xampp/php/php.exe' tools/visual-gsap/prepare.php --review-migrations
# Revisar storage/app/private/qa/visual-gsap/migration-preview.txt.
& 'C:/xampp/php/php.exe' tools/visual-gsap/prepare.php
# Después de compilar el frontend con npm run build:
& 'E:/software/node/node.exe' tools/visual-gsap/server.cjs
```

Inicio: `http://127.0.0.1:4392/`; catálogo: `/catalogo`; selección y solicitud: `/solicitar-cotizacion`. Puerto PHP interno: 4393. Ambos servidores escuchan exclusivamente en loopback. Cerrar el proceso Node con Ctrl+C. El lanzador no detiene servidores ajenos y rechaza puertos ocupados. El proxy Node rechaza las rutas administrativas.

El exportador usa el cliente MariaDB autorizado, su defaults-file como primer argumento, base explícita `usbstock_dev` y una transacción de solo lectura consistente. Solo lee categorías, productos, variantes, asignaciones, documentos de producto y revisión del catálogo. No exporta usuarios, clientes, cotizaciones, solicitudes ni directorio corporativo. Los costos originales permanecen exclusivamente en el snapshot y SQLite privados; no se imprimen. La relación del usuario que subió fotos se elimina de la copia; la secuencia de cotizaciones comienza en cero. La fuente es una copia puntual, no una sincronización.

La preparación reutiliza `tools/commercial-review/bootstrap.php`: clave y cookie propias, SQLite exacta, runtime separado, sin lectura de `.env` y con integraciones desactivadas. Usa las migraciones del repositorio como fuente del esquema. Las fotografías gestionadas se copian desde el almacenamiento privado de `../site`, comprobando rutas, tamaño y SHA-256. No comparte almacenamiento ni modifica los originales; las miniaturas se generan solo en el runtime aislado.

El servidor utiliza el entrypoint estándar de Laravel (`vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php` y `public/index.php`), sin router PHP propio. Antes de iniciarlo, `cache-config.php` valida y serializa la configuración aislada a `web-config.php` **privado**. Solo contiene la conexión SQLite de esta instancia, su clave propia y los paths de runtime. `APP_CONFIG_CACHE` hace que Laravel omita dotenv y `LARAVEL_STORAGE_PATH` fija el storage independiente. Los valores de entorno de respaldo también seleccionan SQLite e integraciones apagadas. No modificar ni publicar el archivo cacheado: contiene la clave de sesión de QA. El bloqueo HTTP del bootstrap CLI no se serializa; el runner de navegador bloquea orígenes externos y todos los servicios comerciales permanecen desactivados.

Todos los datos, clave local, SQLite, sesiones, fotos, logs y manifiestos quedan bajo `storage/app/private/qa/visual-gsap`, excluido de Git y de HTTP. No copiar ese directorio al repositorio ni a `public`. `snapshot-manifest.json`, `prepared.json` y `verification.json` documentan recuentos y hashes sin imprimir datos privados. El snapshot inicial no se sobrescribe. Repetir `prepare.php` sobre una instancia completa verifica su integridad y conserva las operaciones de QA.

```powershell
& 'C:/xampp/php/php.exe' tools/visual-gsap/verify.php
```

La verificación fuerza consultas SQLite de solo lectura, comprueba recuentos, claves foráneas, fotografías y bloque público inicial. Las solicitudes enviadas desde esta web se guardan únicamente en la copia aislada. Para pruebas de WhatsApp, el runner de navegador debe simular el directorio y bloquear navegación externa; el directorio real permanece apagado. Los tests PHPUnit normales siguen usando SQLite en memoria y sus guardas existentes.

## Navegador y galería

```powershell
node tools/visual-gsap/browser.cjs
node tools/visual-gsap/gallery.cjs
```

`browser.cjs` inicia y cierra su servidor y Edge, verifica navegación y operaciones exclusivamente de QA y guarda PNG/informe en `browser/`. Se puede indicar `PLAYWRIGHT_MODULE` y `EDGE_BINARY` si las instalaciones locales difieren. `--resume` conserva grupos ya aprobados y requiere que la aplicación no haya cambiado; `--resume --recapture` repite los cuatro anchos y sus métricas para actualizar fotografías visibles sin repetir escrituras de QA.

La galería resultante es `storage/app/private/qa/visual-gsap/comparison.html`. Las capturas de referencia se generan con `reference.cjs`, con el Vite de `../showcase` previamente iniciado en 4178. El runner sustituye únicamente el logo virtual y la fuente remota por los recursos originales locales. No hace cambios en el código de referencia.

## Medición complementaria

```powershell
node tools/visual-gsap/performance.cjs
```

El runner inicia y cierra su servidor aislado y Edge, y mide tres navegaciones de portada por cada ancho (1440, 1039, 820 y 390 px). No ejecuta operaciones comerciales. Guarda las doce muestras, Navigation Timing, recursos, LCP, CLS, tareas largas y movimiento en `storage/app/private/qa/visual-gsap/performance.json`. Mantiene el recorrido visual anterior como evidencia independiente; las conclusiones y sus límites están en `docs/visual-gsap/VALIDATION.md`.
