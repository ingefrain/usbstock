# USB Stock

Aplicación Laravel 12 para el catálogo comercial de memorias USB, la sincronización controlada de productos de proveedor y la generación de cotizaciones comerciales.

USB Stock parte del commit `293cf58743da881f06c2ec2b83a6e35ee922ee8d` de Proyecto Base y tiene su propio repositorio Git. La aplicación vive en `site`; la documentación del proyecto está en `../xtras/docs` (fuera del repositorio).

## Requisitos locales

- PHP 8.2 o compatible con Laravel 12.
- Composer y extensiones PHP requeridas por Laravel.
- MariaDB local en `127.0.0.1:3307`, con una cuenta propia para la aplicación.
- Node.js 20.19+ o 22.12+ para Vite 7.

La base de desarrollo es `usbstock_dev`. El `.env` local no se versiona. No copiar credenciales de otros proyectos. Antes de ejecutar migraciones, revisar sus efectos y validar el destino.

## Comandos de desarrollo

```powershell
composer install
npm ci
npm run dev
php artisan serve
```

Para compilar recursos, usa `npm run build`. El proceso `npm run dev` mantiene Vite activo durante el desarrollo.

## Flujo comercial y revisión visual

La web pública integra el diseño B con catálogo continuo, variantes y solicitudes persistentes. El visitante captura un vendedor como texto libre, recordado mediante cookie cifrada; costos confirma la asociación interna, convierte sin duplicar partidas y completa la cotización. Las revisiones conservan origen, cliente, vendedor y documentos históricos. Las nuevas emisiones mPDF son Letter vertical; la propuesta visual B del PDF permanece provisional.

El catálogo de desarrollo requiere autorización separada para importar datos reales. Para revisar nombres y fotografías históricos sin contaminarlo, usar [la instancia Laravel aislada](tools/commercial-review/README.md). Sus existencias y operaciones son ficticias y están identificadas como QA. Sus credenciales, archivos, reportes y SQLite permanecen en almacenamiento privado excluido de Git. No desplegar las herramientas de revisión.

Las pruebas generales usan SQLite en memoria. Las pruebas MariaDB requieren la guardia y cuenta dedicada descritas en `tests/Support/run_mariadb_tests.php`. No ejecutar pruebas contra desarrollo ni contra bases heredadas.

FTP, Scheduler comercial, aplicación automática y Telegram permanecen desactivados en el entorno local; no configurar destinatario comercial durante esta revisión. La fase visual no autoriza conexiones externas ni publicación.

## Documentación

Consulta `../xtras/docs/INDICE_DOCUMENTACION.md`, en particular la visión, el roadmap, el estado local y la evaluación de componentes reutilizables.
