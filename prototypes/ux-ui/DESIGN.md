# USB Stock — evaluación de conceptos y sistema visual

Estado: propuestas para decisión del usuario, 17 de noviembre de 2026. No se autoriza ni implementa todavía su traslado a la aplicación.

## Identidad y dirección

Se estudió estáticamente la portada del sistema original, sus referencias de imágenes y la identidad local asociada a `http://localhost/usbstock/codex/`. No se ejecutó el legado ni se modificaron sus archivos; no se presenta como una revisión navegada de ese servidor. El logotipo original se conserva íntegro, con rojo, gris y blanco. Se reutiliza el mensaje «Tu marca, siempre presente» en B.

Las fotografías proceden del catálogo local original. Los fondos y calidades fotográficas son heterogéneos; se conservaron sus bytes. No se usaron logos de supuestos clientes, promociones, garantías, tiempos de entrega ni testimonios. Las capacidades, nombres y existencias del prototipo son ficticias. La tipografía es del sistema: Segoe UI Variable Text, Segoe UI y Arial; no se descarga ninguna fuente.

## Comparación

| Concepto | Ventajas | Costes o límites | Uso más adecuado |
|---|---|---|---|
| A · Tech Premium | Mucho espacio, jerarquía editorial, producto protagonista, blanco y acento rojo moderado. Lectura tranquila y clara. | La búsqueda ocupa una posición posterior al hero. Necesita fotografía uniforme para sostener la percepción premium. Menos énfasis comercial inmediato. | Presentación de marca y fichas de producto. |
| B · Comercial dinámico | Buscador antes del hero, marca reconocible, fotografía grande, categorías visuales y acciones de cotización evidentes. Tarjetas redondeadas y consistentes. | El rojo tiene mayor presencia. En móvil la portada es más larga; habría que priorizar categorías según el catálogo real. | Experiencia pública principal de USB Stock. |
| C · B2B moderno | Búsqueda y capacidad en primer plano, disponibilidad explícita, lectura ordenada por filas y administración con navegación contrastada. | Menor impacto emocional. Las filas ocupan más altura que la cuadrícula de A/B; la densidad debe decidirse con usuarios frecuentes. | Administración y compradores que ya conocen el modelo deseado. |

**Recomendación:** elegir B como dirección pública y tomar de C la claridad operativa del administrador. Mantener una sola familia de botones, campos, estados y tipografía. No mezclar los tres lenguajes de portada. La recomendación es cualitativa; no se ha hecho un estudio con clientes ni se afirma una mejora de conversión medida.

Las tres propuestas comparten la misma cobertura funcional para comparar presentación, no ventajas artificiales de funcionalidad. Las diferencias administrativas se concentran en superficies, acentos y navegación; no representan tres arquitecturas de operación distintas.

## Tokens reutilizables

| Grupo | Base / propuesta | Aplicación |
|---|---|---|
| Marca | `#ef1239` | Identidad del logo y acentos no textuales. No usar como texto pequeño sobre blanco: contraste aproximado 4.36:1. |
| Acción | A `#be1534`; B `#c51636`; C `#b71934` | Botones y enlaces; blanco sobre estos fondos: 6.28, 5.93 y 6.56:1. |
| Texto | `#192126`; C `#192d3c` | Títulos y contenido principal. |
| Secundario | `#626b70` | Ayudas y metadatos; 5.45:1 sobre blanco. |
| Superficies | `#ffffff`, `#f5f6f6`, `#edf1f2` | Página, agrupaciones y estados neutros. B usa apoyo rosa; C azul grisáceo. |
| Líneas | `#dbe0e2` | Separaciones discretas. El estado y el foco también tienen texto o contorno. |
| Éxito / aviso | `#176348` / `#80540a` | Disponibilidad y pendientes, siempre acompañados de etiqueta. |
| Foco | `#1468c0`, 3 px, separación 4 px | Enlaces, botones, campos, radios y controles de teclado. |
| Tipografía | Segoe UI Variable Text → Segoe UI → Arial | Sin descarga. Cuerpo 16 px / 1.55; intro 16–17; tablas 13; ayudas 11–13. |
| Titulares | H1 fluido 38–68 px; hero B hasta 72; H2 29–40 | En móvil se ajustan a 31–44 según vista y jerarquía. |
| Espacio | 4, 8, 12, 16, 24, 32, 48, 64 px | Escala común para separación y padding; secciones amplias de 72 px. |
| Radios | Base 18; B 22; C 12 px | A es más recta en fotos. Campos 9 y botones 10 px. Evitar radios arbitrarios al integrar. |
| Sombras | `0 12px 35px #19212609` | Elevación tenue en piezas seleccionadas; no en cada bloque. |
| Movimiento | 180 ms en acciones; desplazamiento de 1 px | Se anula con `prefers-reduced-motion`. Sin carruseles ni animaciones automáticas. |

Los valores están implementados en el CSS del prototipo; las variables transversales no constituyen todavía un paquete de tokens del sistema Laravel. Al aprobar el concepto se consolidará un único tema y se mapearán los valores a CSS/Tailwind existentes.

## Reglas de componentes

