<?php

namespace App\Commerce;

final class CommercePermissions
{
    public const DEFINITIONS = [
        'sellers.manage' => 'Administrar el directorio comercial de vendedores',
        'products.view' => 'Consultar catálogo comercial',
        'products.manage' => 'Administrar productos, imágenes y categorías',
        'supplier_sync.view' => 'Consultar sincronizaciones de proveedor',
        'supplier_sync.create' => 'Validar archivos del proveedor',
        'supplier_sync.apply' => 'Aplicar sincronizaciones revisadas',
        'supplier_sync.approve' => 'Aprobar cambios masivos de proveedor',
        'quotes.view' => 'Consultar cotizaciones comerciales',
        'quotes.manage' => 'Crear y editar borradores de cotización',
        'quotes.issue' => 'Emitir cotizaciones y versiones PDF',
        'clients.manage' => 'Administrar clientes comerciales',
        'commerce.configure' => 'Configurar emisor y condiciones comerciales',
        'quote_requests.manage' => 'Atender solicitudes públicas de cotización',
    ];
}
