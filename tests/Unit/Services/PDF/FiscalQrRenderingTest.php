<?php

declare(strict_types=1);

it('renders the fiscal verification QR as an actual SVG in the fiscal PDF template', function () {
    $invoice = (object) [
        'number'                 => 'A-1',
        'created_at'             => now(),
        'status'                 => 'draft',
        'fiscal_verification_qr' => '<svg data-testid="verifactu-qr"></svg>',
    ];

    $html = view('larabill::pdf.invoice.fiscal', [
        'invoice'           => $invoice,
        'company'           => [
            'name'        => 'Castris',
            'address'     => 'Main Street',
            'postal_code' => '08001',
            'city'        => 'Barcelona',
            'country'     => 'ES',
            'tax_id'      => 'B12345678',
            'phone'       => '930000000',
            'email'       => 'billing@example.test',
            'website'     => 'https://example.test',
        ],
        'client'            => [],
        'items'             => [[
            'description' => 'Service',
            'quantity'    => 1,
            'unit_price'  => 10000,
            'tax_rate'    => 21,
            'tax_amount'  => 2100,
            'total'       => 12100,
        ]],
        'totals'            => [
            'subtotal'   => 10000,
            'tax_amount' => 2100,
            'total'      => 12100,
        ],
        'fiscal_data'       => [],
        'include_qr'        => true,
        'qr_data'           => [
            'qr_svg' => $invoice->fiscal_verification_qr,
            'qr_url' => 'https://prewww2.aeat.es/qr?id=REG-000001',
        ],
        'generated_at'      => now(),
        'notes'             => null,
        'payment_terms'     => null,
        'template_settings' => [],
    ])->render();

    expect($html)->toContain('QR tributario:')
        ->and($html)->toContain('<svg data-testid="verifactu-qr"></svg>')
        ->and($html)->not->toContain('&lt;svg data-testid=&quot;verifactu-qr&quot;&gt;&lt;/svg&gt;')
        ->and($html)->toContain('https://prewww2.aeat.es/qr?id=REG-000001');
});
