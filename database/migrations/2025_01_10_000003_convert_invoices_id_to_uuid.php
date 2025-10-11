<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * IMPORTANT: This migration changes invoices.id from bigInteger to UUID binary(16).
     * It will DROP and recreate the invoices table, so backup your data first!
     */
    public function up(): void
    {
        // Drop the existing invoices table
        // WARNING: This will delete all invoice data!
        Schema::dropIfExists('invoices');

        // Recreate with UUID primary key
        Schema::create('invoices', function (Blueprint $table) {
            // UUID as binary(16) for efficient storage and indexing
            $table->uuid('id')->primary();

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

        // Note: invoice_items table will also need updating to reference UUID
        Schema::table('invoice_items', function (Blueprint $table) {
            // Drop existing foreign key if it exists
            $table->dropForeign(['invoice_id']);

            // Change invoice_id to UUID
            $table->uuid('invoice_id')->change();

            // Recreate foreign key
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop and recreate with original structure
        Schema::dropIfExists('invoices');

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

        // Revert invoice_items back to bigInteger
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->unsignedBigInteger('invoice_id')->change();
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
        });
    }
};

