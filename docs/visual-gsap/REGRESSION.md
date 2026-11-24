# Regresión del experimento visual

Validación local del 24 de noviembre de 2026 en `experiment/visual-gsap`, derivada de `16e92350b34db06d352bb4a1cacc3999b63bbc4d`.

## Resultado final

| Comprobación | Resultado |
| --- | --- |
| Suite PHPUnit completa existente | **322 aprobadas; 12 omitidas; 0 fallos; 0 errores** |
| Total descubierto / aserciones | **334 pruebas / 4.190 aserciones** |
| Tiempo / memoria de la corrida final | 2 min 28,919 s / 152 MiB |
| PHPUnit / PHP | 11.5.57 / 8.2.12 |
| Composer `validate --strict --no-check-publish` | Aprobado: `composer.json is valid` |

Las 12 omisiones son exclusivamente los 12 métodos de `MariaDbOutboxIntegrationTest`. No se ejecutaron integraciones contra MariaDB. El log completo final está en `storage/app/private/qa/visual-gsap/phpunit.log`, excluido de Git.

## Cobertura pública relevante

Estas clases existentes quedaron incluidas y aprobadas en la corrida completa:

| Clase | Métodos | Contratos cubiertos |
| --- | ---: | --- |
| `PublicCatalogTest` | 8 | Bloques de 24, cursores, filtros, visibilidad, categorías, fotografías, límites y continuidad sin duplicados |
| `PublicCartAjaxTest` | 7 | Selección AJAX, revisión de sesión, cantidades, idempotencia y conservación del formulario |
| `CommercialAvailabilityTest` | 3 | Umbral comercial de 50 piezas y variantes disponibles |
| `CommercialTaxonomyTest` | 3 | Jerarquía y navegación por categorías reales |
| `CorporateContactsTest` | 7 | Selector corporativo, contratos de consulta y manejo del proveedor |
| `CommercialSellerFlowTest` | 7 | Asesor asignado, solicitud y flujo comercial |

La suite completa también verificó autorización administrativa, cotizaciones, historial inmutable, cálculos y generación PDF. Se mantuvieron las pruebas comerciales existentes. `ExampleTest` actualiza únicamente la expectativa de presentación de la portada al titular y CTA del rediseño.

## Aislamiento y reproducción

El worktree no utiliza `.env`. `phpunit.xml` fuerza `APP_ENV=testing`, SQLite `:memory:`, sesiones/cache en memoria, correo de prueba e integraciones deshabilitadas. `tests/bootstrap.php` verifica dichas variables, las refleja en `$_SERVER` y rechaza configuración cacheada. `TestDatabaseSafety` permanece activo.

Ejecutar desde la raíz del worktree en PowerShell. La clave se genera exclusivamente para el proceso de pruebas y no se muestra ni se guarda:

```powershell
$env:OUTBOX_MARIADB_TEST = '0'
$env:APP_NAME = 'USB Stock'
$qaKeyBytes = [byte[]]::new(32)
[Security.Cryptography.RandomNumberGenerator]::Fill($qaKeyBytes)
$env:APP_KEY = 'base64:' + [Convert]::ToBase64String($qaKeyBytes)
& 'C:\xampp\php\php.exe' vendor/bin/phpunit --colors=never --display-skipped
& 'C:\xampp\php\composer.bat' validate --strict --no-check-publish
```

La revisión detectó requisitos del entorno independiente: sin `APP_KEY` Laravel rechaza las pruebas HTTP; sin `APP_NAME` el nombre predeterminado es Laravel. Un directorio temporal PDF de una corrida interrumpida se trasladó, conservándolo, a la evidencia privada de QA. Las cinco comprobaciones afectadas por el entorno aprobaron después con 575 aserciones; la corrida completa final confirma el resultado. Los logs iniciales y de diagnóstico se conservan separados del log final.

## Límites

Esta evidencia corresponde a regresión automatizada en SQLite. No sustituye la revisión visual en navegador, la validación responsiva ni las métricas de rendimiento, que se documentan por separado. No se ejecutó una suite de escritura sobre la base de desarrollo, no se modificaron cotizaciones históricas ni se contactaron integraciones de producción.
