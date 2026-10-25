<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use Illuminate\Support\Facades\DB;

/*
 * AID-30 schema of the grouped-payment pivot, relaxed by AID-962 (migration
 * 053): the two UNIQUEs — one row per (payment, invoice) and one active
 * payment per invoice — are retired, because partial payments let two live
 * payments cover one invoice and a payment be re-allocated to an invoice
 * after an allocation reversal. The per-invoice guarantee moved from the
 * UNIQUE to the invoice row lock (spec §5.4); these tests pin that the
 * schema no longer refuses either shape.
 */
describe('AID-30 — grouped payment schema on MySQL', function () {

    // Scoped pivot-row builder (AID-272): previously a loose global
    // `function pivotRow()` at file scope, which risked colliding with other
    // Pest helpers across the suite. Kept local to this describe and captured
    // via `use` in the tests that need it.
    $pivotRow = function (string $paymentId, string $invoiceId, ?string $activeInvoiceId): array {
        return [
            'grouped_payment_id' => $paymentId,
            'invoice_id'         => $invoiceId,
            'applied_amount'     => 1000,
            'previous_status'    => 1,
            'active_invoice_id'  => $activeInvoiceId,
            'created_at'         => now(),
            'updated_at'         => now(),
        ];
    };

    it('stores amount/applied_amount as big integers and ids as UUID', function () {
        $this->bootstrap();

        // Money stays base-100 integer — that IS larabill's contract (ADR-009).
        expect($this->getMysqlColumnType('grouped_payments', 'amount'))->toBe('bigint');
        expect($this->getMysqlColumnType('grouped_payment_invoice', 'applied_amount'))->toBe('bigint');

        // Identity is asserted semantically (AID-708): the physical shape of a
        // UUID column belongs to Laravel's grammar for the configured driver,
        // not to this package. Pinning char(36) here failed a valid schema.
        $this->assertUuidCompatibleColumn('grouped_payments', 'id');
        $this->assertUuidCompatibleColumn('grouped_payments', 'billable_user_id');
        $this->assertUuidCompatibleColumn('grouped_payment_invoice', 'active_invoice_id');
    });

    it('lets two ACTIVE rows with the same active_invoice_id from two payments coexist', function () use ($pivotRow) {
        $this->bootstrap();
        $userId  = $this->seedUser();
        $invoice = Invoice::factory()->sent()->create(['user_id' => $userId, 'billable_user_id' => $userId, 'is_immutable' => false, 'paid_at' => null]);
        $p1      = GroupedPayment::factory()->create();
        $p2      = GroupedPayment::factory()->create();

        // Two partial payments, both live, covering the same invoice.
        DB::table('grouped_payment_invoice')->insert($pivotRow($p1->id, $invoice->id, $invoice->id));
        DB::table('grouped_payment_invoice')->insert($pivotRow($p2->id, $invoice->id, $invoice->id));

        expect(DB::table('grouped_payment_invoice')->where('active_invoice_id', $invoice->id)->count())->toBe(2);
    });

    it('lets the same (payment, invoice) pair appear twice', function () use ($pivotRow) {
        $this->bootstrap();
        $userId  = $this->seedUser();
        $invoice = Invoice::factory()->sent()->create(['user_id' => $userId, 'billable_user_id' => $userId, 'is_immutable' => false, 'paid_at' => null]);
        $payment = GroupedPayment::factory()->create();

        // A reversed allocation (NULL marker) and the re-allocation of the same pair.
        DB::table('grouped_payment_invoice')->insert($pivotRow($payment->id, $invoice->id, null));
        DB::table('grouped_payment_invoice')->insert($pivotRow($payment->id, $invoice->id, $invoice->id));

        expect(DB::table('grouped_payment_invoice')
            ->where('grouped_payment_id', $payment->id)
            ->where('invoice_id', $invoice->id)
            ->count())->toBe(2);
    });

});
