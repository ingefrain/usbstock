# Auditoría de la referencia Lovable

Referencia: https://github.com/ingefrain/usb-stock-showcase, commit `b5b9631` (`Add project README`). Checkout de lectura: `E:\proyectos\usbstock\showcase`. Auditoría realizada sobre los componentes y recursos reales, no solo sobre el README. El código de referencia utiliza React 19, TanStack Start/Router, Tailwind 4 y GSAP/ScrollTrigger 3.15.1. La adaptación conserva Laravel 12, Blade, Vite y los servicios existentes.

La base experimental es `16e92350b34db06d352bb4a1cacc3999b63bbc4d` del repositorio Laravel, en `experiment/visual-gsap`. No se incorporan React, TanStack, Barba ni los servicios simulados del prototipo.

## Fuentes estudiadas

- `src/routes/index.tsx`: Hero, Categories, Selection, Personalization, Process, Editorial, FinalCta.
- `src/routes/catalogo.tsx`: búsqueda, chips, cuadrícula, estados y ficha modal de demostración.
- `src/components/site/Header.tsx`, `Footer.tsx`, `Logo.tsx`, `ProductCard.tsx`, `QuoteDock.tsx`, `WhatsAppWidget.tsx`.
- `src/styles.css`: tokens, tipografía, gradientes, contenedor, sombras, reduced motion.
- `src/lib/motion.ts`: secuencias GSAP, triggers, stagger y parallax.
- `src/lib/catalog.ts`, `src/lib/quote.ts`: datos y funciones expresamente simulados.
- `src/routes/__root.tsx`: composición, fuente remota, metadatos y recursos globales.
- `src/assets/*`: fotografías editoriales/productos y descriptor de logotipo.

## Dirección visual comprobada en código

El inicio alterna una composición hero de gran tipografía y fotografía, un mosaico asimétrico de categorías, una franja gris de cuatro productos, personalización con imagen junto a una lista de beneficios, un proceso oscuro, fotografía editorial y una llamada roja final. No utiliza una cuadrícula uniforme para todas las secciones.

El encabezado fijo mide 64 px en móvil y 80 px desde 768 px. Se muestra transparente al inicio y adopta fondo blanco translúcido, blur y sombra después de 12 px de scroll. A partir de 1024 px tiene navegación horizontal y un megamenú de tres columnas; debajo utiliza panel móvil de pantalla completa con grupos `details`.

El hero usa un fondo gris radial y una retícula de 56 px. En escritorio distribuye texto/foto en `1.05fr 1fr`, con un titular `clamp(2.9rem,8vw,7rem)`, interlínea `0.92` y tracking `-0.045em`. La fotografía ocupa una proporción `4/3.4`, radio 40 px y encuadre a la derecha. Los botones tienen forma de píldora y 56 px de altura. Los chips superpuestos comunican personalización, estilo y mínimo de 50 piezas.

El catálogo usa un hero compacto con título `clamp(2.5rem,6vw,5rem)` y buscador de 64 px. Los chips de categoría se desplazan horizontalmente y permanecen bajo el encabezado. Las tarjetas tienen fondo blanco, radio 25.6 px, padding 8 px e imagen cuadrada con radio 19.2 px. Disponibilidad y capacidad se superponen sobre la foto; debajo aparecen modelo, título de 17 px y acción de cotización. En escritorio la acción secundaria aparece con hover/foco; en móvil es visible. No hay zoom de fotos de producto al pasar el cursor.

## Tokens y medidas

| Elemento | Valor real de referencia | Aplicación |
| --- | --- | --- |
| Fuente | Manrope, pesos 400/500/600/700/800 | Reutilizar Manrope local de Laravel; no añadir dependencia de Google Fonts |
| Fondo y tarjeta | `oklch(1 0 0)` | Blanco |
| Tinta | `oklch(0.225 0.014 230)` | Texto, botones secundarios, proceso y footer |
| Primario | `oklch(0.6 0.235 17)` | Rojo corporativo, acciones y acentos |
| Brillo primario | `oklch(0.7 0.2 22)` | Gradiente rojo |
| Gris de superficie | `oklch(0.97 0.002 197)` | Catálogo, secciones alternas y fotografía |
| Texto secundario | `oklch(0.5 0.012 230)` | Descripciones y metadatos |
| Borde | `oklch(0.92 0.004 220)` | Controles y divisores |
| Éxito | `oklch(0.62 0.14 155)` | Disponibilidad y WhatsApp |
| Contenedor | máximo 1360 px, padding 20 px / 32 px desde 768 | Ritmo horizontal |
| Secciones | padding vertical 96 px / 128 px desde 768 | Ritmo editorial |
| Radios grandes | 28, 32 y 40 px | Fotografías, bento, bloques comerciales |
| Grid catálogo | 1 columna; 2 desde 480; 3 desde 1024; 4 desde 1280 | 390 / 820 / 1039 / 1440 px |
| Grid categorías | filas 220 px móvil; 260 px desde 768 | Giratorias 2×2, madera/tarjetas 1×1, ejecutivas 2×1 |

