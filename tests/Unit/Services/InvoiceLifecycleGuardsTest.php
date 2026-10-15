<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ADR-016 §6.5 (PR-2) — immutability and deletion guards.
 *
 * The attribute-assignment + save() bypass (v7 D8) is closed at the
 * model-event layer, reading PERSISTED state; the grouped-payment collection
 * path is preserved explicitly; ISSUED rows can never be deleted (the §1.1.2
 * deletion hole) and PREPARED conversion-born rows are released, never
 * deleted. Bulk/query-builder and external SQL remain documented outside the
 * guarantee (v7 §10 limit, verbatim).
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);
});

function larabillIssuedFixture(InvoiceService $service): Invoice
{
    $draft = $service->createInvoice([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
    ]);

    return $service->issue($draft);
}

it('closes the save() bypass on fiscal content of an ISSUED row (D8)', function () {
    $issued = larabillIssuedFixture(app(InvoiceService::class));

    $issued->fiscal_number = 'FAC-2026-000099';

    expect(fn () => $issued->save())->toThrow(Exception::class, 'fiscally protected');
});

it('closes the save() bypass on EVERY fiscal content field, not only the number', function () {
    $service = app(InvoiceService::class);
    $issued  = larabillIssuedFixture($service);

    foreach ([
        'series_number' => 999,
        'fiscal_year'   => 1999,
        'total_amount'  => cents(9999),
        'is_roi_taxed'  => true,
        'proforma_id'   => '00000000-0000-7000-8000-000000000099',
    ] as $field => $value) {
        $row           = $issued->refresh();
        $row->{$field} = $value;

        expect(fn () => $row->save())->toThrow(Exception::class, 'fiscally protected');
    }
});

it('permits the grouped-payment collection path on an ISSUED row (§6.5 preservation)', function () {
    $service = app(InvoiceService::class);
    $issued  = larabillIssuedFixture($service);

    $issued->markAsPaidViaGroupedPayment(now());
    expect($issued->refresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($issued->paid_at)->not->toBeNull()
        ->and($issued->fiscal_number)->toBe($issued->fiscal_number); // untouched

    $issued->restoreStateViaGroupedPaymentReversal(InvoiceStatus::SENT, null);
    expect($issued->refresh()->status)->toBe(InvoiceStatus::SENT);
});

it('forbids deleting an ISSUED row — the §1.1.2 deletion hole closes', function () {
    $service = app(InvoiceService::class);
    $issued  = larabillIssuedFixture($service);

    expect(fn () => $issued->delete())
        ->toThrow(Exception::class, 'ISSUED');

    // The row is still there, untouched.
    expect(Invoice::find($issued->id)->fiscal_number)->toBe($issued->fiscal_number);
});

it('forbids deleting a PREPARED conversion-born row: release it instead (§6.5/§10)', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Line', 'quantity' => 100, 'base_price' => 2500]],
    ]);

    $prepared = $service->convertProformaToInvoice($proforma)->invoice;

    expect(fn () => $prepared->delete())
        ->toThrow(Exception::class, 'PREPARED');
});

it('keeps DRAFT and CANCELLED rows deletable (the operator cleanup surface, unchanged from v6)', function () {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice(['billable_user_id' => TestCase::USER_UUID_1, 'items' => [], 'status' => 'draft']);

    expect($draft->delete())->toBeTrue();

    $cancelled                          = $service->createInvoice(['billable_user_id' => TestCase::USER_UUID_1, 'items' => [], 'status' => 'draft']);
    $cancelled->invoice_document_status = InvoiceDocumentStatus::CANCELLED;
    $cancelled->status                  = InvoiceStatus::CANCELLED;
    $cancelled->document_cancelled_at   = now();
    $cancelled->save();

    expect($cancelled->delete())->toBeTrue();
});

it('guards the LINES of an ISSUED document: no creation, update or deletion (§6.5)', function () {
    $service = app(InvoiceService::class);
    $issued  = larabillIssuedFixture($service);

    // No new line on an ISSUED document.
    expect(fn () => $issued->items()->create(['description' => 'Late line', 'quantity' => cents(100), 'unit_price' => cents(100)]))
        ->toThrow(Exception::class, 'fiscally protected');

    // No mutation of an existing line.
    $item = $issued->items()->first();
    expect(fn () => $item->update(['description' => 'Tampered']))
        ->toThrow(Exception::class);

    // No deletion of a line.
    expect(fn () => $item->delete())->toThrow(Exception::class, 'fiscally protected');

    // The deliberate internal door of the v6 contract stays closed on the
    // attribute path: forceFill + save() hits the saving guard too.
    expect(fn () => $item->forceFill(['description' => 'Tampered'])->save())
        ->toThrow(Exception::class, 'fiscally protected');
});

