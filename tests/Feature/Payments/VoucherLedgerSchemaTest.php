<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use Illuminate\Support\Facades\Schema;

/*
 * AID-1825 — the ledger pieces of the voucher sale (minimal scope §1, §1a):
 * the voucher backing of an entry (`sale_invoice_id`), the consumption exit
 * kind, the keyed operation row and the sale claim. Schema and vocabulary
 * only; behaviour is pinned by VoucherLedgerTest.
 */

it('declares CONSUMED as an exit kind, leaving the 9.1 kinds untouched', function () {
    expect(CreditMovementKind::CONSUMED->isExit())->toBeTrue()
        ->and(CreditMovementKind::CONSUMED->isEntry())->toBeFalse()
        ->and(CreditMovementKind::CONSUMED->value)->toBe(4)
        ->and(CreditMovementKind::SURPLUS->value)->toBe(0)
        ->and(CreditMovementKind::TOP_UP->value)->toBe(1)
        ->and(CreditMovementKind::APPLIED->value)->toBe(2)
        ->and(CreditMovementKind::REFUNDED->value)->toBe(3);
});

it('adds the voucher backing and the consumption columns to credit_movements', function () {
    expect(Schema::hasColumns('credit_movements', ['sale_invoice_id', 'credit_operation_id', 'service_ref', 'service_description']))->toBeTrue();
});

it('creates the keyed credit_operations table', function () {
    expect(Schema::hasTable('credit_operations'))->toBeTrue()
        ->and(Schema::hasColumns('credit_operations', ['id', 'operation_key', 'kind', 'billable_user_id', 'fingerprint', 'recorded_at']))->toBeTrue();
});

it('creates the voucher_sale_claims table and the invoice link to it', function () {
    expect(Schema::hasTable('voucher_sale_claims'))->toBeTrue()
        ->and(Schema::hasColumns('voucher_sale_claims', [
            'id', 'proforma_id', 'grouped_payment_id', 'operation_key', 'stage',
            'expected_amount', 'expected_currency', 'expected_billable_user_id',
            'issued_by_sale', 'hook_ran', 'issuing_at', 'backed_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('invoices', 'sale_claim_id'))->toBeTrue();
});
