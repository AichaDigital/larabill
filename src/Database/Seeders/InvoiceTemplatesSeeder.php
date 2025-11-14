<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Seeders;

use AichaDigital\Larabill\Models\InvoiceTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeder for invoice templates
 */
class InvoiceTemplatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            // Fiscal templates
            [
                'name'          => 'default',
                'display_name'  => 'Plantilla Estándar',
                'type'          => 'fiscal',
                'template_path' => 'larabill::pdf.invoice.fiscal',
                'description'   => 'Plantilla estándar para facturas fiscales con QR',
                'is_default'    => true,
                'is_active'     => true,
                'settings'      => [
                    'show_qr'            => true,
                    'show_legal_notes'   => true,
                    'show_payment_terms' => true,
                ],
            ],
            [
                'name'          => 'modern',
                'display_name'  => 'Plantilla Moderna',
                'type'          => 'fiscal',
                'template_path' => 'larabill::pdf.invoice.fiscal-modern',
                'description'   => 'Plantilla moderna con diseño actualizado',
                'is_default'    => false,
                'is_active'     => true,
                'settings'      => [
                    'show_qr'            => true,
                    'show_legal_notes'   => true,
                    'show_payment_terms' => true,
                    'color_scheme'       => 'blue',
                ],
            ],
            [
                'name'          => 'minimal',
                'display_name'  => 'Plantilla Minimalista',
                'type'          => 'fiscal',
                'template_path' => 'larabill::pdf.invoice.fiscal-minimal',
                'description'   => 'Plantilla minimalista con información esencial',
                'is_default'    => false,
                'is_active'     => true,
                'settings'      => [
                    'show_qr'            => true,
                    'show_legal_notes'   => false,
                    'show_payment_terms' => true,
                ],
            ],

            // Proforma templates
            [
                'name'          => 'default',
                'display_name'  => 'Plantilla Estándar',
                'type'          => 'proforma',
                'template_path' => 'larabill::pdf.invoice.proforma',
                'description'   => 'Plantilla estándar para facturas proforma',
                'is_default'    => true,
                'is_active'     => true,
                'settings'      => [
                    'show_qr'            => false,
                    'show_legal_notes'   => true,
                    'show_payment_terms' => true,
                ],
            ],
            [
                'name'          => 'simple',
                'display_name'  => 'Plantilla Simple',
                'type'          => 'proforma',
                'template_path' => 'larabill::pdf.invoice.proforma-simple',
                'description'   => 'Plantilla simple para proformas rápidas',
                'is_default'    => false,
                'is_active'     => true,
                'settings'      => [
                    'show_qr'            => false,
                    'show_legal_notes'   => false,
                    'show_payment_terms' => false,
                ],
            ],

            // Reverse Charge templates
            [
                'name'          => 'default',
                'display_name'  => 'Plantilla Estándar',
                'type'          => 'reverse-charge',
                'template_path' => 'larabill::pdf.invoice.reverse-charge',
                'description'   => 'Plantilla para facturas con reverse charge',
                'is_default'    => true,
                'is_active'     => true,
                'settings'      => [
                    'show_qr'                    => true,
                    'show_legal_notes'           => true,
                    'show_payment_terms'         => true,
                    'show_reverse_charge_notice' => true,
                ],
            ],

            // Exempt templates
            [
                'name'          => 'default',
                'display_name'  => 'Plantilla Estándar',
                'type'          => 'exempt',
                'template_path' => 'larabill::pdf.invoice.exempt',
                'description'   => 'Plantilla para facturas exentas',
                'is_default'    => true,
                'is_active'     => true,
                'settings'      => [
                    'show_qr'            => true,
                    'show_legal_notes'   => true,
                    'show_payment_terms' => true,
                    'show_exempt_notice' => true,
                ],
            ],
        ];

        foreach ($templates as $template) {
            InvoiceTemplate::updateOrCreate(
                [
                    'name' => $template['name'],
                    'type' => $template['type'],
                ],
                $template
            );
        }
    }
}
