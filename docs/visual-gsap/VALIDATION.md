# Validación final

## Regresión y build

- Suite completa: **322 aprobadas, 12 integraciones MariaDB omitidas, 4.190 aserciones**, sin fallos ni errores. Véase [REGRESSION.md](REGRESSION.md).
- Después del ajuste final del encabezado de catálogo: **10 pruebas / 103 aserciones** aprobadas en `PublicCatalogTest|ExampleTest`.
- `npm run build`, Composer validate estricto, sintaxis JS/PHP del alcance, Pint y `git diff --check` aprobados.
- Build público: CSS 96,68 kB (20,74 kB gzip), entrada visual JS 119,50 kB (47,22 kB gzip) y módulo comercial compartido 83,56 kB (30,23 kB gzip). Los tamaños no incluyen fotografías ni fuentes. GSAP no se carga desde la entrada del administrador.

## Navegador real

Edge/Playwright contra Laravel real, con catálogo copiado a SQLite independiente. El navegador integrado no respondió; se utilizó el navegador instalado del entorno portable. No se simularon catálogo, carrito ni envío de solicitudes. Solo el directorio corporativo se interceptó para comprobar sus estados sin contactar al proveedor.

| Grupo | Resultado |
| --- | --- |
| 1440, 1039, 820 y 390 px | Inicio, catálogo, tarjetas, navegación y ficha sin overflow horizontal |
| Búsqueda y categorías | Respuesta AJAX, URL, regreso por historial y rutas de categorías correctos |
| Catálogo completo | **303 modelos únicos en 13 bloques: 12 × 24 + 15**, final alcanzado |
| Regreso desde ficha | Posición de salida 1821 px y restaurada 1821 px; foco conservado |
| Carrito y solicitud | Alta, cambio y retirada AJAX; rechazo nativo de 49; solicitud enviada exclusivamente en SQLite de QA |
| WhatsApp | Estado recuperable del servicio apagado, asesor QA online/offline, enlace seguro y Escape con devolución de foco |
| Reduced motion | Titular visible y sin transformaciones de entrada |
| Sin JavaScript | Menú accesible, HTML visible, siguiente bloque SSR sin duplicados y alta nativa en selección |
| Redimensionamiento | Foco trasladado al control visible al pasar 1039→1440→820 px; menús cerrados correctamente |

Informe: `storage/app/private/qa/visual-gsap/browser/report.json`, **9 grupos aprobados y cero errores JavaScript no capturados**. Contiene muestras de transformaciones, opacidad y clip del hero. Las 33 capturas del resultado y las 21 de referencia están disponibles localmente. El archivo `failure.png` y los logs de iteraciones anteriores se conservaron como diagnóstico y no forman parte de la galería final.

Las capturas se actualizaron tras esperar la descarga de imágenes visibles y recorrer las páginas completas. Esto evita confundir fotografías aún no solicitadas por lazy loading con errores de la aplicación. Las imágenes ocultas por diseño en móvil no se fuerzan a descargar.

## Integridad y aislamiento

Snapshot del catálogo de `usbstock_dev` obtenido mediante el cliente MariaDB autorizado, defaults-file como primer argumento, base explícita y transacción `READ ONLY`. La aplicación web de QA no se conecta a MariaDB.

| Verificación final | Resultado |
| --- | --- |
| Categorías / productos / variantes / relaciones | 31 / 361 / 872 / 363 |
| Filas de catálogo verificadas campo por campo | 1.982 |
| Fotografías con ruta y SHA-256 comprobados | 354 |
| Productos públicos / primer bloque | 303 / 24 |
| Usuarios, clientes, solicitudes o cotizaciones copiadas | Ninguno |
| Claves foráneas | Válidas |
| Escrituras comerciales en la fuente | Ninguna desde el experimento |

Se repitió `verify.php` después de los recorridos: catálogo, precios, existencias, relaciones y fotografías de la copia siguen coincidiendo con el snapshot. Las solicitudes creadas por QA permanecen únicamente en su SQLite privada. Los archivos de snapshot, configuración, clave, sesiones y fotos no se versionan ni se exponen por HTTP.

El preview usa el servidor estándar de Laravel con configuración cacheada privada, única conexión SQLite y `LARAVEL_STORAGE_PATH` propio. Un proceso previo verifica el destino exacto antes de arrancar. No se utiliza dotenv. El proxy bloquea administración y los servicios externos quedan desactivados. Un router auxiliar desapareció durante los primeros intentos; no se encontró evidencia concluyente de la causa en el código o los registros consultados. Se retiró esa dependencia usando el servidor estándar, sin desactivar protecciones ni agregar exclusiones.

## Rendimiento y estabilidad

Las métricas del recorrido visual se obtuvieron con PerformanceObserver, sin emulación de red o CPU móvil. Se conservan incluso las muestras lentas. El CLS máximo observado en la captura final fue **0,016711**. El LCP del catálogo estuvo entre **0,648 y 2,204 s**; la portada tuvo una muestra aislada de **6,860 s** en escritorio y entre **1,860 y 2,388 s** en los otros anchos. La muestra previa de escritorio había sido 2,400 s.

Se ejecutaron otras **12 navegaciones (tres por ancho), sin errores**, registrando Navigation Timing, recursos, candidatos LCP, tareas largas y movimiento en `storage/app/private/qa/visual-gsap/performance.json`:

| Ancho | LCP mediano | Rango de LCP |
| --- | --- | --- |
| 1440 px | 1,604 s | 1,424–1,788 s |
| 1039 px | 2,180 s | 1,876–2,308 s |
| 820 px | 1,880 s | 1,804–2,156 s |
| 390 px | 1,416 s | 0,824–1,476 s |

La muestra histórica de **6,860 s se conserva y no se reprodujo**. Su causa exacta es indeterminada porque ese recorrido no registró Navigation Timing. En las doce muestras complementarias, la mayor variación estuvo antes del primer byte: TTFB de **529–2.041 ms**, frente a unos **39–106 ms** para las transferencias del hero, JS y CSS. Esto apunta al backend o al entorno local, sin distinguir ejecución PHP, carga del equipo o E/S. GSAP también interviene en la aparición visual; el informe registra sus tiempos por separado. El hero servido mide 38.294 bytes.

El CLS máximo de la medición complementaria fue **0,02920**, superior al 0,016711 del recorrido visual anterior; ambos valores permanecen registrados. Estas mediciones locales no garantizan rendimiento productivo ni constituyen una comparación de rendimiento de campo con Lovable. Antes de integrar conviene repetir la medición en un entorno representativo del alojamiento.
