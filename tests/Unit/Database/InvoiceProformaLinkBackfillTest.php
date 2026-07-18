<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\TestCase;

/**
 * AID-555 (D2) — backfill of the canonical proforma link on invoices
 * converted BEFORE the service started writing invoices.proforma_id.
 *
 * Legacy pairs carry only the inverse mirror (proforma.converted_invoice_id);
 * the factory-created state below IS the legacy shape (proforma_id was never
 * written by any real flow). The migration completes the invoice→proforma
 * direction from the mirror, without touching consumer-written links.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);
});

function aid555RunBackfill(): void
{
    $migration = include __DIR__.'/../../../database/migrations/2026_07_18_000001_backfill_invoice_proforma_link.php';
    $migration->up();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function aid555Invoice(array $attributes): Invoice
{
    return Invoice::factory()->create($attributes + [
        'user_id'          => TestCase::USER_UUID_1,
        'billable_user_id' => TestCase::USER_UUID_1,
    ]);
}

it('completes the canonical link from the inverse mirror on legacy converted pairs', function () {
    $final    = aid555Invoice(['serie' => InvoiceSerieType::INVOICE->value]);
    $proforma = aid555Invoice([
        'serie'                => InvoiceSerieType::PROFORMA->value,
        'converted_invoice_id' => $final->id,
    ]);

    expect($final->fresh()->proforma_id)->toBeNull();

    aid555RunBackfill();

    expect((string) $final->fresh()->proforma_id)->toBe((string) $proforma->id);
});

it('leaves already-linked and unconverted invoices untouched', function () {
    // Consumer-written link: the mirror must never overwrite it.
    $otherProforma = aid555Invoice(['serie' => InvoiceSerieType::PROFORMA->value]);
    $linked        = aid555Invoice([
        'serie'       => InvoiceSerieType::INVOICE->value,
        'proforma_id' => $otherProforma->id,
    ]);
    aid555Invoice([
        'serie'                => InvoiceSerieType::PROFORMA->value,
        'converted_invoice_id' => $linked->id,
    ]);

    // Never converted: no mirror points at it.
    $standalone = aid555Invoice(['serie' => InvoiceSerieType::INVOICE->value]);

    aid555RunBackfill();

    expect((string) $linked->fresh()->proforma_id)->toBe((string) $otherProforma->id)
        ->and($standalone->fresh()->proforma_id)->toBeNull();
});
