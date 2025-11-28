<?php

declare(strict_types=1);

/**
 * Filament UI Translations (Spanish)
 *
 * All user-facing strings for Larabill Filament resources.
 * Code remains in English, UI translations in Spanish.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Navigation & General
    |--------------------------------------------------------------------------
    */
    'navigation' => [
        'group' => 'Facturación',
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice Resource
    |--------------------------------------------------------------------------
    */
    'invoice' => [
        // Labels
        'label'            => 'Factura',
        'plural_label'     => 'Facturas',
        'navigation_label' => 'Facturas',

        // Fields
        'fiscal_number'       => 'Número Fiscal',
        'fiscal_number_help'  => 'Número fiscal único para esta factura (ej: FAC-2025-001)',
        'status'              => 'Estado',
        'invoice_date'        => 'Fecha Factura',
        'service_date'        => 'Fecha Servicio',
        'due_date'            => 'Fecha Vencimiento',
        'customer'            => 'Cliente',
        'reverse_charge'      => 'Inversión Sujeto Pasivo',
        'reverse_charge_help' => 'Operación intracomunitaria B2B (ROI)',
        'immutable'           => 'Inmutable',
        'roi'                 => 'ROI',
        'total'               => 'Total',
        'save_to_see_totals'  => 'Guarda para ver totales',

        // Sections
        'header'           => 'Información General',
        'customer_section' => 'Cliente y Fechas',
        'items'            => 'Líneas de Factura',
        'totals'           => 'Totales',
        'details'          => 'Detalles de Factura',

        // Item fields
        'add_item'         => 'Añadir Línea',
        'article'          => 'Artículo',
        'description'      => 'Descripción',
        'quantity'         => 'Cantidad',
        'unit_price'       => 'Precio Unitario',
        'taxable_amount'   => 'Base Imponible',
        'tax_rate'         => 'Tipo IVA',
        'total_tax_amount' => 'Cuota IVA',
        'total_amount'     => 'Total',

        // Actions
        'calculate_totals' => 'Calcular Totales',
        'finalize'         => 'Finalizar Factura',
        'register_aeat'    => 'Registrar en AEAT',

        // Bulk Actions
        'calculate_totals_bulk' => 'Calcular Totales (múltiple)',

        // Notifications
        'totals_calculated'    => 'Totales calculados correctamente',
        'invoice_finalized'    => 'Factura finalizada. Ahora es inmutable.',
        'registered_with_aeat' => 'Factura registrada con AEAT correctamente',
        'registration_failed'  => 'Error al registrar con AEAT',

        // Filters
        'filter_status'         => 'Estado',
        'filter_reverse_charge' => 'Inversión Sujeto Pasivo',
        'filter_immutable'      => 'Inmutable',
        'filter_date_from'      => 'Desde',
        'filter_date_until'     => 'Hasta',
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Resource
    |--------------------------------------------------------------------------
    */
    'customer' => [
        'label'            => 'Cliente',
        'plural_label'     => 'Clientes',
        'navigation_label' => 'Clientes',

        // Fields
        'display_name'  => 'Nombre Fiscal',
        'tax_code'      => 'NIF/CIF/VAT',
        'country_code'  => 'País',
        'is_company'    => 'Es Empresa',
        'business_name' => 'Razón Social',
        'address'       => 'Dirección',
        'city'          => 'Ciudad',
        'postal_code'   => 'Código Postal',
        'state'         => 'Provincia/Estado',
        'phone'         => 'Teléfono',
        'email'         => 'Email',

        // Sections
        'basic_info'   => 'Información Básica',
        'tax_profile'  => 'Perfil Fiscal',
        'address_info' => 'Dirección',
        'contact_info' => 'Contacto',

        // Actions
        'archive'       => 'Archivar',
        'export'        => 'Exportar',
        'view_invoices' => 'Ver Facturas',
        'view_tickets'  => 'Ver Tickets',

        // Notifications
        'archived' => 'Cliente archivado correctamente',
        'exported' => 'Cliente exportado correctamente',
    ],

    /*
    |--------------------------------------------------------------------------
    | Article Resource
    |--------------------------------------------------------------------------
    */
    'article' => [
        'label'            => 'Artículo',
        'plural_label'     => 'Artículos',
        'navigation_label' => 'Artículos',

        // Fields
        'name'          => 'Nombre',
        'sku'           => 'SKU/Referencia',
        'description'   => 'Descripción',
        'base_price'    => 'Precio Base',
        'tax_rate'      => 'Tipo IVA',
        'is_service'    => 'Es Servicio',
        'is_recurring'  => 'Recurrente',
        'billing_cycle' => 'Ciclo de Facturación',

        // Sections
        'basic_info'         => 'Información Básica',
        'pricing'            => 'Precios',
        'recurring_settings' => 'Configuración Recurrencia',

        // Actions
        'duplicate'  => 'Duplicar',
        'deactivate' => 'Desactivar',

        // Notifications
        'duplicated'  => 'Artículo duplicado correctamente',
        'deactivated' => 'Artículo desactivado correctamente',
    ],

    /*
    |--------------------------------------------------------------------------
    | Common Terms
    |--------------------------------------------------------------------------
    */
    'common' => [
        'create'     => 'Crear',
        'edit'       => 'Editar',
        'view'       => 'Ver',
        'delete'     => 'Eliminar',
        'cancel'     => 'Cancelar',
        'save'       => 'Guardar',
        'search'     => 'Buscar',
        'filter'     => 'Filtrar',
        'export'     => 'Exportar',
        'import'     => 'Importar',
        'actions'    => 'Acciones',
        'created_at' => 'Creado',
        'updated_at' => 'Actualizado',
        'deleted_at' => 'Eliminado',
        'yes'        => 'Sí',
        'no'         => 'No',
        'enabled'    => 'Habilitado',
        'disabled'   => 'Deshabilitado',
        'active'     => 'Activo',
        'inactive'   => 'Inactivo',
    ],

    /*
    |--------------------------------------------------------------------------
    | Money Format Helpers
    |--------------------------------------------------------------------------
    */
    'format' => [
        'currency_symbol'     => '€',
        'currency_code'       => 'EUR',
        'decimal_separator'   => ',',
        'thousands_separator' => '.',
        'percentage_suffix'   => '%',
    ],
];
