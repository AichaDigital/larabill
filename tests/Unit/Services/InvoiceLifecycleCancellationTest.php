<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\InvoiceNotCancellableException;
use AichaDigital\Larabill\Exceptions\InvoiceNotIssuableException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ADR-016 §10/§11 (PR-2) — the audited release of a conversion-born PREPARED
 * document and the resumability of `convertAndIssue()`.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    UserTaxProfile::factory()->create([
        'owner_user_id' => TestCase::USER_UUID_1,
        'tax_id'        => 'B12345678',
        'valid_until'   => null,
    ]);
});

function larabillPreparedPair(InvoiceService $service): array
{
    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Line', 'quantity' => 100, 'base_price' => 2500]],
    ]);

    $result = $service->convertProformaToInvoice($proforma);

    return [$proforma, $result->invoice];
}

it('releases a conversion-born PREPARED document with an audit trail (§10)', function () {
    $service               = app(InvoiceService::class);
    [$proforma, $prepared] = larabillPreparedPair($service);

    $cancelled = $service->cancelPreparedInvoice($prepared, 'operator-7');

    // The document: CANCELLED, NEVER numbered, retaining the proforma trace.
    expect($cancelled->invoice_document_status)->toBe(InvoiceDocumentStatus::CANCELLED)
        ->and($cancelled->fiscal_number)->toBeNull()
        ->and($cancelled->fiscal_submission_status)->toBeNull()
        ->and((string) $cancelled->proforma_id)->toBe((string) $proforma->id)
        ->and($cancelled->document_cancelled_at)->not->toBeNull()
        ->and($cancelled->document_cancelled_by)->toBe('operator-7');

    // The proforma: back to DRAFT, immutability reverted, mirror cleared.
    $proforma = $proforma->refresh();
    expect($proforma->status)->toBe(InvoiceStatus::DRAFT)
        ->and($proforma->is_immutable)->toBeFalse()
        ->and($proforma->converted_invoice_id)->toBeNull()
        ->and($proforma->converted_at)->toBeNull();
});

it('allows re-conversion after the release: a NEW document, never the cancelled one (§10)', function () {
    $service               = app(InvoiceService::class);
    [$proforma, $prepared] = larabillPreparedPair($service);

    $service->cancelPreparedInvoice($prepared, 'operator-7');

    $second = $service->convertProformaToInvoice($proforma->fresh());

    expect($second->invoice->id)->not->toBe($prepared->id)
        ->and($second->invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
        ->and($proforma->fresh()->status)->toBe(InvoiceStatus::CONVERTING)
        ->and($proforma->fresh()->converted_invoice_id)->toBe($second->invoice->id);
});

it('refuses a non-PREPARED document typed (§10: PREPARED only)', function () {
    $service = app(InvoiceService::class);
    $issued  = $service->createInvoice(['billable_user_id' => TestCase::USER_UUID_1, 'items' => []]);
    $issued  = $service->issue($issued);

    expect(fn () => $service->cancelPreparedInvoice($issued, 'operator-7'))
        ->toThrow(InvoiceNotCancellableException::class, 'ISSUED');
});

it('refuses a direct DRAFT typed: direct documents keep the consumer path (§10)', function () {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice(['billable_user_id' => TestCase::USER_UUID_1, 'items' => []]);

    expect(fn () => $service->cancelPreparedInvoice($draft, 'operator-7'))
        ->toThrow(InvoiceNotCancellableException::class);
});

it('resumes convertAndIssue idempotently after an issuance failure: never a second invoice (§11)', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Line', 'quantity' => 100, 'base_price' => 2500]],
    ]);

    // Transaction 1 commits alone (CONVERTING + PREPARED, §11); the issuance
    // transaction then fails — simulate it by blocking the issuer config.
    $service->convertProformaToInvoice($proforma, ['on_changes' => 'ignore']);
    $config = CompanyFiscalConfig::getActive();
    $config->update(['is_active' => false]);

    // The conversion leg of the retry is mirror-idempotent; the issuance leg
    // fails typed. Same convergence a crashed process would reach.
    try {
        $service->convertAndIssue($proforma);
        expect()->fail('expected the resumable issuance to fail typed');
    } catch (InvoiceNotIssuableException $e) {
        expect($e->getMessage())->toContain('CompanyFiscalConfig');
    }

    // The legal resumable state: proforma CONVERTING, document PREPARED.
    expect($proforma->fresh()->status)->toBe(InvoiceStatus::CONVERTING);
    $prepared = Invoice::query()->whereNotNull('proforma_id')->sole();
    expect($prepared->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
        ->and($prepared->fiscal_number)->toBeNull();

    // The retry converges — one document, one number.
    $config->update(['is_active' => true]);
    $result = $service->convertAndIssue($proforma);

    expect($result->invoice->fiscal_number)->not->toBeNull()
        ->and($result->invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and(Invoice::query()->whereNotNull('proforma_id')->where('serie', '!=', 0)->count())->toBe(1)
        ->and($proforma->fresh()->status)->toBe(InvoiceStatus::CONVERTED);
});

it('is idempotent on a fully-completed convertAndIssue: re-invocation is a no-op (§11)', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Line', 'quantity' => 100, 'base_price' => 2500]],
    ]);

    $first  = $service->convertAndIssue($proforma);
    $second = $service->convertAndIssue($proforma);

    expect($second->invoice->id)->toBe($first->invoice->id)
        ->and($second->invoice->fiscal_number)->toBe($first->invoice->fiscal_number);
});
