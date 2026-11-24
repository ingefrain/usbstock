# Validación comercial de la API — entorno local

Herramientas del ensayo autorizado de noviembre de 2026. No son endpoints,
seeders globales ni procedimientos de despliegue. Los resultados y decisiones
se documentan en `../xtras/docs/CIERRE_API_GRUPOTALENTO_USBSTOCK.md`, fuera de Git.

El flujo original exige el worktree `E:/proyectos/usbstock/api-grupotalento`,
SQLite experimental, servidor de loopback 8001, correo array y todas las salidas
comerciales desactivadas. Los datos añadidos son ficticios. No ejecutar PHP
heredado, migrar desarrollo ni copiar credenciales para repetir el ensayo.

- `backup.py`: respaldo previo verificado; se detiene si ya existe o si el
  estado inicial no es exactamente la fixture 3000 y el catálogo autorizado.
- `local.php prepare|session|state|grant`: preparación del actor y sesión QA,
  capturas de estado y ampliación del alcance exclusivamente a la nueva
  cotización ficticia. Nunca muestra la sesión ni el Bearer. La preparación
  no se repite sobre un contexto existente.
- `browser-flow.cjs`: Edge/Playwright, formulario público, costos,
  emisión, revisión, reemisión, JSON y PDF. El diario privado evita repetir
  mutaciones ambiguas. No ejecutar de nuevo a ciegas ni eliminar el diario.
- `compare-contract.py --reference-attachment PATH`: comparación de 3000
  con el JSON del adjunto original, mantenido sólo en memoria; el informe
  conserva tipos y diferencias, sin datos personales del ejemplo ni URL PDF.
- `verify-commercial.py`: conciliación independiente a centavos, lectura
  SQLite, hashes de catálogo/histórico/documentos y extracción/render de PDF.
  La revisión visual de las páginas sigue siendo una comprobación humana.
- `stable-state.py before|after`: únicamente lectura de 18 tablas de
  `usbstock_dev` mediante el cliente/defaults-file autorizados. Persiste
  contadores y hashes; exige esquema de 28 migraciones y API desactivada.
- `postmerge-prepare.py COMMIT`, `postmerge-bootstrap.php`,
  `postmerge-check.php`, `postmerge-router.php`, `postmerge-server.cjs` y
  `postmerge-browser.cjs`: validación de main después de integrar.
  Preparan una copia SQLite privada, almacenamiento independiente y clave QA
  nueva en `storage/app/private/qa/api-postmerge`; no leen el .env estable,
  no conectan a MariaDB y no migran. El frontend se limita a loopback 4398 y el proceso PHP interno a 4399.

Los helpers conservan los artefactos privados bajo storage. No incorporar a Git
SQLite, logs, capturas, PDF, estados, cookies ni claves. Los respaldos de xtras
usan una ACL restringida y manifiestos de hashes; excluyen .env, sesiones y
capacidades documentales. El .env original permanece en el worktree.

El ensayo comercial terminado invalida la suposición de “única fixture” del
antiguo `tools/api-restoration/restore_catalog.py verify`. Para preservar y
verificar el estado ampliado se utiliza `verify-commercial.py`; no alterar
el catálogo o eliminar cotizaciones para satisfacer la suposición anterior.

Dependencias: PHP y vendor del proyecto; Python con pdfplumber y Poppler para
PDF; Node y Playwright/Edge del runtime local. No añade dependencias de
aplicación. Revisar los argumentos y guardias de cada herramienta antes de
cualquier repetición. No se afirma compatibilidad real del consumidor Grupo
Talento sin ensayarlo en un entorno suyo autorizado.


## Comprobación posterior al merge

Ejecutar únicamente después de revisar e integrar el commit, desde
`E:/proyectos/usbstock/site`, en `main` limpio. La preparación exige que el flujo
experimental y su conciliación hayan terminado correctamente. Lee sólo la
SQLite experimental y copia los directorios privados `documents`, `quotes` y
`quote-assets`, verificando tamaño, SHA-256, integridad SQLite y claves foráneas.
No carga `.env`, no copia sus claves, no ejecuta migraciones ni accede a
`usbstock_dev`. Conserva una clave aleatoria nueva sólo en la instancia QA.

```powershell
Set-Location -LiteralPath 'E:\proyectos\usbstock\site'
$qaReviewedMainCommit = git -c safe.directory=E:/proyectos/usbstock/site rev-parse HEAD
python tools/api-commercial-validation/postmerge-prepare.py $qaReviewedMainCommit
php tools/api-commercial-validation/postmerge-check.php status
node tools/api-commercial-validation/postmerge-browser.cjs
```

`postmerge-prepare.py` se detiene si la instancia ya existe; no sobrescribirla
para repetir una preparación. `postmerge-check.php status` muestra únicamente
contadores y las comprobaciones de aislamiento. El navegador prepara una sesión
para el administrador ficticio ya clonado, abre inicio en escritorio/móvil,
catálogo, ficha, administración, solicitud convertida y cotización emitida;
descarga los dos PDF y compara sus hashes con los originales copiados. Comprueba
que ambas consultas API devuelvan 401 y el PDF API devuelva 404. No crea ni revisa
cotizaciones. La cookie, sesiones, cachés, vistas compiladas y almacenamiento
pertenecen a esta instancia; API, directorio corporativo y salidas comerciales
permanecen desactivados.

`postmerge-browser.cjs` inicia y detiene su servidor mediante
`postmerge-server.cjs`: exige libres los puertos 4398/4399, sirve únicamente
assets públicos permitidos y transmite las rutas de aplicación a PHP real.
El proceso PHP se abre oculto y se detiene al finalizar, incluso si falla una
comprobación. No iniciar otro servidor para este recorrido. Ante una terminación
forzada, identificar el proceso de este router y sus puertos antes de cerrarlo;
no detener otros procesos PHP o Node. La sesión ficticia se cierra mediante la
interfaz al completar el recorrido.

El informe y las capturas quedan en
`storage/app/private/qa/api-postmerge/browser-report.json` y su directorio.
La regresión anterior del experimento conserva su informe final en
`storage/app/private/qa/api-restoration/browser/report.json`, con once capturas
aprobadas. Los informes de servidor inicialmente detenido y disponibilidad
transitoria del directorio permanecen junto a la evidencia, sin contarlos como
pruebas aprobadas.
