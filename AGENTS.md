# Guía operativa

USB Stock es un proyecto Laravel independiente derivado de Proyecto Base. El dominio comercial (catálogo USB, sincronización con proveedor y cotizaciones) se define en este repositorio; la documentación operativa está en ../xtras/docs.

## Fuentes de verdad

El código está en este repositorio site. El material auxiliar está en ../xtras y la documentación en ../xtras/docs.
Antes de implementar, identifica la tarea y consulta la guía pertinente. Si no sabes cuál corresponde, empieza por ../xtras/docs/INDICE_DOCUMENTACION.md.

- ROADMAP_USBSTOCK.md para fases planificadas.
- VISION_USBSTOCK.md para alcance y decisiones del dominio.
- COMPONENTES_REUTILIZABLES.md antes de crear vistas o componentes administrativos.
- POLITICA_DATOS_PRUEBA.md para seeders, factories, fixtures y limpieza.
- DERIVAR_NUEVO_PROYECTO.md como registro del procedimiento de origen; no volver a aplicar pasos de creación del repositorio.
- ESTADO_LOCAL_USBSTOCK.md para el entorno y pendientes de preparación.

Todos esos documentos están en ../xtras/docs. Las migraciones definen el esquema.

## Convenciones visuales

Revisa las vistas existentes. Usa superficie administrativa centrada o amplia y grid responsive. Cada campo agrupa label, control, ayuda y error. Evita tarjetas limitadas por max-width pegadas a la izquierda.
Reutiliza Dropzone, Markdown, Toggle para estados reversibles, Notify para feedback temporal, Alert para información persistente y Modal/Confirm Dialog para confirmaciones. Evita window.alert() y window.confirm(). Revisa los componentes propios antes de introducir otra dependencia.

## Datos y seguridad

Seeders solo estructurales e idempotentes. Factories para tests y fixtures pequeñas por escenario. No crear precarga DEMO global ni depender de registros de desarrollo.
No usar migrate:fresh en desarrollo o producción. Revisa migraciones con migrate --pretend. Conserva los datos manuales del usuario.
No mostrar secretos ni sustituir credenciales configuradas sin autorización explícita. En MariaDB local usa el ejecutable y defaults-file documentados en el contexto de trabajo y selecciona explícitamente la base de la tarea. Conserva autorización server-side y actor real durante impersonación. No force push.

## Documentación y derivación

Al introducir una convención transversal, decide si corresponde a esta guía, al índice o a una guía temática. Mantén este archivo compacto.
Conserva las convenciones administrativas reutilizables. No incorpores código de negocio de otros derivados. No ejecutar migraciones sin revisar antes sus efectos y el destino. No añadir datos DEMO globales. No presupongas que xtras/docs se incluye en Git.
