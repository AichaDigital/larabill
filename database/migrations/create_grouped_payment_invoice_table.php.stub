<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grouped_payment_invoice', function (Blueprint $table) {
            $table->id(); // internal pivot PK (not domain-exposed)

            $table->foreignUuid('grouped_payment_id')->constrained('grouped_payments')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices');

            $table->bigInteger('applied_amount')->comment('Base-100 (FixedDecimalCast:2); v1 = invoice total. bigInteger for symmetry with grouped_payments.amount (money flows applied_amount → amount)');
            $table->unsignedTinyInteger('previous_status')->comment('InvoiceStatus before marking PAID (exact restore on reverse)');
            $table->dateTime('previous_paid_at')->nullable()->comment('Invoice paid_at before (exact restore)');

            // = invoice_id while posted, NULL when reversed. MySQL-safe one-active-payment backstop.
            $table->uuid('active_invoice_id')->nullable();

            $table->timestamps();

            $table->unique(['grouped_payment_id', 'invoice_id']); // one row per invoice per payment
            $table->unique('active_invoice_id');                  // one active payment per invoice
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grouped_payment_invoice');
    }
};
