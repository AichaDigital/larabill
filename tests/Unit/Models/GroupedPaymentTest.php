<?php
// tests/Unit/Models/GroupedPaymentTest.php
use Illuminate\Support\Facades\Schema;

it('creates grouped_payments with the expected columns', function () {
    expect(Schema::hasTable('grouped_payments'))->toBeTrue();
    expect(Schema::hasColumns('grouped_payments', [
        'id', 'billable_user_id', 'amount', 'currency', 'paid_at', 'reference',
        'idempotency_key', 'status', 'reversed_at', 'reversed_by', 'reverse_reason', 'notes',
    ]))->toBeTrue();
});

it('creates grouped_payment_invoice pivot with the expected columns', function () {
    expect(Schema::hasTable('grouped_payment_invoice'))->toBeTrue();
    expect(Schema::hasColumns('grouped_payment_invoice', [
        'id', 'grouped_payment_id', 'invoice_id', 'applied_amount',
        'previous_status', 'previous_paid_at', 'active_invoice_id',
    ]))->toBeTrue();
});
