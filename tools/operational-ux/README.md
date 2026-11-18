# Revisión operativa del carrito y la paginación

Esta herramienta verifica la aplicación local poblada. No es un runner de PHPUnit, no importa inventario y **crea dos solicitudes ficticias** identificadas como `PRUEBA LOCAL UX`. Ejecutarla requiere autorización para esa revisión. No volver a iniciar una ejecución nueva sobre evidencias existentes: `--resume` omite los grupos completos y bloquea una repetición parcial de solicitudes ya enviadas.

Informe de implementación: `../../../xtras/docs/MODERNIZACION_UX_OPERATIVA_USBSTOCK.md`. Las evidencias permanecen en `storage/app/private/qa/operational-ux`, fuera de Git y de HTTP. Los permisos NTFS de la carpeta privada tienen el pendiente documentado en `ESTADO_LOCAL_USBSTOCK.md`.

## Preparación y ejecución local

Requiere Laravel en `127.0.0.1:8000`, recursos compilados, Edge y Playwright del runtime Codex. El proxy propio en `127.0.0.1:4390` sirve los recursos estáticos y envía las rutas de aplicación a Laravel; evita los resets intermitentes de recursos de `php -S` en Windows. Se cierra al terminar y no detiene el servidor Laravel existente. El navegador bloquea peticiones a otros orígenes.

Desde `site`, con todas las integraciones desactivadas:

```powershell
& C:\xampp\php\php.exe tools/operational-ux/local-review.php before
& C:\xampp\php\php.exe tools/operational-ux/local-review.php session
& E:\software\node\node.exe tools/operational-ux/review.cjs
& C:\xampp\php\php.exe tools/operational-ux/local-review.php after
```

`before` rechaza sobrescribir la línea base. `session` comprueba el respaldo existente y prepara acceso temporal para el administrador local autorizado, sin leer ni modificar su contraseña. El guion cierra esa sesión y elimina el archivo de acceso al finalizar el grupo administrativo. Si se interrumpe, conservar la evidencia privada y usar `--resume`; no divulgar ni incorporar el archivo de acceso. `after` compara tablas completas y hashes de PDF; permite solamente las dos solicitudes previstas, sesiones y auditoría/actividad de revisión. La cuenta dedicada `usbstock_test_runner` se utiliza exclusivamente por el runner aislado de MariaDB.

## Convenciones de implementación

- Formularios públicos: `data-cart-action="add|update|remove"`; POST convencional, CSRF y sesión. `resources/js/selection.js` añade fetch; la lógica vive en `App\Commerce\PublicCart`.
- JSON: `success`, `message`, `selection { revision, line_count, piece_count, request_key }`, `item { variant, quantity, printing? }`; fragmento Blade escapado opcional con `X-Selection-View: review`. `variant` es UUID; no se devuelven costos.
- Cada cambio efectivo incrementa la revisión y renueva la clave de solicitud. Repetir la misma cantidad absoluta es idempotente. Un cambio diferente con revisión antigua produce 409. La sesión se bloquea durante lectura/escritura para impedir que otra petición la sobrescriba. Mantener un almacén de caché compatible con locks atómicos y un driver de sesión del servidor; no usar sesiones `cookie` para este contrato.
- Listados administrativos: `->adminPaginate(50|100|200)` sobre la consulta ya autorizada y filtrada, y `{{ $items->links() }}` fuera de la condición de resultados. El argumento es el máximo permitido, **no** el tamaño inicial. Predeterminado 20, preferencia por ruta/sesión, CSS propio `assets/base-admin/pagination.css`. No utilizar el componente para sustituir el catálogo público continuo.

## Verificación aislada

```powershell
& C:\xampp\php\php.exe artisan test --compact
& C:\xampp\php\php.exe tests/Support/run_mariadb_tests.php
```

La primera suite fuerza SQLite `:memory:`; las diez pruebas MariaDB se omiten ahí y se ejecutan mediante el segundo comando, que comprueba destino y usuario dedicado antes de abrir PDO. Nunca retargetear PHPUnit a `usbstock_dev`.
