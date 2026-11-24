# Evaluación comparativa del experimento

El resultado reproduce la composición y el lenguaje visual de Lovable: Manrope, gran titular con fotografía protagonista, retícula técnica tenue, mosaico asimétrico, franja de productos, personalización editorial, proceso oscuro y cierre rojo. El catálogo conserva el encabezado «Memorias USB para tu marca.», buscador destacado, categorías en píldoras, fotografía cuadrada y tarjetas redondeadas. La implementación se mantiene en Blade, JavaScript, Tailwind y Vite, con GSAP/ScrollTrigger; no incorpora React ni Barba.

La galería privada `storage/app/private/qa/visual-gsap/comparison.html` permite comparar capturas reales a 1440, 1039, 820 y 390 px. Contiene pares de inicio completo, catálogo, tarjetas y navegación; ofrece vista ajustada, tamaño original y desplazamiento sincronizado opcional. Se regenera con `node tools/visual-gsap/gallery.cjs` después de actualizar los informes y PNG. No requiere servidor, paquetes adicionales ni conexión a Internet.

## Diferencias deliberadas

| Diferencia respecto a Lovable | Motivo |
| --- | --- |
| Categorías, fotos de portada asociadas y productos distintos | El prototipo usa nombres, inventario y fotografías repetidas de demostración. Laravel consulta las familias, modelos y fotos importadas existentes. |
| Fotos de producto contenidas y sin zoom | Preservar su proporción, mostrar el producto completo y evitar convertir imágenes editoriales en evidencia de inventario. |
| Descripción y cantidad de variantes en las tarjetas | Aportar información real para la selección comercial. La densidad es mayor que en las tarjetas ficticias. |
| Acción «Ver y cotizar» hacia la ficha | Elegir una variante válida antes de añadir al carrito. El botón de Lovable agrega automáticamente 50 piezas a un producto simulado. |
| Capacidad, contexto de categoría, modelos cargados y enlace al pie | Conservar filtros reales, carga por cursor de 24 productos y accesibilidad del catálogo continuo. No se inventa un total ni se reproduce el fallo artificial de la tercera página. |
| Solicitud, carrito y WhatsApp existentes | Mantener AJAX, sesión, CSRF, validaciones, disponibilidad y selector corporativo. Los paneles demostrativos no sustituyen servicios funcionales. |
| Header sticky y navegación móvil por debajo de 1100 px | La taxonomía real tiene más contenido. A 1039 px se usa el menú móvil; Lovable todavía muestra su megamenú de escritorio. |
| Rojo de acción `oklch(.58 .22 17)` | Variación cercana al color de referencia para mejorar el contraste de textos y controles. |
| Titular con «presente.» legible | Se conserva el gradiente previsto y se corrige el rectángulo rojo observado en el prototipo por el orden de sus utilidades CSS. |
| Textos de personalización y contacto | Describir el flujo confirmado sin prometer prestaciones o publicar teléfonos, correos y vendedores ficticios. |
| GSAP sin flotación infinita | Mantener entradas, revelado de fotografía y stagger, con movimiento editorial discreto. No se transforma la navegación ni se aplica zoom a las fotos de catálogo. |
| Banda de revisión local | Identifica la copia aislada usada en QA. No es una pieza del diseño comercial de producción. |

## Juicio visual

El hero y las secciones editoriales son equivalentes en estructura y jerarquía a la referencia, y el titular resulta más legible al corregir el defecto del gradiente. El catálogo adopta la misma dirección visual y reserva espacio para información real de variantes y descripciones. En móvil se mantienen una tarjeta por fila, controles táctiles, buscador amplio y categorías horizontales; la navegación utiliza grupos legibles con el contenido histórico real.

La diferencia más visible en categorías y selección es la calidad y variedad del material fotográfico histórico. Algunas miniaturas tienen menor resolución o fondos menos homogéneos que las fotografías editoriales del prototipo. Es una característica del material importado, no una equivalencia demostrada entre imágenes. Una curaduría comercial posterior de fotografías y productos destacados puede mejorar esa zona sin modificar precios, inventario ni reglas del catálogo.

## Evidencia y recomendación

El informe Laravel `browser/report.json` registra la validación responsive y los recorridos comerciales; `reference/reference-report.json` documenta la referencia. Las capturas se mantienen sin retoque. La galería muestra las métricas locales disponibles con su alcance explícito: no equivalen a datos de producción ni comparan rendimiento de campo entre frameworks.

La galería se comprobó en Edge a 1440 y 390 px: 32 imágenes enlazadas existentes, selector de ancho y modo de tamaño original funcionales, sin desbordamiento horizontal, errores JavaScript ni solicitudes externas. `gallery-report.json` conserva ese resultado; `gallery-desktop.png` y `gallery-mobile.png` muestran el visor.

Recomendación: conservar el experimento como candidato a integración y revisar la galería con el responsable comercial. La fidelidad de composición y las diferencias funcionales están documentadas para decidir sobre `main` por separado. Esta evaluación no autoriza merge, push, despliegue ni incorporación de Barba.
