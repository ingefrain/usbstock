# Contrato v1 anonimizado

`quote-v1-anonymized.json` deriva exclusivamente del ejemplo JSON de estructura proporcionado por el usuario en el adjunto de instrucciones de la Fase API 2, sección 6 «Estructura JSON obligatoria», recibido el 24 de noviembre de 2026. La auditoría estática de Fase 1 se utilizó como referencia adicional de campos y valores compatibles.

Se conservaron claves, objetos, niveles, arreglos, tipos JSON, valores null y formatos de fecha del ejemplo. Los identificadores, folio, fechas, contacto, producto, importes, comentario y URL se sustituyeron por valores sintéticos. Los dominios `.invalid` no representan destinatarios ni documentos recuperables. No se incluyen credenciales, datos de clientes reales ni enlaces documentales originales.

Este archivo es una referencia estructural versionada por petición expresa del usuario. No es un registro para importar, una respuesta capturada durante estas pruebas ni evidencia de que esos números correspondan a cotizaciones Laravel. Tampoco define nuevas reglas económicas.

La comparación de contrato debe contemplar null donde el campo lo permite: por ejemplo, el snapshot nativo actual puede carecer de ID de partida, SKU de variante, teléfono secundario o PDF disponible. `items_html` puede contener las filas escapadas del snapshot aunque sea null en este ejemplo. `archived=true` ilustra el tipo booleano del legado; la proyección nativa actual devuelve false porque no dispone de estado de archivo.

Los importes se comparan como números JSON, sin exigir una cantidad textual fija de decimales. Para Laravel, `sale_amount` es el neto de subtotal menos descuento persistidos, según la decisión comercial de esta fase; estos valores sintéticos no sustituyen las pruebas de dicha proyección.
