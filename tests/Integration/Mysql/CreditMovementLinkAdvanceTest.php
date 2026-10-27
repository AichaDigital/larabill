<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Exceptions\CreditMovementAlreadyLinkedException;
use AichaDigital\Larabill\Exceptions\InvalidAdvanceInvoiceException;
use AichaDigital\Larabill\Exceptions\InvalidCreditMovementException;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;

/*
 * AID-1778 spec §5.6 / R24 — the 9.0 link contract of
 * `CreditMovement::linkAdvanceInvoice()` ON A REAL ENGINE (MySQL 8.4 /
 * MariaDB 11.4). The §5.6 builder materializes the advance-side conflict
 * set in PHP after the invoice X lock and issues a conditional UPDATE with
 * NO subquery over `credit_movements` — these legs prove that SQL executes
 * identically on both engines: no ERROR 1093 (same-target-table refusal),
 * no plan dependence. The fork races that prove the CONCURRENCY live in
 * tests/Concurrency/LinkAdvanceInvoiceConcurrencyTest.php; this file pins
 * the deterministic refusals and the one-winner/one-loser shape per engine.
 *
 * Runs on the db-integration matrix (both drivers); skips cleanly when the
 * LARABILL_TEST_MYSQL_* env is absent (MysqlIntegrationTestCase setUp).
 */

/**
 * An unnumbered DRAFT advance — the only document the 9.0 link accepts.
 */
function aid1778MysqlDraftAdvance(string $owner, string $billable, int $totalCents = 5000): Invoice
{
    return Invoice::factory()->unissuedDraft()->create([
        'user_id'          => $owner,
        'billable_user_id' => $billable,
        'total_amount'     => cents($totalCents),
        'is_immutable'     => false,
        'paid_at'          => null,
        'due_date'         => null,
    ]);
}

/**
 * A live SURPLUS entry with its own FK-valid origin payment.
 */
function aid1778MysqlEntry(string $billable): CreditMovement
{
    return CreditMovement::factory()->create([
        'billable_user_id'   => $billable,
        'grouped_payment_id' => GroupedPayment::factory()->create(['billable_user_id' => $billable])->id,
    ]);
}

it('links an unnumbered DRAFT advance on the real engine — no ERROR 1093 (R24)', function () {
    $this->bootstrap();
    $owner    = $this->seedUser();
    $billable = $this->seedUser();

    $advance = aid1778MysqlDraftAdvance($owner, $billable);
    $entry   = aid1778MysqlEntry($billable);

    $entry->linkAdvanceInvoice($advance);

    expect($entry->fresh()->advance_invoice_id)->toBe($advance->id)
        ->and(CreditMovement::query()->whereNotNull('advance_invoice_id')->count())->toBe(1);
});

it('refuses the advance-side conflict from the PHP-materialized set and leaves the loser NULL on the real engine (R24/J1)', function () {
    $this->bootstrap();
    $owner    = $this->seedUser();
    $billable = $this->seedUser();

    $advance = aid1778MysqlDraftAdvance($owner, $billable);
    $winner  = aid1778MysqlEntry($billable);
    $loser   = aid1778MysqlEntry($billable);

    $winner->linkAdvanceInvoice($advance);

    // The conditional UPDATE carries no subquery over credit_movements: the
    // conflict ids were consumed in PHP, so the engine never refuses its own
    // target table — the refusal is the typed exception, not ERROR 1093.
    expect(fn () => $loser->linkAdvanceInvoice($advance))
        ->toThrow(CreditMovementAlreadyLinkedException::class, (string) $winner->id)
        ->and($loser->fresh()->advance_invoice_id)->toBeNull()
        ->and($winner->fresh()->advance_invoice_id)->toBe($advance->id);
});

it('refuses the entry-side second link (::forMovement) — the reverse direction of O2 (R24)', function () {
    $this->bootstrap();
    $owner    = $this->seedUser();
    $billable = $this->seedUser();

    $first  = aid1778MysqlDraftAdvance($owner, $billable);
    $second = aid1778MysqlDraftAdvance($owner, $billable);
    $entry  = aid1778MysqlEntry($billable);
    $twin   = CreditMovement::query()->findOrFail($entry->id); // loaded before the first link

    $entry->linkAdvanceInvoice($first);

    expect(fn () => $twin->linkAdvanceInvoice($second))
        ->toThrow(CreditMovementAlreadyLinkedException::class)
        ->and($entry->fresh()->advance_invoice_id)->toBe($first->id)
        ->and($twin->advance_invoice_id)->toBe($first->id);
});

it('refuses an ISSUED advance (::advanceNotDraft) and a proforma (::advanceNotAnInvoice) on the real engine (R24/L9/J7)', function () {
    $this->bootstrap();
    $owner    = $this->seedUser();
    $billable = $this->seedUser();

    $issued = Invoice::factory()->sent()->create([
        'user_id'          => $owner,
        'billable_user_id' => $billable,
        'total_amount'     => cents(5000),
        'is_immutable'     => false,
        'paid_at'          => null,
        'due_date'         => null,
    ]);
    $proforma = Invoice::factory()->proforma()->create([
        'user_id'          => $owner,
        'billable_user_id' => $billable,
        'total_amount'     => cents(5000),
        'is_immutable'     => false,
        'paid_at'          => null,
        'due_date'         => null,
    ]);
    $entryForIssued   = aid1778MysqlEntry($billable);
    $entryForProforma = aid1778MysqlEntry($billable);

    expect(fn () => $entryForIssued->linkAdvanceInvoice($issued))
        ->toThrow(InvalidAdvanceInvoiceException::class, (string) $issued->id)
        ->and(fn () => $entryForProforma->linkAdvanceInvoice($proforma))
        ->toThrow(InvalidAdvanceInvoiceException::class, (string) $proforma->id)
        // Zero writes on every refusal.
        ->and($entryForIssued->fresh()->advance_invoice_id)->toBeNull()
        ->and($entryForProforma->fresh()->advance_invoice_id)->toBeNull()
        ->and($proforma->serie)->toBe(InvoiceSerieType::PROFORMA);
});

it('refuses to link an exit on the real engine (entry-side guard unchanged, R24)', function () {
    $this->bootstrap();
    $owner    = $this->seedUser();
    $billable = $this->seedUser();

    $advance = aid1778MysqlDraftAdvance($owner, $billable);
    $entry   = aid1778MysqlEntry($billable);
    $exit    = CreditMovement::factory()->create([
        'billable_user_id'     => $billable,
        'grouped_payment_id'   => $entry->grouped_payment_id,
        'kind'                 => 2, // APPLIED exit
        'amount'               => cents(-1000),
        'consumes_movement_id' => $entry->id,
    ]);

    expect(fn () => $exit->linkAdvanceInvoice($advance))
        ->toThrow(InvalidCreditMovementException::class)
        ->and($exit->fresh()->advance_invoice_id)->toBeNull();
});