it('guards the lines of an immutable PREPARED-era row the same way (persisted is_immutable)', function () {
    $service = app(InvoiceService::class);
    $issued  = larabillIssuedFixture($service);
    $issued->makeImmutable();

    $item = $issued->items()->first();
    expect(fn () => $item->update(['description' => 'Tampered']))
        ->toThrow(Exception::class);
});

it('rejects a forged coherent ISSUED shape written without issue() (gate round 2)', function (): void {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice([
        'status'           => 'draft', // the gate's scenario: a DRAFT row transitioning to ISSUED
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
    ]);

    // A perfectly coherent ISSUED shape, every field inside the allowed write
    // set — but written by hand. Shape is not authorization: only issue()
    // may perform the transition, because the correlative provenance (the
    // counter) lives only there.
    $draft->fiscal_number            = 'FAC-FORGE-000001';
    $draft->series_number            = 99;
    $draft->fiscal_year              = 2026;
    $draft->invoice_date             = now()->toDateString();
    $draft->issued_at                = now();
    $draft->invoice_document_status  = InvoiceDocumentStatus::ISSUED;
    $draft->fiscal_submission_status = FiscalSubmissionStatus::NOT_REQUIRED;

    expect(fn () => $draft->save())
        ->toThrow(Exception::class, 'issue() is the named writer');

    // Nothing persisted: the row is still an unnumbered DRAFT, and the
    // counter never moved.
    expect(Invoice::query()->whereKey($draft->getKey())->value('fiscal_number'))->toBeNull()
        ->and(Invoice::query()->whereKey($draft->getKey())->value('invoice_document_status'))->toBe(InvoiceDocumentStatus::DRAFT)
        ->and(InvoiceSeriesControl::count())->toBe(0);

    // The named writer still goes through and consumes the counter.
    $issued = $service->issue($draft->refresh());
    expect($issued->refresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($issued->refresh()->series_number)->toBe(1)
        ->and(InvoiceSeriesControl::count())->toBe(1);
});

it('rejects fiscal-content changes bundled into an issuance transition (gate finding 1)', function (): void {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice([
        'status'           => 'draft', // the gate's scenario: a DRAFT row transitioning to ISSUED
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
    ]);

    // The exact mutation the gate named: alter fiscal content AND supply a
    // coherent ISSUED shape in the same save. Only the issuance write set may
    // change on the transition — the amounts may not be smuggled in.
    $draft->total_amount                 = FixedDecimal::ofUnscaled(999999, 2);
    $draft->fiscal_number                = 'FAC-FAKE-000001';
    $draft->series_number                = 1;
    $draft->fiscal_year                  = 2026;
    $draft->invoice_date                 = now()->toDateString();
    $draft->issued_at                    = now();
    $draft->invoice_document_status      = InvoiceDocumentStatus::ISSUED;
    $draft->fiscal_submission_status     = FiscalSubmissionStatus::NOT_REQUIRED;

    $threw = null;
    try {
        $draft->save();
    } catch (Exception $exception) {
        $threw = $exception;
    }

    expect($threw)->not->toBeNull('Fiscal content must not be bundled into an issuance transition')
        ->and($threw?->getMessage())->toContain('issuance transition')
        ->and(Invoice::query()->whereKey($draft->getKey())->value('total_amount')->unscaledValue())->toBe(1000)
        ->and(Invoice::query()->whereKey($draft->getKey())->value('fiscal_number'))->toBeNull();

    // The named writer still goes through: issue() on the untouched draft.
    $issued = $service->issue($draft->refresh());
    expect($issued->refresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($issued->refresh()->total_amount->unscaledValue())->toBe(1000);
});

it('rejects deleting a CONVERTING proforma mid-conversion (gate finding 2)', function (): void {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
    ]);

    $service->convertProformaToInvoice($proforma);
    expect($proforma->refresh()->status)->toBe(InvoiceStatus::CONVERTING);

    $threw = null;
    try {
        $proforma->refresh()->delete();
    } catch (Exception $exception) {
        $threw = $exception;
    }

    expect($threw)->not->toBeNull('A CONVERTING proforma owns a linked PREPARED child')
        ->and($threw?->getMessage())->toContain('CONVERTING')
        ->and(Invoice::query()->whereKey($proforma->getKey())->exists())->toBeTrue();

    // The guard's own path releases it: cancel, then the proforma is DRAFT
    // and deletable again.
    $prepared = Invoice::query()->whereKey($proforma->refresh()->converted_invoice_id)->firstOrFail();
    $service->cancelPreparedInvoice($prepared, 'gate-test');
    $proforma->refresh()->delete();

    expect(Invoice::query()->whereKey($proforma->getKey())->exists())->toBeFalse();
});
