# Revisión local de directorio y escalas

No desplegar estas herramientas. Los archivos privados se excluyen de Git; no copiar credenciales a argumentos ni logs.

`CommercialPricing` implementa bandas sobre cantidad × costo propio de la variante y un incremento sobre costo, verificado contra el helper administrativo de `codexv0`. Las cuatro reglas importadas tienen origen auditable; no se incluyen en un seeder global. El costo propio fue aprobado por el usuario el 18/11/2026, sustituyendo el mínimo del modelo del legado. El módulo está en `/admin/price-scales`, permiso `commerce.configure`.

## Evidencia e importación

- `reference.py`: SELECT exactos en `usbstock2` READ ONLY con el ejecutable/defaults-file exigidos; genera 26 referencias independientes y hashes de fuentes estáticas. No ejecuta PHP heredado.
- `local.php backup|preview|migrate|import|verify`: guardia local `usbstock_dev:3307`, actor autorizado y automatizaciones apagadas. El backup existente nunca se sobrescribe. La migración es aditiva; importar es idempotente. Estas operaciones ya se completaron en esta fase.
- `matrix.php`: compara cálculos Laravel con la fixture SQL y escribe la matriz en `../xtras/docs/MATRIZ_PRECIOS_ESCALAS_USBSTOCK.md`.
- `corporate-check.php`: ensayo de listado autorizado y ya realizado una vez. Un marcador privado bloquea repetirlo. No eliminar el marcador para reconectar. No envía métricas ni imprime claves.
- `corporate-browser.cjs`: representa la respuesta corporativa real capturada sin otra consulta de directorio; solo permite descargar las fotografías validadas. No pulsa enlaces de WhatsApp.

## Revisión administrativa aislada

`qa-prepare.php` crea una instancia SQLite en almacenamiento privado, usuario aleatorio y fixtures identificadas como QA. No carga `.env` principal y prohíbe conexiones a MariaDB y HTTP externo. Esas operaciones no tocan solicitudes, cotizaciones ni catálogo de desarrollo. `qa-browser.cjs` usa Edge y el servidor loopback temporal 4390/4391 para revisar escalas, simulación, toggle, solapamientos, conversión y ajustes explícitos en escritorio/tablet/móvil; lo cierra al terminar. La instancia Laravel del usuario en 8000 se conserva.

```powershell
php tools/pricing/qa-prepare.php
node tools/pricing/qa-browser.cjs
php artisan test --compact
php tests/Support/run_mariadb_tests.php
php tools/pricing/local.php verify
```

Las suites general y MariaDB se ejecutan secuencialmente. MariaDB requiere la configuración privada y guardias de `tests/Support/run_mariadb_tests.php`; utiliza exclusivamente `usbstock_test` con usuario dedicado. No ejecutar pruebas contra desarrollo o legado. La revisión UI muta solo fixtures de su instancia, por lo que no debe reutilizarse como una aplicación comercial.

Documentación operativa fuera de Git: `../xtras/docs/DIRECTORIO_Y_ESCALAS_COMERCIALES_USBSTOCK.md`, matriz y estado local. No se cambian totales IVA/envío, plantillas ni PDF históricos. Configuración local corporativa temporal habilitada; métricas, FTP, Scheduler, autoaplicación, Telegram y destinatario comercial apagados.

Validación 18/11/2026: 317 pruebas SQLite / 4,079 aserciones y 11 MariaDB / 888; 26 casos SQL de precios, todos coincidentes con la política aprobada. Seis diferencias deliberadas respecto al mínimo del modelo. QA Edge: directorio real en tres anchos y seis grupos administrativos en instancia aislada. Verificación de desarrollo: 35 tablas previas y dos PDF intactos; 28 migraciones aplicadas, cuatro escalas activas y ninguna partida anterior rellenada. `security-check.py` comprueba la ausencia de la clave fuera de `.env` sin mostrarla. Pint de cambios pasa; el global conserva seis archivos preexistentes con estilo pendiente.
