# Restauración del selector corporativo

La implementación pública está en `resources/views/components/shop/corporate-contacts.blade.php`, `resources/js/corporate-contacts.js` y `resources/css/commercial-navigation.css`. Sustituye el diálogo centrado por una región flotante no modal. No cambia `CorporateDirectory`, rutas, controlador, caché, tickets ni configuración privada.

## Referencia y recursos

Lectura estática de `C:/xampp/htdocs/usbstock/codexv0/templates/default/includes.php`, `style.php` y `js/main.js`, contrastada con `C:/xampp/htdocs/usbstock/codex`. No ejecutar estos proyectos. La referencia reproducible de QA es una reconstrucción estática del HTML/CSS, no una captura de producción ni el PHP heredado.

| Recurso local | Procedencia | SHA-256 |
| --- | --- | --- |
| `public/brand/logo.webp` (sin cambios) | `codexv0/img/design/logo.png.webp` | `192acf3ecdea2bfa81aba2641f3c0fa4dfe23765ac4e185d79a7be5ced36ffc6` |
| `public/brand/whatsapp-banner.webp` | `codexv0/img/contenido/whatspices.webp` | `461c0032a2079ab321f7eddc15f9799378ec141a0d32dc3d8d3c43d39d7b2a99` |
| `public/brand/whatsapp-icon.png` | `codexv0/img/design/icon-whatsapp.png` | `834d671f8607624d52dd461d9fb1bc5fff3471b6a92a410d222d19caa7de4beb` |

Montserrat era la familia declarada por el original. Se sirve localmente desde `public/brand/fonts/montserrat-variable.ttf`, obtenida del [repositorio oficial Google Fonts](https://github.com/google/fonts/tree/main/ofl/montserrat), con su licencia `OFL-Montserrat.txt`. Solo se aplica al panel; no hay CDN ni dependencia JavaScript nueva.

Se conservan ancho de 260 px, logo de 150 px, banner cuadrado, fotos circulares de 50 px, separación de 15 px, padding de 10 px, borde gris, radio de 5 px y sombra originales. Las filas online completas son enlaces y las offline no tienen enlace. Se mantienen `ventas`, nombre, iconos y Online/Offline. Los textos pequeños usan grises/verde más oscuros para contraste; los indicadores conservan #05A884/#AAA. La zona de cierre crece a 44 px sin agrandar su círculo visible de 36 px.

El desplazamiento respecto al borde derecho reserva la pestaña de cotización: 68 px en escritorio y 14 px en móvil; el panel móvil queda 80 px por encima del borde inferior, más safe area. En ventanas de menos de 560 px de alto, el banner se contiene completo en 110 px para conservar acceso a vendedores y cierre. No se recorta el recurso original.

## QA local

Ejecutar desde `site`, con Laravel local escuchando en 8000 y puerto 4390 libre. El proxy temporal solo escucha en loopback. Las rutas Laravel se ejecutan realmente; únicamente directorio, fotografías de prueba y métrica simulada se interceptan. El runner bloquea todo destino externo restante y previene la navegación de WhatsApp. Los nombres llevan QA; las fotografías de prueba reutilizan el banner y no representan al personal real. El carrito de prueba solo usa la sesión y nunca envía una solicitud comercial.

```powershell
C:/xampp/php/php.exe tools/corporate-selector/verify.php before
E:/software/node/node.exe tools/corporate-selector/browser.cjs
C:/xampp/php/php.exe tools/corporate-selector/verify.php after
```

`verify.php` exige `usbstock_dev` local en 3307 e integraciones apagadas, usa una transacción de lectura y guarda huellas de tablas, PDF y `.env`, nunca filas o valores privados. No sobrescribe la línea base. Para otra ejecución independiente, crear una carpeta de evidencia nueva con ACL privada y ajustar ambos runners; no borrar la evidencia histórica. Se excluyen únicamente sesiones y caché de la comparación.

Evidencia fuera de Git/HTTP: `storage/app/private/taxonomy-20261118/corporate-restoration/`. Abrir `gallery.html` para comparar referencia y Laravel; `browser-report.json` contiene resultados y geometría. Las rutas del runtime Playwright/Edge son locales a esta estación y pueden ajustarse en otra máquina.

El runner anterior de `tools/taxonomy/browser.cjs` conserva evidencia de la fase anterior; su comprobación del diálogo se reemplaza por estas comprobaciones no modales. Las pruebas Laravel de seguridad, catálogo, carrito, economía y cotizaciones se conservan sin cambios.

Validación del 18/11/2026: 306 pruebas generales (3,857 aserciones), 10 MariaDB aisladas (880 aserciones), 22 grupos de Edge y 32 capturas. Composer, Vite, sintaxis y Pint del alcance aprobados. Pint global conserva ocho archivos previos con incidencias de formato, detallados en la guía del workspace. Verificación final: 37 tablas protegidas, dos PDF y `.env` intactos. `--supplement` permite añadir comprobaciones visuales nuevas solo sobre un reporte completo ya aprobado; no sustituye una ejecución íntegra después de cambiar la aplicación.

## Integración real pendiente

Mantener `CORPORATE_CONTACTS_ENABLED=false` y `CORPORATE_CONTACTS_RECORD_CLICKS=false`. Activar requiere autorización separada, credencial privada válida y revisión del contrato real. No copiar claves heredadas al frontend. La vista ya consume el contrato seguro actual; no requiere otra estructura al autorizar el servicio.

Detalle del cierre y resultados: `../xtras/docs/RESTAURACION_SELECTOR_CORPORATIVO_USBSTOCK.md`, fuera del repositorio Git de `site`. Sin push ni despliegue.
