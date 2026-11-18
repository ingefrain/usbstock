# USB Stock — prototipos UX/UI para revisión

Tres propuestas navegables, aisladas de Laravel y de las bases de datos. No sustituyen la interfaz pública ni el administrador actuales. Todos los modelos, existencias, variantes, solicitudes e indicadores son ficticios y están identificados como demostración.

## Abrir las propuestas

En PowerShell, desde cualquier directorio:

```powershell
& 'E:\software\node\node.exe' 'E:\proyectos\usbstock\site\prototypes\ux-ui\serve.mjs'
```

Mantener esa terminal abierta durante la revisión. Detener con Ctrl+C. No requiere PHP, MariaDB, Vite, instalación de paquetes ni acceso a Internet. El servidor escucha exclusivamente en `127.0.0.1:4387`; utilizar esa dirección, sin sustituirla por otro hostname. Si el puerto está ocupado, detener la instancia propia anterior o elegir otro con `USBSTOCK_PROTOTYPE_PORT`; los scripts de QA usan el puerto predeterminado.

| Vista | A · Tech Premium | B · Comercial dinámico | C · B2B moderno |
|---|---|---|---|
| Inicio | [A](http://127.0.0.1:4387/a/) | [B](http://127.0.0.1:4387/b/) | [C](http://127.0.0.1:4387/c/) |
| Catálogo | [A](http://127.0.0.1:4387/a/catalogo) | [B](http://127.0.0.1:4387/b/catalogo) | [C](http://127.0.0.1:4387/c/catalogo) |
| Ficha | [A](http://127.0.0.1:4387/a/producto/72) | [B](http://127.0.0.1:4387/b/producto/72) | [C](http://127.0.0.1:4387/c/producto/72) |
| Selección / solicitud | [A](http://127.0.0.1:4387/a/cotizar) | [B](http://127.0.0.1:4387/b/cotizar) | [C](http://127.0.0.1:4387/c/cotizar) |
| Administración | [A](http://127.0.0.1:4387/a/admin) | [B](http://127.0.0.1:4387/b/admin) | [C](http://127.0.0.1:4387/c/admin) |

[Comparador inicial](http://127.0.0.1:4387/). La barra superior permite cambiar de concepto. Cada selección demo se conserva en `sessionStorage`, separada por concepto. No se guardan datos de contacto, ni se transmiten formularios. Las modificaciones del administrador viven en la vista actual y desaparecen al recargar.

## Recorrido sugerido

1. Comparar inicio, categorías y fotografía.
2. Filtrar por nombre, estilo, capacidad y disponibilidad. Continuar hasta los 72 modelos. Probar búsqueda vacía, regreso desde una ficha y el enlace al pie.
3. Elegir capacidad, color y cantidad en una ficha. El mínimo ilustrativo es 50 y no se permite superar las existencias demo. El modelo `/producto/60` muestra disponibilidad insuficiente.
4. Agregar una variante, revisar la selección, modificar cantidades y simular una solicitud con `alex@example.test`. No se genera PDF ni correo.
5. Explorar el administrador: búsqueda, estados, edición local, diálogos, avisos y preferencias. Cotizaciones y solicitudes son muestras de presentación, no operaciones reales.
6. Repetir a 1440, 820 y 390 px. En móvil los filtros están plegados; sin JavaScript permanecen abiertos y la paginación funciona mediante enlaces GET.

## Aislamiento y archivos

- `serve.mjs`: servidor HTTP local, GET/HEAD, rutas y assets permitidos explícitamente; sin bootstrap de Laravel, `.env`, conexiones de datos o servicios externos. POST devuelve 405.
- `data.mjs`: 72 modelos sintéticos; no contiene datos extraídos del proveedor ni precios.
- `views.mjs`: HTML de las cinco vistas principales, comparador y secciones administrativas.
- `assets/styles.css`: estilos y tokens propios del prototipo.
- `assets/interactions.js`: selección, variantes, diálogo, edición y formulario exclusivamente locales.
- `ASSETS.json`: procedencia y SHA-256 del logo y diez fotos. Copias verificadas byte por byte, sin modificar los originales.
- `DESIGN.md`: comparación, recomendación, sistema de diseño e integración futura.
- `QA.md`, `qa.cjs`, `qa-details.cjs`: evidencia y verificación reproducible.

El servidor publica una ruta fija de solo lectura a `resources/js/catalog.js`, sin copiarlo ni modificarlo. Conserva los hooks DOM, `IntersectionObserver`, bloques de 24, historial, restauración, manejo de errores y alternativa sin JavaScript. Los cursores y la revisión son sintéticos: firma efímera propia del prototipo, nunca `APP_KEY`; reiniciar el servidor invalida los cursores anteriores.

Los prototipos están fuera de `public` y no tienen rutas Laravel. No desplegar este servidor, sus fixtures ni las páginas administrativas demo. No representan la autenticación ni la autorización del sistema real.

La documentación canónica está en `E:\proyectos\usbstock\xtras\docs\PROPUESTAS_UX_UI_USBSTOCK.md`, fuera del repositorio `site`. Los PNG y reportes privados se conservan en `site/storage/app/private/qa/ux-ui`, excluido de Git.
