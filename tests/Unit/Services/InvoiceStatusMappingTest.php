<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\InvalidInvoiceStatusException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-838 + ADR-016 §7.2 (PR-2, rounds 9/10 V14/V15) — the documented
 * `status` input is validated, not guessed, and its MEANING is re-scoped.
 *
 * AID-838 (v6.12.0) fixed the mapping: accepted names DERIVED from the enum,
 * both string and integer forms, unknown values refused loud. PR-2 re-scopes
 * WHAT the input means (§7.2): the lifecycle outcomes PENDING, CONVERTED,
 * CANCELLED and CONVERTING are never birth inputs — they are produced by the
 * document lifecycle (`issue()`, the conversion, the cancellation paths).
 * With the four rejections the enum is exhausted at birth:
 *
 * - `DRAFT` accepted → an UNNUMBERED fiscal DRAFT (§7.1 phase 2 skipped).
 * - `SENT`/`PAID`/`OVERDUE` accepted → issued, then the collection stamp.
 * - the four lifecycle outcomes rejected — on the NORMALIZED state, so both
 *   the string case name and the integer value land in the same typed
 *   refusal (V15: the mapper normalizes both forms, `:683-696` of the v6
 *   tree).
 *
 * The accepted names are still DERIVED from the enum, never listed by hand:
 * the derivation is what AID-838 fixed and it survives the re-scope.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();
    $this->service  = app(InvoiceService::class);
});

/**
 * The birth-eligible cases, DERIVED from the enum — the exhaustive list is
 * the enum minus the four lifecycle outcomes, so a case added to
 * InvoiceStatus can never silently become accepted (or rejected) again
 * without this dataset answering the question.
 *
 * @return array<string, InvoiceStatus>
 */
function larabillBirthEligibleStatuses(): array
{
    $lifecycleOutcomes = [
        InvoiceStatus::PENDING,
        InvoiceStatus::CONVERTED,
        InvoiceStatus::CANCELLED,
        InvoiceStatus::CONVERTING,
    ];

    return collect(InvoiceStatus::cases())
        ->reject(fn (InvoiceStatus $case) => in_array($case, $lifecycleOutcomes, true))
        ->mapWithKeys(fn (InvoiceStatus $case) => [$case->name => $case])
        ->all();
}

it('accepts every birth-eligible enum case by name, with the §7.2 outcome each one documents', function (InvoiceStatus $case) {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => mb_strtolower($case->name),
        'items'            => [],
    ]);

    if ($case === InvoiceStatus::DRAFT) {
        // §7.2: explicit draft → phase 2 does not run — UNNUMBERED DRAFT.
        expect($invoice->status)->toBe(InvoiceStatus::DRAFT)
            ->and($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::DRAFT)
            ->and($invoice->fiscal_number)->toBeNull()
            ->and($invoice->series_number)->toBeNull()
            ->and($invoice->fiscal_year)->toBeNull()
            ->and($invoice->invoice_date)->toBeNull()
            ->and($invoice->issued_at)->toBeNull()
            ->and($invoice->fiscal_submission_status)->toBeNull();

        return;
    }

    // §7.2: collection statuses are the post-issuance collection stamp —
    // the document is ISSUED and numbered exactly once.
    expect($invoice->status)->toBe($case)
        ->and($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($invoice->fiscal_number)->not->toBeNull()
        ->and($invoice->series_number)->not->toBeNull()
        ->and($invoice->fiscal_year)->not->toBeNull()
        ->and($invoice->invoice_date)->not->toBeNull()
        ->and($invoice->issued_at)->not->toBeNull()
        ->and($invoice->fiscal_submission_status)->not->toBeNull();
})->with(fn () => larabillBirthEligibleStatuses());

it('accepts every birth-eligible enum case by its integer value', function (InvoiceStatus $case) {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => $case->value,
        'items'            => [],
    ]);

    // Same §7.2 outcomes through the integer form of the normalized state.
    expect($invoice->status)->toBe($case)
        ->and($invoice->invoice_document_status)->toBe(
            $case === InvoiceStatus::DRAFT ? InvoiceDocumentStatus::DRAFT : InvoiceDocumentStatus::ISSUED,
        );
})->with(fn () => larabillBirthEligibleStatuses());

it('is case insensitive, as it always was', function () {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => 'dRaFt',
        'items'            => [],
    ]);

    expect($invoice->status)->toBe(InvoiceStatus::DRAFT)
        ->and($invoice->fiscal_number)->toBeNull();
});

it('refuses an unknown status string instead of quietly issuing a draft', function () {
    expect(fn () => $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => 'porcessed',   // the typo a consumer actually makes
        'items'            => [],
    ]))->toThrow(InvalidInvoiceStatusException::class);
});

it('refuses an integer that is not a case, instead of leaking a raw ValueError', function () {
    expect(fn () => $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => 99,
        'items'            => [],
    ]))->toThrow(InvalidInvoiceStatusException::class);
});

/*
 * §7.2 (V14/V15): the four lifecycle outcomes are never birth inputs —
 * in BOTH the string and the integer form, because the mapper normalizes
 * both before the rejection runs.
 */
it('rejects every lifecycle outcome as a birth input, in both the string and the integer form', function (InvoiceStatus $case) {
    expect(fn () => $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => mb_strtolower($case->name),
        'items'            => [],
    ]))->toThrow(InvalidInvoiceStatusException::class, $case->name)
        ->and(fn () => $this->service->createInvoice([
            'billable_user_id' => $this->customer->id,
            'status'           => $case->value,
            'items'            => [],
        ]))->toThrow(InvalidInvoiceStatusException::class, $case->name);
})->with(fn () => collect([
    InvoiceStatus::PENDING,
    InvoiceStatus::CONVERTED,
    InvoiceStatus::CANCELLED,
    InvoiceStatus::CONVERTING,
])->mapWithKeys(fn (InvoiceStatus $case) => [$case->name => $case])->all());

it('names the offending lifecycle outcome and the remedy in the message', function () {
    try {
        $this->service->createInvoice([
            'billable_user_id' => $this->customer->id,
            'status'           => 'cancelled',
            'items'            => [],
        ]);
        expect()->fail('expected InvalidInvoiceStatusException');
    } catch (InvalidInvoiceStatusException $e) {
        expect($e->getMessage())->toContain('CANCELLED')
            ->and($e->getMessage())->toContain('lifecycle outcome')
            ->and($e->getMessage())->toContain('§7.2');
    }
});

it('keeps result compatibility: an absent status births an ISSUED invoice, and only an explicit draft skips issuance (ADR-016 §7.1/§7.2)', function () {
    // §7.1 result compatibility: the bare call reaches the issuance phase.
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [],
    ]);

    expect($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($invoice->fiscal_number)->not->toBeNull()
        ->and($invoice->status)->toBe(InvoiceStatus::DRAFT);

    // §7.2: only an EXPLICIT draft skips the issuance phase.
    $explicitDraft = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [],
        'status'           => 'draft',
    ]);

    expect($explicitDraft->invoice_document_status)->toBe(InvoiceDocumentStatus::DRAFT)
        ->and($explicitDraft->fiscal_number)->toBeNull();
});
