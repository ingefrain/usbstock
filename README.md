# USB Stock

Aplicación Laravel 12 para el catálogo comercial de memorias USB, la sincronización controlada de productos de proveedor y la generación de cotizaciones comerciales.

USB Stock parte del commit `293cf58743da881f06c2ec2b83a6e35ee922ee8d` de Proyecto Base y tiene su propio repositorio Git, actualmente sin commits. La aplicación vive en `site`; la documentación del proyecto está en `../xtras/docs`.

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

Para compilar recursos, usa `npm run build`. El proceso `npm run dev` mantiene Vite activo durante el desarrollo. La aplicación no incluye por ahora los módulos de negocio de catálogo ni cotizaciones.

## Documentación

Consulta `../xtras/docs/INDICE_DOCUMENTACION.md`, en particular la visión, el roadmap, el estado local y la evaluación de componentes reutilizables.
