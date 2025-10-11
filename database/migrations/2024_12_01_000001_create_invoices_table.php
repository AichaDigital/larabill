<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->enum('type', ['invoice', 'proforma'])->default('invoice');
            $table->enum('status', ['draft', 'sent', 'paid', 'overdue', 'cancelled'])->default('draft');
            $table->unsignedBigInteger('user_id');
            $table->text('user_tax_info_encrypted')->nullable();
            $table->json('customer_data')->nullable();
            $table->boolean('is_immutable')->default(false);
            $table->timestamp('immutable_at')->nullable();
            $table->integer('subtotal')->default(0)->comment('Base-100 integer (e.g., €12.34 => 1234)');
            $table->integer('tax_amount')->default(0)->comment('Base-100 integer (e.g., €12.34 => 1234)');
            $table->integer('total')->default(0)->comment('Base-100 integer (e.g., €12.34 => 1234)');
            $table->json('fiscal_data')->nullable();
            $table->json('vat_verification')->nullable();
            $table->boolean('is_roi_taxed')->default(false)->comment('Whether this invoice uses ROI taxation (reverse charge)');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('template_name')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['number']);
            $table->index(['user_id']);
            $table->index(['status']);
            $table->index(['type', 'status']);
            $table->index(['template_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
