# Verificación de los prototipos — 17/11/2026

Resultado final: **95 comprobaciones aprobadas**, 45 combinaciones de vista/ancho y **84 capturas reales de navegador**. Evidencia privada en `storage/app/private/qa/ux-ui`.

## Entorno y reproducción

Windows; Node 26.13.1; Microsoft Edge headless 157.0.4341.60 mediante Playwright ya instalado. El navegador integrado de la herramienta no respondió; las capturas y pruebas se ejecutaron efectivamente con Edge local. No se instaló un navegador ni un paquete nuevo.

Con el servidor del README iniciado en 4387, ejecutar desde `site`:

```powershell
& 'E:\software\node\node.exe' prototypes/ux-ui/qa.cjs
& 'E:\software\node\node.exe' prototypes/ux-ui/qa-details.cjs
```

Los scripts admiten `PLAYWRIGHT_MODULE` y `EDGE_EXECUTABLE` para adaptar rutas locales. Visualizar los prototipos no requiere Playwright; únicamente lo requieren estos scripts de QA.

## Cobertura ejecutada

| Área | Comprobación real |
|---|---|
| Responsive | A/B/C × inicio, catálogo, ficha, solicitud, administrador × 1440, 820 y 390 px. HTTP 200, un H1, IDs únicos, imágenes visibles cargadas, controles con nombre y ancho de documento sin desbordamiento. |
| Catálogo | 24 → 48 → 72, IDs únicos, fin de colección, filtros combinados, búsqueda vacía, historial y restauración de 48 tarjetas, foco y posición después de visitar una ficha. |
| Continuidad accesible | Sin JavaScript: dos bloques distintos de 24 y filtro GET. Sin IntersectionObserver: enlace manual y foco en el primer producto agregado. Sin sessionStorage: continuidad normal. |
| Estados | Carga visible, 503 sintético con reintento manual, 409 sintético con actualización de resultados. Errores introducidos únicamente mediante interceptación local de Playwright. |
| Ficha y selección | Cambio de capacidad y color por ratón y teclado, mínimo 50, máximo stock demo, producto no cotizable, agregado, edición de cantidad, retiro y simulación de solicitud. |
| Navegación | Enlaces del comparador, menú móvil, skip link, Escape, retorno de foco, Tab dentro del diálogo y apertura de filtros móviles. |
| Administración | Búsqueda, filtro de estado, vacío, edición local, descripción, estado, aviso, preferencias y muestras de cotizaciones/solicitudes. |
| Aislamiento | POST rechazado, `.env` y fuente de servidor inaccesibles por HTTP, JS de catálogo idéntico al archivo existente, cero solicitudes externas de las páginas y cero errores JS. Simular solicitud no produce ninguna petición de red. |
| Recursos | Logo y diez fotos: 11 coincidencias SHA-256 con los originales; 270,493 bytes en total. |

`report.json`: 72 comprobaciones, 45 estados y 83 capturas. `details.json`: 23 comprobaciones adicionales y una captura del comparador. Los reportes iniciales también se conservaron para reflejar las iteraciones; no se suman sus comprobaciones al total final.

## Accesibilidad, revisión visual y rendimiento

Se inspeccionaron las capturas reales de los tres conceptos, incluyendo portadas completas de escritorio/móvil, catálogos, fichas, solicitud, administrador y tableta. Se corrigieron filtros demasiado altos en móvil, ausencia de fotografías del hero C en tamaños menores, tipografía pequeña de tablas, desbordamiento producido por un encabezado accesible y recorrido de foco del diálogo.

La comprobación de contraste evaluó 1,666 nodos de texto renderizados en las 15 vistas principales: todos superan el umbral aplicable de 4.5:1 para texto normal o 3:1 para texto grande. El mínimo calculado es 4.42:1 y corresponde al titular grande rosa sobre rojo de B. La comprobación combina fondos sólidos heredados; no equivale a certificación WCAG completa y no mide todos los estados de hover, imágenes, transparencias o contenido añadido dinámicamente. Se verificaron además etiquetas, foco, teclado, regiones de estado y reducción de movimiento en CSS.

En las 45 navegaciones locales, `loadEventEnd` fue de 56 a 2,444 ms, mediana 78 ms. HTML y recursos registrados antes de recorrer la página: 106,895–373,327 bytes. Las cifras son una muestra de loopback con assets locales y carga diferida, sin limitación de CPU/red; no son Core Web Vitals de producción ni incluyen toda la colección descargada al desplazarse. No se concluye rendimiento comercial a partir de esta medición.

Pendiente antes de integrar: pruebas con clientes, lectores de pantalla, Firefox/Safari/WebKit, dispositivos físicos, zoom elevado, traducciones/nombres extremos y contenido real. Las validaciones de stock y selección aquí son sintéticas; la aplicación final conserva las comprobaciones del servidor. No se ejecutó PHPUnit en esta tarea porque no se modificó código de Laravel ni sus contratos de backend.

## Capturas

Nombres: `{a,b,c}-{home,catalog,product,quote,admin}-{1440,820,390}.png`. También existen `*-catalog-cards-*`, portadas y fichas completas `*-full`, diálogos, solicitud confirmada, vacío, carga, error y navegación sin JS. `comparison-hub-1440.png` muestra el comparador.

Los PNG y reportes quedan fuera de Git. El servidor utilizado en QA se detiene al finalizar la tarea; el comando del README lo vuelve a iniciar para la revisión del usuario.