- **Navegación:** logo íntegro, catálogo, cómo cotizar, contacto y selección. Menú móvil con estado expandido, cierre por Escape y recuperación del foco.
- **Tarjetas:** fotografía con dimensión reservada, modelo y nombre, capacidad, disponibilidad textual y acción. A/B utilizan tres columnas en el catálogo de escritorio, dos en tableta y una en móvil. C utiliza filas con foto lateral. Carga diferida de fotos fuera de la primera vista.
- **Filtros:** controles nativos con label; búsqueda, estilo, capacidad y disponibilidad. En móvil un `details` plegable reduce la altura inicial. Sin JS se conserva formulario GET y enlace al siguiente bloque.
- **Botones:** primaria para avanzar, secundaria para continuar/cancelar, enlace para navegación contextual. Estados hover, foco, deshabilitado y carga distinguibles.
- **Formulario:** label, control, ayuda y error unidos. Radios accesibles para capacidad/color; número con límites ilustrativos. Sin mostrar costos ni inventar precio final.
- **Solicitud:** selección editable, totales de piezas, contacto y confirmación de simulación. La selección no reserva existencias.
- **Administración:** menú, encabezado, indicadores, filtros, tabla con scroll propio en móvil, estado textual, edición modal y aviso temporal. La tabla no provoca scroll horizontal en toda la página.
- **Diálogo:** nombre accesible, cierre visible y Escape, Tab/Shift+Tab dentro de la ventana y retorno al control de apertura. En integración debe reutilizarse el componente de Proyecto Base.
- **Estados:** vacío con salida clara; carga con `aria-busy` y región `aria-live`; error con reintento manual; cursor vencido con actualización explícita; sin existencias con acción deshabilitada.

## Inventario para la integración posterior

| Archivo / familia actual | Cambio visual previsto | Contrato que debe conservarse |
|---|---|---|
| `resources/views/layouts/shop.blade.php` | Cabecera, navegación, contenedor, footer y selección | Rutas, metadatos, seguridad y assets locales. |
| `resources/views/shop/home.blade.php` | Hero, buscador, categorías, destacados y proceso | Mostrar únicamente contenido real disponible; no inventar beneficios. |
| `resources/views/shop/catalog.blade.php`, `cards.blade.php`, `card.blade.php` | Filtros, tarjetas, continuidad y estados | Hooks `data-catalog-*`, identificadores, grupos de 24, enlace sin JS. |
| `resources/js/catalog.js` | Conservar inicialmente sin cambios | Observer, cursor, revisión, cancelación, historial, snapshot y foco. Ya ejercitado intacto por este prototipo. |
| `resources/views/shop/product.blade.php` | Galería, información, variantes y cantidad | Publicación, stock y validación server-side. El prototipo no reemplaza esas reglas. |
| `resources/views/shop/request.blade.php`, `info.blade.php` | Selección, contacto, errores y páginas informativas | Validación, protección de formularios y flujo autorizado de solicitud. |
| `resources/css/shop.css`, `resources/css/app.css` | Tokens aprobados, espaciado y responsive | Evitar fugas de estilos entre público, administrador y PDF. |
| `resources/views/layouts/admin.blade.php`, `admin/dashboard.blade.php` | Menú, encabezado, superficie e indicadores | Navegación existente, autorización y actor real durante impersonación. |
| `resources/views/admin/commerce/{products,product,categories,clients,requests,quotes,quote,quote-line,settings}.blade.php` | Tablas, filtros, campos y estados | Acciones, cálculos, permisos y transacciones actuales. No rediseñar el flujo económico. |
| `resources/views/components/admin/*` | Ajustes coherentes de presentación | Reutilizar Alert, Notify, Confirm Dialog, Toggle, Dropzone, Markdown, Field Error y Empty State; no duplicarlos con librerías nuevas. |
| Resto de vistas administrativas | Revisión progresiva de consistencia | Usuarios, roles, documentos, auditoría y notificaciones conservan sus permisos y comportamiento. |

Ninguno de esos archivos de aplicación se modificó en esta fase. Los iconos de los prototipos son SVG sencillos, no un rediseño del logotipo ni una nueva dependencia.

## Implementación por etapas, después de elegir concepto

1. **Decisión visual:** aprobar B, A o C y la dirección administrativa. Validar ejemplos reales de nombres largos, fotografías faltantes, productos no publicados, variantes y catálogo vacío. Resolver qué categorías/destacados se mostrarán al existir datos reales.
2. **Base visual:** centralizar tokens del tema elegido; integrar layout público y componentes de acción/campo. Mantener rutas, accesibilidad y estilos PDF separados. Revisión comparativa en los tres anchos.
3. **Catálogo:** portar HTML/CSS conservando hooks y JS actual. Verificar cursores reales, orden, filtros, revisión, datos vacíos, cambios de stock/publicación, regreso desde ficha y fallos de red. No introducir fixtures en desarrollo.
4. **Ficha y solicitud:** portar presentación al flujo actual. Verificar variantes válidas, mínimo, stock real, errores server-side, solicitudes y permisos. La selección local del prototipo no es un backend nuevo para reutilizar.
5. **Administrador:** renovar primero layout, tablas, filtros y formularios comerciales. Reutilizar los componentes de Proyecto Base. Revisar teclado, lectura de tablas y autorización; extender el mismo sistema al resto de módulos.
6. **Aceptación:** pruebas Laravel aisladas apropiadas, build Vite, pruebas de navegador con Firefox/WebKit además de Chromium, lector de pantalla y dispositivos físicos. Revisar rendimiento con catálogo real y red móvil. Hacer commits revisables por etapa; despliegue solo bajo autorización posterior.

No hay cambios en motor económico, PDF, históricos, importación, FTP, Scheduler, Telegram, credenciales o datos comerciales. Los futuros PDF están aprobados en **Carta vertical / Letter, 8.5 × 11 pulgadas**; esta fase no cambia la plantilla oficial ni genera documentos.
