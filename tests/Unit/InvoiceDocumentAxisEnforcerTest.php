<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\InvoiceDocumentCoherenceException;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * ADR-016 §8.2 (PR-1, AID-1627) — application enforcer for the document-axis
 * coherence CHECK.
 *
 * The MySQL CHECK and this enforcer evaluate the SAME clause set (the one
 * amended by V1/V9/V10/V11/V12/V13/V17/S4-r/S5 of the spec's §16 gate). The
 * truth table in §8.2 ("intended row shapes") is the contract these tests
 * pin: the enforcer is implemented as "all clauses TRUE", so it must accept
 * EXACTLY the intended shapes and reject EVERY malformed shape listed there.
 *
 * SQLite cannot ALTER-add a CHECK, so this enforcer is the ONLY enforcement
 * on SQLite — these tests are its proof (the engine split is proven on MySQL
 * by tests/Integration/UpgradePath/InvoiceDocumentAxisUpgradeTest.php).
 */

/**
 * A complete, coherent FISCAL ISSUED row — the baseline every mutation derives from.
 *
 * @return array<string, mixed>
 */
function axisIssuedFiscalRow(): array
{
    return [
        'serie'                    => InvoiceSerieType::INVOICE->value,
        'invoice_document_status'  => InvoiceDocumentStatus::ISSUED->value,
        'fiscal_submission_status' => FiscalSubmissionStatus::NOT_REQUIRED->value,
        'fiscal_number'            => 'FAC-2026-000047',
        'series_number'            => 47,
        'fiscal_year'              => 2026,
        'invoice_date'             => '2026-10-13',
        'issued_at'                => '2026-10-13 10:00:00',
        'document_cancelled_at'    => null,
        'document_cancelled_by'    => null,
    ];
}

/**
 * A complete, coherent PROFORMA row — five fields NOT NULL, document axis NULL.
 *
 * @return array<string, mixed>
 */
function axisProformaRow(): array
{
    return [
        'serie'                    => InvoiceSerieType::PROFORMA->value,
        'invoice_document_status'  => null,
        'fiscal_submission_status' => null,
        'fiscal_number'            => 'PRO-2026-000012',
        'series_number'            => 12,
        'fiscal_year'              => 2026,
        'invoice_date'             => '2026-10-13',
        'issued_at'                => '2026-10-13 10:00:00',
        'document_cancelled_at'    => null,
        'document_cancelled_by'    => null,
    ];
}

/**
 * The five number/issuance fields bound by the §8.2 clause set.
 *
 * @return array<int, string>
 */
function axisFiveFields(): array
{
    return ['fiscal_number', 'series_number', 'fiscal_year', 'invoice_date', 'issued_at'];
}

/**
 * @param  array<string, mixed>  $row
 */
function axisAccepts(array $row): void
{
    $threw = null;

    try {
        Invoice::assertDocumentAxisCoherentRow($row);
    } catch (InvoiceDocumentCoherenceException $exception) {
        $threw = $exception;
    }

    expect($threw)->toBeNull('The row shape must satisfy the whole §8.2 clause set');
}

/**
 * @param  array<string, mixed>  $row
 * @return array<int, string> The violated §8.2 clause names
 */
function axisRejects(array $row): array
{
    try {
        Invoice::assertDocumentAxisCoherentRow($row);
    } catch (InvoiceDocumentCoherenceException $exception) {
        expect($exception->violatedClauses())->not->toBeEmpty();

        return $exception->violatedClauses();
    }

    fail('InvoiceDocumentCoherenceException was not thrown');
}

it('accepts every intended row shape of the §8.2 table', function (): void {
    // 1. Proforma (serie=0, doc NULL, five NOT NULL).
    axisAccepts(axisProformaRow());

    // 2. Fiscal DRAFT (five NULL).
    $draft                            = axisIssuedFiscalRow();
    $draft['invoice_document_status'] = InvoiceDocumentStatus::DRAFT->value;
    foreach (axisFiveFields() as $field) {
        $draft[$field] = null;
    }
    $draft['fiscal_submission_status'] = null;
    axisAccepts($draft);

    // 3. Fiscal PREPARED (five NULL).
    $prepared                            = $draft;
    $prepared['invoice_document_status'] = InvoiceDocumentStatus::PREPARED->value;
    axisAccepts($prepared);

    // 4. Fiscal ISSUED (five NOT NULL, submission set) — the baseline itself.
    axisAccepts(axisIssuedFiscalRow());

    // 5. CANCELLED conversion-born (five NULL, instant + actor).
    $cancelledConversion                            = $draft;
    $cancelledConversion['invoice_document_status'] = InvoiceDocumentStatus::CANCELLED->value;
    $cancelledConversion['document_cancelled_at']   = '2026-10-13 12:00:00';
    $cancelledConversion['document_cancelled_by']   = 'consumer-cancel-path';
    axisAccepts($cancelledConversion);

    // 6. CANCELLED direct (five NULL, instant, actor NULL by design — S4-r).
    $cancelledDirect                          = $cancelledConversion;
    $cancelledDirect['document_cancelled_by'] = null;
    axisAccepts($cancelledDirect);
});

it('accepts every enum case of both axes as a domain value (no hand-listed drift)', function (): void {
    // Document axis: each case must be in-domain with a coherent fiscal shape.
    foreach (InvoiceDocumentStatus::cases() as $case) {
        $row                            = axisIssuedFiscalRow();
        $row['invoice_document_status'] = $case->value;
        $numbered                       = $case === InvoiceDocumentStatus::ISSUED;
        foreach (axisFiveFields() as $field) {
            $row[$field] = $numbered ? axisIssuedFiscalRow()[$field] : null;
        }
        $row['fiscal_submission_status'] = $numbered ? FiscalSubmissionStatus::NOT_REQUIRED->value : null;
        // C5: a CANCELLED row always carries its cancellation instant.
        $row['document_cancelled_at'] = $case === InvoiceDocumentStatus::CANCELLED
            ? '2026-10-13 12:00:00'
            : null;
        axisAccepts($row);
    }

    // Submission axis: every case is in-domain on an ISSUED fiscal row.
    foreach (FiscalSubmissionStatus::cases() as $case) {
        $row                             = axisIssuedFiscalRow();
        $row['fiscal_submission_status'] = $case->value;
        axisAccepts($row);
    }

    // The enum values themselves are the integers the §8.2 SQL CHECK pins.
    expect(InvoiceDocumentStatus::checkDomainValues())->toBe([0, 1, 2, 3])
        ->and(FiscalSubmissionStatus::checkDomainValues())->toBe([0, 1, 2, 3, 4, 5]);
});

it('rejects a proforma carrying a document status (§8.2 C2 axis biconditional)', function (): void {
    $row                             = axisProformaRow();
    $row['invoice_document_status']  = InvoiceDocumentStatus::ISSUED->value;
    $row['fiscal_submission_status'] = FiscalSubmissionStatus::NOT_REQUIRED->value;

    expect(axisRejects($row))->toContain('C2');
});

it('rejects a fiscal row without a document status (§8.2 C2 — the malformed legacy shape)', function (): void {
    $row                             = axisIssuedFiscalRow();
    $row['invoice_document_status']  = null;
    $row['fiscal_submission_status'] = null;

    expect(axisRejects($row))->toContain('C2');
});

it('rejects mixed field nullity on any fiscal document status (§8.2 C4)', function (): void {
    // ISSUED with one of the five fields NULL.
    $missingField              = axisIssuedFiscalRow();
    $missingField['issued_at'] = null;
    expect(axisRejects($missingField))->toContain('C4');

    // DRAFT with one of the five fields populated.
    $leakingDraft                            = axisIssuedFiscalRow();
    $leakingDraft['invoice_document_status'] = InvoiceDocumentStatus::DRAFT->value;
    foreach (['series_number', 'fiscal_year', 'invoice_date', 'issued_at'] as $field) {
        $leakingDraft[$field] = null;
    }
    $leakingDraft['fiscal_submission_status'] = null;
    expect(axisRejects($leakingDraft))->toContain('C4');
});

it('rejects out-of-domain document, submission and serie values (§8.2 C1/V9/V11)', function (): void {
    // Out-of-domain document status (B4: the mixed-nullity out-of-enum row is
    // the hole the domain clause closes — and it is rejected here too).
    $outOfDomainDoc                            = axisIssuedFiscalRow();
    $outOfDomainDoc['invoice_document_status'] = 99;
    expect(axisRejects($outOfDomainDoc))->toContain('C1');

    $mixedNullityOutOfDomain              = $outOfDomainDoc;
    $mixedNullityOutOfDomain['issued_at'] = null;
    expect(axisRejects($mixedNullityOutOfDomain))->toContain('C1');

    // Out-of-domain submission status (V9).
    $outOfDomainSubmission                             = axisIssuedFiscalRow();
    $outOfDomainSubmission['fiscal_submission_status'] = 255;
    expect(axisRejects($outOfDomainSubmission))->toContain('V9');

    // Out-of-domain serie (V11): a fiscal DRAFT shape with serie 255.
    $outOfDomainSerie                            = axisIssuedFiscalRow();
    $outOfDomainSerie['serie']                   = 255;
    $outOfDomainSerie['invoice_document_status'] = InvoiceDocumentStatus::DRAFT->value;
    foreach (axisFiveFields() as $field) {
        $outOfDomainSerie[$field] = null;
    }
    $outOfDomainSerie['fiscal_submission_status'] = null;
    expect(axisRejects($outOfDomainSerie))->toContain('V11');
});

it('rejects an actor without a cancellation instant (§8.2 S4-r one-directional pair)', function (): void {
    $row                            = axisIssuedFiscalRow();
    $row['invoice_document_status'] = InvoiceDocumentStatus::DRAFT->value;
    foreach (axisFiveFields() as $field) {
        $row[$field] = null;
    }
    $row['fiscal_submission_status'] = null;
    $row['document_cancelled_at']    = null;
    $row['document_cancelled_by']    = 'support-agent';

    expect(axisRejects($row))->toContain('S4-r');
});

it('rejects a cancellation instant on a non-CANCELLED row (§8.2 V13 inverse)', function (): void {
    // Even an ISSUED row cannot carry cancellation metadata (V13).
    $row                          = axisIssuedFiscalRow();
    $row['document_cancelled_at'] = '2026-10-13 12:00:00';
    expect(axisRejects($row))->toContain('V13');

    // ...and a DRAFT row carrying both instant and actor (a "cancelled that
    // never happened" shape with full metadata) is rejected as well.
    $draftWithMetadata                            = $row;
    $draftWithMetadata['invoice_document_status'] = InvoiceDocumentStatus::DRAFT->value;
    foreach (axisFiveFields() as $field) {
        $draftWithMetadata[$field] = null;
    }
    $draftWithMetadata['fiscal_submission_status'] = null;
    $draftWithMetadata['document_cancelled_by']    = 'someone';
    expect(axisRejects($draftWithMetadata))->toContain('V13');
});

it('rejects submission or cancellation fields on a doc-NULL row (§8.2 S5 proforma hygiene)', function (): void {
    // Proforma with a submission value — the S5 finding: with a plain
    // ISSUED-biconditional this evaluated UNKNOWN under three-valued logic.
    $submissionOnProforma                             = axisProformaRow();
    $submissionOnProforma['fiscal_submission_status'] = FiscalSubmissionStatus::PENDING->value;
    expect(axisRejects($submissionOnProforma))->toContain('S5');

    // Proforma with cancellation metadata.
    $cancelledProforma                          = axisProformaRow();
    $cancelledProforma['document_cancelled_at'] = '2026-10-13 12:00:00';
    expect(axisRejects($cancelledProforma))->toContain('S5');
});

it('enforces the coherence set on save, not only on the static evaluator', function (): void {
    // A coherent fiscal row saves (factory default is the stamped transitional shape).
    $invoice = Invoice::factory()->create([
        'user_id'          => TestCase::USER_UUID_1,
        'billable_user_id' => TestCase::USER_UUID_1,
    ]);
    $invoice->status = InvoiceStatus::PAID;
    $invoice->save();
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::PAID);

    // A malformed shape cannot be born through Eloquent (fiscal + doc NULL = C2).
    $malformed = Invoice::factory()->make([
        'user_id'                  => TestCase::USER_UUID_1,
        'billable_user_id'         => TestCase::USER_UUID_1,
        'invoice_document_status'  => null,
        'fiscal_submission_status' => null,
    ]);
    $threw = null;
    try {
        $malformed->save();
    } catch (InvoiceDocumentCoherenceException $exception) {
        $threw = $exception;
    }
    expect($threw)->not->toBeNull('The malformed fiscal birth must be rejected')
        ->and($threw?->violatedClauses() ?? [])->toContain('C2')
        ->and(Invoice::query()->whereKey($malformed->getKey())->exists())->toBeFalse();

    // An incoherent mutation of an existing row cannot persist (mixed nullity, C4).
    $broken = Invoice::factory()->create([
        'user_id'          => TestCase::USER_UUID_1,
        'billable_user_id' => TestCase::USER_UUID_1,
    ]);
    $broken->fiscal_number = null;
    $mutationThrew         = null;
    try {
        $broken->save();
    } catch (InvoiceDocumentCoherenceException $exception) {
        $mutationThrew = $exception;
    }
    expect($mutationThrew)->not->toBeNull('Nulling fiscal_number on an ISSUED row must be rejected')
        ->and($mutationThrew?->violatedClauses() ?? [])->toContain('C4')
        ->and(Invoice::query()->whereKey($broken->getKey())->value('fiscal_number'))->not->toBeNull(
            'The rejected mutation must not have persisted'
        );
});