El README usa equivalentes orientativos `#EF1239`, `#192126` y `#F5F6F6`; la implementación utiliza los valores OKLCH anteriores. Debe verificarse contraste del texto pequeño y de botones sobre los colores finales, sin asumir que las equivalencias son idénticas.

## Matriz de traslado

| Componente de Lovable | Traslado a Laravel | Fuente funcional / ajuste |
| --- | --- | --- |
| Logo | Conservar identidad original y proporción | Asset local Laravel; el prototipo solo contiene un descriptor de URL virtual |
| Header / navegación | Tipografía, altura, superficies, píldoras y refinamiento móvil | Taxonomía y navegación real; conservar teclado, Escape, foco y URLs |
| Hero | Composición, texto, fotografía editorial, CTA y chips | Enlaces reales a catálogo/solicitud; mínimo comercial existente |
| Categorías bento | Composición asimétrica y tratamientos fotográficos | Enlazar categorías reales por slug; no inventar categorías disponibles |
| Productos destacados | Cuatro tarjetas y franja de fondo | Consulta real Laravel, fotos importadas y disponibilidad real |
| ProductCard | Imagen cuadrada, metadatos, pills, CTA y hover sin zoom | `data-product-id`, `h3 a`, formularios AJAX y rutas reales |
| Catálogo | Hero/search, filtros compactos, cuadrícula y estados | `catalog.js`, bloques de 24, cursores, stock mínimo, deduplicación, historial y restauración |
| Personalización | Jerarquía, fotografía y lista numerada | Mensajes compatibles con los servicios existentes, sin garantías inventadas |
| Proceso de cotización | Bloque oscuro de cuatro pasos | Flujo real de selección, cantidades y solicitud |
| Editorial / CTA final | Fotografía protagonista, panel y cierre rojo | Enlaces reales; imagen editorial no se presenta como inventario |
| Footer | Columnas, fondo tinta, divisores y logo | Datos corporativos reales; excluir teléfono y correo ficticios |
| QuoteDock | Lenguaje visual y coexistencia con WhatsApp | Conservar selección AJAX/sesión y formulario Laravel, no `localStorage` demo |
| WhatsApp | Integración visual periférica | Conservar selector corporativo real y asesores configurados |
| Ficha producto modal | Solo referencia visual | Mantener ficha/ruta Laravel y validaciones; no añadir otra lógica de variantes |
| Motion | Hero, reveal, stagger, parallax editorial discreto | Entry público independiente; reduced motion, no JS y BFCache |

## Recursos gráficos

| Archivo local de referencia | Tamaño aproximado | Uso |
| --- | --- | --- |
| `hero-usb-clean.jpg` | 38 KB | USB metálica plateada con aro rojo sobre fondo gris; hero y cierre |
| `editorial-desk.jpg` | 161 KB | Escritorio con productos corporativos; fotografía editorial |
| `usb-swivel.jpg` | 72 KB | Categoría giratorias / chip hero |
| `usb-wood.jpg` | 67 KB | Categoría madera |
| `usb-card.jpg` | 43 KB | Categoría tarjetas |
| `usb-metal.jpg` | 87 KB | Categoría ejecutivas / personalización |
| `seller-1/2/3.jpg` | 10–12 KB cada una | Demostración: no trasladar al directorio real |
| `usbstock-logo.png.asset.json` | Descriptor | URL `/__l5e/assets-v1/...`; no contiene el binario del logo |

Las fotos editoriales pueden acompañar el discurso de marca. Las tarjetas y fichas comerciales deben seguir sirviendo las fotografías importadas de cada producto, incluidas sus miniaturas protegidas.

## Animaciones existentes y adaptación

| Secuencia original | Configuración original | Criterio experimental |
| --- | --- | --- |
| Titulares hero | `yPercent:110`, 0.9 s, stagger 0.08, `power3.out` | Reproducir secuencia sin CSS que oculte contenido |
| Subtítulo / CTA | y20 / y16, opacidad, 0.6 / 0.5 s | Mantener solapamiento temporal y rapidez |
| Foto hero | clip inset18%/30% →0, radio40, 1.1 s, `power4.inOut` | Reproducir reveal; reservar espacio con aspect ratio |
| Foto hero interna | scale1.08 →1, 1.3 s | Opcional; evitar zoom de producto en las tarjetas |
| Chips | y18, 0.5 s, stagger0.08 | Reproducir entrada |
| Float chip | y−10, yoyo3 s infinito | Excluir movimiento perpetuo innecesario |
| Secciones | y36, opacidad, 0.9 s, top88%, once | Iniciar al entrar, mantener visibles antes del trigger |
| Fotografías | clip12% →0, 1.2 s, top85%, once | Revelado ligero y contenido visible si falla JS |
| Cards | y40, 0.8 s, stagger0.07, top92%, once | Agrupar hasta 8, acotar stagger; registrar solo nuevos nodos AJAX |
| Parallax | 18/30 px, scrub | Solo fotografía editorial, pointer fine/tableta grande; nunca contenedores fijos/sticky |

