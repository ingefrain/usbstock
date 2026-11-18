# QA local del administrador y propuestas de precios

Revisión autorizada del 18/11/2026, sobre `12789c9`. No desplegar estas herramientas ni publicar su evidencia privada.

`local.php before|after` compara huellas de 36 tablas persistentes, PDF históricos, `.env` y automatizaciones. `before` nunca sobreescribe una referencia. `session` crea una sesión temporal del administrador existente sin cambiar credenciales; `close` la elimina. Todas las acciones exigen guardias de desarrollo local `usbstock_dev:3307` y bloquean HTTP externo. No contienen claves ni cambian precios de cotizaciones reales.

`before.cjs` documentó el fallo inicial a 1039 px. No volver a ejecutarlo sobre la corrección ni sobrescribir la evidencia original. `browser.cjs` revisa el borrador afectado, trece módulos y dos formularios extensos a diez anchos: 162 comprobaciones y 82 capturas. Bloquea toda escritura HTTP y acceso externo. Puede preparar precios en el formulario, pero recarga sin guardarlos.

`qa-bootstrap.php` define otra aplicación Laravel con SQLite privado, almacenamiento y sesión propios, sin leer `.env` principal. `qa-prepare.php` crea pequeñas fixtures identificadas como QA; se niega a duplicar una preparación completa. `qa-router.php` solo sirve loopback. `qa-browser.cjs` comprueba guardado conjunto, precios manuales, cambios de costo/cantidad, regla ausente, concurrencia, conversión y PDF/revisión. `qa-state.php` solo modifica/consulta esa instancia aislada. El modo `restart-unissued-fixture` permitió recuperar la prueba tras un corte de energía; rechaza usarlo si existen emisiones.

Los servidores de QA usan 4390/4391 y se cierran al terminar. Laravel del usuario en 8000 se conserva. Los scripts de navegador requieren Edge y el runtime Playwright local indicado en el código.

```powershell
php tools/admin-repair/local.php before
php tools/admin-repair/local.php session
node tools/admin-repair/browser.cjs
php tools/admin-repair/qa-prepare.php
node tools/admin-repair/qa-browser.cjs
php tools/admin-repair/local.php after
php tools/admin-repair/local.php close
```

No ejecutar de nuevo un escenario de escritura ya completado suponiendo que sus fixtures siguen intactas. Revisar su reporte y estado antes de continuar. La suite general y la MariaDB se ejecutan secuencialmente con sus guardias existentes, nunca sobre desarrollo. La suite dedicada exige `usbstock_test` y usuario `usbstock_test_runner`.

Resultado: 321 pruebas generales / 4,110 aserciones y 12 MariaDB / 896, todas aprobadas. Siete grupos de UI de escritura aislada; snapshots/PDF conservados. Vite, Composer validate y Pint de cambios aprobados; seis archivos previos fuera de alcance siguen fallando Pint global.

Informe operativo: `../xtras/docs/CORRECCION_ADMIN_RESPONSIVO_PRECIOS_USBSTOCK.md` (fuera de Git). La aplicación muestra propuestas al abrir borradores; la acción conjunta solo completa precios guardados y capturados en cero. Persistir exige «Guardar y calcular». No cambia fórmulas ni emisiones históricas.
