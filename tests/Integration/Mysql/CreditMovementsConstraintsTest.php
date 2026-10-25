<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * AID-962 (spec §3.3) — the `credit_movements` contract on a real engine:
 * money as a signed base-100 bigint bounded by the named AEAT-domain CHECK
 * (MySQL/MariaDB only; SQLite cannot ALTER-add it), the payment FK RESTRICT
 * (the ledger never loses its origin), and the advance-invoice FK SET NULL
 * (the consumer's documentary reference may disappear, the money may not).
 * Rows go in through the query builder on purpose: the CHECK exists for a
 * consumer that inserts bypassing the cast.
 */
function aid962MovementRow(string $billable, string $paymentId, int $amount, ?string $advanceInvoiceId = null): array
{
    return [
        'id'                 => (string) Str::orderedUuid(),
        'billable_user_id'   => $billable,
        'currency'           => 'EUR',
        'kind'               => 0, // SURPLUS entry
        'amount'             => $amount,
        'grouped_payment_id' => $paymentId,
        'advance_invoice_id' => $advanceInvoiceId,
        'occurred_at'        => now(),
        'created_at'         => now(),
        'updated_at'         => now(),
    ];
}

describe('AID-962 — credit_movements schema on MySQL', function () {

    it('stores amount as a big integer', function () {
        $this->bootstrap();

        expect($this->getMysqlColumnType('credit_movements', 'amount'))->toBe('bigint');
    });

    it('rejects an amount outside the AEAT money domain (CHECK)', function () {
        $this->bootstrap();
        $billable = $this->seedUser();
        $payment  = GroupedPayment::factory()->create(['billable_user_id' => $billable]);

        // 99 999 999 999 999 is the bound; one cent above it is refused.
        DB::table('credit_movements')->insert(aid962MovementRow($billable, $payment->id, 99999999999999));

        expect(fn () => DB::table('credit_movements')->insert(aid962MovementRow($billable, $payment->id, 100000000000000)))
            ->toThrow(QueryException::class, 'larabill_money_domain_credit_movements_amount');
        expect(DB::table('credit_movements')->count())->toBe(1);
    });

    it('refuses to delete a payment a movement references (RESTRICT)', function () {
        $this->bootstrap();
        $billable = $this->seedUser();
        $payment  = GroupedPayment::factory()->create(['billable_user_id' => $billable]);
        DB::table('credit_movements')->insert(aid962MovementRow($billable, $payment->id, 1000));

        expect(fn () => DB::table('grouped_payments')->where('id', $payment->id)->delete())
            ->toThrow(QueryException::class, 'foreign key constraint fails');
        expect(DB::table('grouped_payments')->where('id', $payment->id)->exists())->toBeTrue();
    });

    it('nulls advance_invoice_id when the advance invoice is deleted (SET NULL)', function () {
        $this->bootstrap();
        $billable = $this->seedUser();
        $payment  = GroupedPayment::factory()->create(['billable_user_id' => $billable]);
        $advance  = Invoice::factory()->sent()->create(['user_id' => $billable, 'billable_user_id' => $billable, 'is_immutable' => false, 'paid_at' => null]);
        $row      = aid962MovementRow($billable, $payment->id, 1000, $advance->id);
        DB::table('credit_movements')->insert($row);

        DB::table('invoices')->where('id', $advance->id)->delete();

        $movement = DB::table('credit_movements')->where('id', $row['id'])->first();
        expect($movement)->not->toBeNull()
            ->and($movement->advance_invoice_id)->toBeNull();
    });

});
