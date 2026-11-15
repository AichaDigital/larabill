<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\DTO\OneOffSaleResult;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\SaleRefundStage;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\OneOffSaleService;
use AichaDigital\Larabill\Support\RawDocumentRead;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\ValueObjects\SaleRefundRequest;
use AichaDigital\Larabill\ValueObjects\SaleSettlementRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

/*
 * R3-8 (AID-1997 spec §D8, «Inherited defect scoped into 9.6.0», confirmed RED
 * by the ADE-1260 consumer suite, MR !62 of arabeconaicha): issuance regenerates
 * customer_snapshot from the row's profile whenever one is referenced —
 * overwriting the FROZEN recipient a refund supplied at creation, with
 * snapshot_at making byte equality impossible → payer_drift on every
 * profile-carrying refund.
 *
 * The fix: `issue()` preserves a SUPPLIED frozen recipient — a rectificative
 * (rectifies_invoice_id set) whose customer_snapshot is already populated
 * keeps it verbatim; the fiscal context still regenerates (it describes the
 * document itself). Ordinary invoices: unchanged regeneration.
 */

beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = TestUser::factory()->create();
    $this->service  = app(OneOffSaleService::class);

    // The consumer shape (ADE-1324 §6.0): a Spanish buyer tax profile on the
    // billed user — the profile-carrying sale the consumer suite exercises.
    $this->profile = UserTaxProfile::query()->create([
        'owner_user_id'        => $this->customer->id,
        'fiscal_name'          => 'Consumidora de Referencia S.L.',
        'tax_id'               => 'B12345678',
        'country_code'         => 'ES',
        'is_company'           => true,
        'is_eu_vat_registered' => true,
        'is_active'            => true,
        'valid_from'           => now()->subDay(),
    ]);
    $this->customer->update(['current_tax_profile_id' => $this->profile->id]);
});

function aid1997pSettleWithProfile(object $test): OneOffSaleResult
{
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $test->customer->id,
        'items'            => [['description' => 'One-off service', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);
    $test->service->reserve((string) $proforma->id, 'r3-8-op');

    return $test->service->settle(new SaleSettlementRequest(
        'r3-8-op',
        $test->customer->id,
        FixedDecimal::ofUnscaled(10000, 2),
        'EUR',
        now()->subHour()->startOfSecond(),
        PaymentMethod::Card,
        'pi_r3_8',
        $test->customer->id,
    ));
}

it('issues a rectificative preserving the supplied frozen recipient (R3-8)', function () {
    $settled = aid1997pSettleWithProfile($this);

    $refunded = $this->service->refund(new SaleRefundRequest(
        'r3-8-op',
        'r3-8-refund',
        null,
        'EUR',
        now()->startOfSecond(),
        PaymentMethod::BankTransfer,
        'ref_r3_8',
        $this->customer->id,
        'R3-8 red: profile-carrying refund',
    ));

    // The refund completes: no payer_drift, the rectificative is ISSUED and
    // its recipient is the ORIGINAL sale invoice's snapshot — not a
    // regenerated one.
    expect($refunded->refund->stage)->toBe(SaleRefundStage::Refunded)
        ->and($refunded->rectificative)->not->toBeNull()
        ->and($refunded->rectificative->customer_snapshot)->toBe($settled->invoice->customer_snapshot);
});

it('still regenerates the recipient on ordinary issuance (the boundary, R3-8)', function () {
    // An ORDINARY invoice (no rectifies_invoice_id): issuance still stamps the
    // recipient from the live profile — a decryptable, profile-bearing
    // snapshot with snapshot_at (the regeneration the rectificative path must
    // not do).
    $invoice = app(InvoiceService::class)->createInvoice([
        'billable_user_id'    => $this->customer->id,
        'type'                => 'invoice',
        'items'               => [['description' => 'Ordinary service', 'quantity' => 100, 'base_price' => 5000]],
        'user_tax_profile_id' => (string) $this->profile->id,
    ], ['make_immutable' => true]);

    $issued = app(InvoiceService::class)->issue($invoice->fresh());

    $rawSnapshot = (string) RawDocumentRead::row('invoices', (string) $issued->id)['customer_snapshot'];
    expect($rawSnapshot)->toStartWith('eyJ');

    $decoded = json_decode(Crypt::decryptString($rawSnapshot), true);
    expect($decoded['tax_id'] ?? null)->toBe('B12345678')
        ->and($decoded['snapshot_at'] ?? null)->not->toBeNull(); // the regeneration stamp
});
