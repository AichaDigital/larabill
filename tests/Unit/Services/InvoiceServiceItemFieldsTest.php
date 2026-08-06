<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\UnitMeasure;
use AichaDigital\Larabill\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-836 (spec D10): the calculated branch of createInvoiceItem() must
 * persist every optional field the InvoiceItemData shape promises. It used
 * to silently drop item_type, internal_code, unit_measure_id, the service
 * period and metadata — and the recurring flow's idempotency check filters
 * exactly on metadata + service_date_from, so dropping them would make the
 * delegated recurring path duplicate invoices.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->userModel = config('larabill.user_model', 'App\\Models\\User');
});

it('persists the promised optional item fields on the calculated branch', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->create(['tax_group_id' => null]);
    $unit     = UnitMeasure::factory()->create();

    $invoice = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => (string) $customer->id,
        'items'            => [
            [
                'article_id'        => $article->id,
                'base_price'        => 2900,
                'description'       => 'Monthly hosting service',
                'item_type'         => ItemType::SERVICE,
                'internal_code'     => 'SRV-2026-001',
                'unit_measure_id'   => $unit->id,
                'service_date_from' => '2026-01-15',
                'service_date_to'   => '2026-02-14',
                'metadata'          => [
                    'source_reference' => [
                        'type'              => 'article_service',
                        'service_status_id' => 42,
                    ],
                ],
            ],
        ],
    ]);

    $item = $invoice->items->first();

    expect($item->item_type)->toBe(ItemType::SERVICE)
        ->and($item->internal_code)->toBe('SRV-2026-001')
        ->and($item->unit_measure_id)->toBe($unit->id)
        ->and($item->service_date_from?->toDateString())->toBe('2026-01-15')
        ->and($item->service_date_to?->toDateString())->toBe('2026-02-14')
        ->and($item->metadata['source_reference']['service_status_id'])->toBe(42)
        ->and($item->metadata['source_reference']['type'])->toBe('article_service');
});
