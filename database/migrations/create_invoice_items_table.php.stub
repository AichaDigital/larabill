<?php

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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id()->comment('PK for individual line editing, deletion, reordering, and future relations (discounts, promotions)');

            // UUID FK to invoices - matches Invoice primary key type
            $table->uuid('invoice_id');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade')->comment('UUID FK to parent invoice');

            // Item identification
            $table->unsignedTinyInteger('item_type')->default(0)->comment('ItemType enum: 0=good, 1=service. Critical for EU tax rules (services have different treatment)');
            $table->string('description')->comment('Item description (product or service)');
            $table->string('internal_code')->nullable()->comment('User internal code for product/service');

            // Quantity & Unit
            $table->integer('quantity')->default(100)->comment('Base-100: 1 unit = 100, 2.5 units = 250');
            $table->foreignId('unit_measure_id')->nullable()->constrained('unit_measures')->nullOnDelete()->comment('FK to extensible unit_measures table (unit, kg, L, m, m², etc.)');

            // Pricing & Tax (Agnostic Structure)
            $table->integer('unit_price')->default(0)->comment('Base-100: price per unit measure');
            $table->integer('taxable_amount')->default(0)->comment('Base-100: taxable base = quantity * unit_price (before tax)');
            $table->integer('total_tax_amount')->default(0)->comment('Base-100: Suma de todos los impuestos aplicados');
            $table->json('taxes_applied')->nullable()->comment('Snapshot inmutable del desglose de impuestos aplicados');
            $table->integer('total_amount')->default(0)->comment('Base-100: total line = taxable_amount + total_tax_amount');

            // Service dates (EU requirement for services)
            $table->date('service_date_from')->nullable()->comment('Service start date (EU: mandatory for services if config requires)');
            $table->date('service_date_to')->nullable()->comment('Service end date. Example: Hosting 2025-01-01 to 2025-12-31');

            $table->json('metadata')->nullable()->comment('Flexible additional data');
            $table->timestamps();

            // Optimized indexes
            $table->index(['invoice_id', 'item_type']);
            $table->index(['service_date_from', 'service_date_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
