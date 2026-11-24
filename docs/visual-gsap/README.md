# Experimento público Lovable + GSAP

Experimento local completado en `E:/proyectos/usbstock/visual-gsap`, rama `experiment/visual-gsap`. Se verificó el worktree limpio y su origen exacto **16e92350b34db06d352bb4a1cacc3999b63bbc4d** antes de implementar. `site/main` conserva ese commit. Referencia: `../showcase`, commit **b5b9631**, repositorio `ingefrain/usb-stock-showcase`, limpio al cerrar la auditoría.

## Resultado y decisión

Inicio y catálogo adoptan la propuesta visual de Lovable en Laravel 12/Blade: Manrope, hero fotográfico, tipografía de gran escala, categorías en mosaico, tarjetas redondeadas, secciones editoriales, proceso oscuro y cierre rojo. GSAP y ScrollTrigger controlan entradas y revelados discretos. El catálogo y la selección siguen utilizando los servicios comerciales existentes.

**Recomendación: candidato para integrar después de revisar la galería y decidirlo por separado.** La composición mantiene la dirección de Lovable y corrige defectos de contraste y recorte del titular. Las diferencias de fotografías, familias y tarjetas son deliberadas: proceden del catálogo real. La calidad del material histórico condiciona el resultado de esas zonas. No se realizó merge, push ni despliegue. Barba.js permanece fuera del experimento.

## Entregables

| Entregable | Ubicación |
| --- | --- |
| Auditoría, tokens, componentes y matriz de traslado | [LOVABLE-AUDIT.md](LOVABLE-AUDIT.md) |
| Comparación y diferencias justificadas | [VISUAL-REVIEW.md](VISUAL-REVIEW.md) |
| Regresión completa y reproducción | [REGRESSION.md](REGRESSION.md) |
| Navegador, métricas, límites e integridad | [VALIDATION.md](VALIDATION.md) |
| Galería comparativa local | `storage/app/private/qa/visual-gsap/comparison.html` |
| Preview y herramientas aisladas | [tools/visual-gsap/README.md](../../tools/visual-gsap/README.md) |
| Capturas reales | `storage/app/private/qa/visual-gsap/reference/` y `browser/` |

La galería funciona como archivo local, sin servidor ni red. Contiene cuatro anchos —1440, 1039, 820 y 390 px— y pares de inicio, catálogo, tarjetas y navegación. También se conservaron capturas de ficha, solicitud, WhatsApp, reduced motion y JavaScript desactivado. Las imágenes no se retocaron.

## Implementación

- `layouts/shop.blade.php` utiliza las entradas Vite públicas `visual.css` y `visual.js`. El administrador conserva sus entradas, vistas, CSS y scripts.
- La hoja pública reutiliza estilos de formularios y del selector corporativo y añade las composiciones Lovable en `visual-public.css` y `visual-home.css`.
- Se reutilizan sin modificar `catalog.js`, `selection.js`, `commerce.js`, `storefront.js`, `navigation.js` y `corporate-contacts.js`.
- El árbol público de categorías se consulta una vez por petición y se comparte entre menú, filtros y footer. No se cambian las reglas de visibilidad ni la taxonomía.
- El acceso flotante a la cotización conserva sus contratos AJAX y pasa a una cápsula inferior en escritorio. El cambio elimina la franja lateral reservada de 58 px sin alterar el layout al añadir una partida.
- `visual-motion.js` registra únicamente los nuevos nodos del catálogo, respeta reduced motion y limpia estilos temporales antes del guardado para historial/BFCache. El HTML permanece visible sin JavaScript. No transforma contenedores sticky ni aplica zoom a fotos de producto.
- Las tres fotografías editoriales se conservaron con procedencia en `public/brand/showcase/README.md`. Las tarjetas y portadas de categorías siguen usando imágenes importadas y rutas Laravel autorizadas.

Se conservan mínimo de 50 por variante, bloques de 24, cursores, búsqueda, categorías, capacidades confirmadas, carrito AJAX, CSRF, solicitudes, vendedor asignado, selector corporativo y validaciones. No se modificaron inventario, precios, motor de cotizaciones, histórico, FTP, Scheduler ni Telegram. No se incorporó React.

## Uso local

Con dependencias instaladas y la copia aislada ya preparada en este equipo:

```powershell
Set-Location E:/proyectos/usbstock/visual-gsap
npm.cmd run build
node tools/visual-gsap/server.cjs
```

El preview se sirve en `http://127.0.0.1:4392`. Usa SQLite, sesiones y archivos privados propios; no necesita `.env` ni utiliza la base de desarrollo durante la navegación. Cerrar con Ctrl+C. Los servidores de revisión se detuvieron al finalizar la validación.

```powershell
# Recorrido completo con Edge y generación de evidencias.
node tools/visual-gsap/browser.cjs
node tools/visual-gsap/gallery.cjs
```

`--resume` reanuda grupos ya comprobados únicamente cuando la implementación no cambió. `--resume --recapture` vuelve a tomar las cuatro vistas responsive y sus métricas, conservando los recorridos funcionales. Las capturas esperan fotografías visibles y el final de la entrada del hero; las páginas completas recorren el contenido para activar la carga diferida.

Para repetir la referencia, ejecutar su Vite en 4178 desde `../showcase` y usar `node tools/visual-gsap/reference.cjs`. El runner resuelve el logo virtual con el logo real y utiliza Manrope local; conserva la implementación y las fotografías de referencia. La instalación/ejecución de TanStack puede regenerar su árbol de rutas: revisar el diff y conservar el checkout de referencia limpio.

## Límites de la decisión

El selector WhatsApp se comprobó con indisponibilidad real del servicio apagado y respuestas de QA, sin contactar asesores ni enviar métricas externas. Los filtros de capacidad conservan soporte y pruebas; el catálogo copiado no contiene capacidades estructuradas confirmadas, por lo que ese selector no se inventa. Las cifras de rendimiento son locales y no equivalen a mediciones en el hosting. La calidad editorial de fotos antiguas puede mejorarse en una tarea separada.

Barba.js no está instalado ni implementado. Su eventual evaluación, el merge a `main` y cualquier publicación requieren decisiones posteriores; este experimento no los ejecuta.