`visual-motion.js` observa únicamente inserciones directas de `[data-catalog-grid]`. No observa cambios de estilos animados. No altera la carga, el cursor, la búsqueda ni la selección. Limpia sus estilos antes de que el catálogo guarde la instantánea de retorno, y omite entradas al restaurar BFCache/historial. El contenido SSR es visible sin JavaScript y la preferencia reduced motion se evalúa de forma dinámica.

## Demostraciones que se excluyen

1. `catalog.ts` genera 168 productos pseudoaleatorios y repite cinco fotografías. No contiene datos importados reales.
2. `fetchPage` agrega una espera artificial de 700 ms y fuerza un error una vez en offset 48. No trasladar esa simulación ni sustituir el endpoint Laravel.
3. La selección de `quote.ts` vive en `localStorage`, redondea cantidades y no valida contra servicios del servidor. No sustituye el carrito AJAX.
4. El botón de envío solo cambia `sent`, vacía selección y muestra «Demostración». No crea una solicitud real.
5. WhatsApp contiene tres nombres/roles/fotos ficticios y muestra un toast; no abre un chat real.
6. Footer incluye `(55) 0000 0000`, `ventas@usbstock.mx` y ubicación de ejemplo. Usar los contactos reales configurados.
7. Las categorías distintas de USB muestran «Próximamente» aunque no existe consulta de inventario. La adaptación debe obedecer categorías y productos reales.
8. Los modales React no proporcionan por sí mismos todas las garantías de foco, Escape o navegación del sistema actual. Conservar o mejorar los contratos existentes.

## Ejecución y evidencia

El checkout se clonó desde GitHub. La instalación usa `bun install --frozen-lockfile`, porque el repositorio contiene `bun.lock` y no `package-lock.json`. La caché de Bun se ubica dentro de `node_modules/.bun-cache` para respetar los permisos del entorno. Vite 8.1.7 sirvió la referencia en `http://127.0.0.1:4178/`. TanStack regeneró automáticamente su árbol de rutas; se conserva el original al finalizar, sin modificar la implementación visual.

Se ejecutó el navegador real Edge 158.0.4370.33 mediante Playwright instalado en el entorno portable. El primer arranque dentro del sandbox agotó su espera; el mismo runner funcionó con el permiso de proceso correspondiente. No fue necesario instalar un navegador. El runner de QA espera la hidratación React antes de interactuar o preparar imágenes; las capturas iniciales realizadas antes de esa espera no deben usarse para atribuir defectos de navegación a la referencia.

Evidencia privada del experimento: `storage/app/private/qa/visual-gsap/reference/`. Runner: `storage/app/private/qa/visual-gsap/reference-runner.cjs`. El informe final es `reference-report.json`; un informe de la iteración anterior se conserva identificado como `reference-report-before-hydration-fix.json`.

| Verificación real de referencia | Resultado |
| --- | --- |
| Inicio y catálogo en 1440, 1039, 820 y 390 px | 8 combinaciones sin desbordamiento horizontal |
| Fotografías visibles | Ninguna rota en las 8 combinaciones |
| Tarjetas | 4 en portada y 24 iniciales en catálogo |
| Capturas | 21 PNG: páginas, cards, menús, selección demo, WhatsApp demo y hero con movimiento |
| Errores JavaScript no capturados | 0 en el recorrido final |
| GSAP real | 32 muestras; titulares pasan de matrices con desplazamiento a y=0; imagen pasa de opacity=0/clip inset18% a opacity=1/inset0 |
| Drawer de cotización abierto | `aria-hidden=false` comprobado en el recorrido final |

El binario del logo no existe en el clon: el runner resuelve únicamente su URL virtual con el logotipo original de Laravel. También sirve Manrope desde el archivo local ya existente para evitar depender de una descarga de Google Fonts. Ambas sustituciones están registradas en el informe; no se sustituyeron la composición, el CSS ni las fotografías de Lovable.

### Defecto visual encontrado en la referencia

En Edge, la palabra «presente.» se representa como un rectángulo rojo sin letras visibles. La utilidad `.bg-red-gradient` declara la propiedad abreviada `background`, que anula el recorte de `.bg-clip-text` en la cascada compilada. El resto del titular permanece visible. La adaptación debe corregir este defecto usando `background-image` y `background-clip:text` explícitos, incluido `-webkit-background-clip:text`, conservando la intención visual del gradiente. La captura real conserva el defecto para que la comparación sea honesta.
