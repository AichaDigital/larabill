<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use Illuminate\Support\Facades\DB;

describe('AID-30 — grouped payment schema on MySQL', function () {

    it('stores amount/applied_amount as integers and ids as char(36)', function () {
        $this->bootstrap();
        expect($this->getMysqlColumnType('grouped_payments', 'amount'))->toBe('int');
        expect($this->getMysqlColumnType('grouped_payment_invoice', 'applied_amount'))->toBe('int');
        expect($this->getMysqlColumnLength('grouped_payments', 'id'))->toBe(36);
        expect($this->getMysqlColumnLength('grouped_payments', 'billable_user_id'))->toBe(36);
        expect($this->getMysqlColumnLength('grouped_payment_invoice', 'active_invoice_id'))->toBe(36);
    });

    it('enforces one pivot row per (grouped_payment_id, invoice_id)', function () {
        $this->bootstrap();
        $userId  = $this->seedUser();
        $payment = GroupedPayment::factory()->create();
        $invoice = Invoice::factory()->sent()->create([
            'user_id'          => $userId,
            'billable_user_id' => $userId,
            'is_immutable'     => false,
            'paid_at'          => null,
        ]);

        $insert = fn () => DB::table('grouped_payment_invoice')->insert(pivotRow($payment->id, $invoice->id, null));
        $insert();
        expect($insert)->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('allows many reversed (null) rows but one active payment per invoice', function () {
        $this->bootstrap();
        $userId = $this->seedUser();
        $invA = Invoice::factory()->sent()->create(['user_id' => $userId, 'billable_user_id' => $userId, 'is_immutable' => false, 'paid_at' => null]);
        $invB = Invoice::factory()->sent()->create(['user_id' => $userId, 'billable_user_id' => $userId, 'is_immutable' => false, 'paid_at' => null]);
        $invC = Invoice::factory()->sent()->create(['user_id' => $userId, 'billable_user_id' => $userId, 'is_immutable' => false, 'paid_at' => null]);
        $p1 = GroupedPayment::factory()->create();
        $p2 = GroupedPayment::factory()->create();

        // Two reversed rows (active_invoice_id NULL) for the same invoice → allowed.
        DB::table('grouped_payment_invoice')->insert(pivotRow($p1->id, $invA->id, null));
        DB::table('grouped_payment_invoice')->insert(pivotRow($p2->id, $invA->id, null));

        // Two ACTIVE rows carrying the same active_invoice_id (invB) → second rejected by unique(active_invoice_id).
        DB::table('grouped_payment_invoice')->insert(pivotRow($p1->id, $invB->id, $invB->id));
        expect(fn () => DB::table('grouped_payment_invoice')->insert(pivotRow($p2->id, $invC->id, $invB->id)))
            ->toThrow(\Illuminate\Database\QueryException::class);
    });

});

function pivotRow(string $paymentId, string $invoiceId, ?string $activeInvoiceId): array
{
    return [
        'grouped_payment_id' => $paymentId,
        'invoice_id'         => $invoiceId,
        'applied_amount'     => 1000,
        'previous_status'    => 1,
        'active_invoice_id'  => $activeInvoiceId,
        'created_at'         => now(),
        'updated_at'         => now(),
    ];
}