it('lets raw database-shaped rows pass through the evaluator exactly like the CHECK would', function (): void {
    // Raw rows read from the DB carry string dates and int enums — the same
    // normalization the MySQL CHECK performs over stored values.
    Invoice::factory()->create([
        'user_id'          => TestCase::USER_UUID_1,
        'billable_user_id' => TestCase::USER_UUID_1,
    ]);
    $raw = DB::table('invoices')
        ->where('invoice_document_status', InvoiceDocumentStatus::ISSUED->value)
        ->firstOrFail();

    axisAccepts((array) $raw);
});

it('rejects a raw incoherent row on its next guarded save (bulk writes stay outside the guarantee)', function (): void {
    // A raw INSERT bypasses the enforcer BY DESIGN (the bulk/query-builder
    // limit documented in ADR-016 §6.5 / v7 §10). But the enforcer reads the
    // PERSISTED row shape on every save — so the row cannot be touched again
    // through Eloquent until it is coherent.
    $id = (string) Str::uuid();
    DB::table('invoices')->insert([
        'id'            => $id,
        'fiscal_number' => 'FAC-2026-000999',
        'prefix'        => 'FAC',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'series_number' => 999,
        'fiscal_year'   => 2026,
        'invoice_date'  => '2026-10-13',
        'issued_at'     => '2026-10-13 10:00:00',
        'status'        => 0,
        'user_id'       => TestCase::USER_UUID_1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $row        = Invoice::query()->whereKey($id)->firstOrFail();
    $row->notes = 'touched';
    $threw      = null;
    try {
        $row->save();
    } catch (InvoiceDocumentCoherenceException $exception) {
        $threw = $exception;
    }

    expect($threw)->not->toBeNull('Saving over a persisted incoherent row must be rejected')
        ->and($threw?->violatedClauses() ?? [])->toContain('C2');
});
