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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id()->comment('PK for individual line editing, deletion, reordering, and future relations (discounts, promotions)');

            // Use binary(16) to match Invoice UUID storage (dyrynda/laravel-model-uuid)
            $table->binary('invoice_id', 16);
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade')->comment('UUID binary(16) parent invoice');

            // Item identification
            $table->unsignedTinyInteger('item_type')->default(0)->comment('ItemType enum: 0=good, 1=service. Critical for EU tax rules (services have different treatment)');
            $table->string('description')->comment('Item description (product or service)');
            $table->string('internal_code')->nullable()->comment('User internal code for product/service');

            // Quantity & Unit
            $table->integer('quantity')->default(100)->comment('Base-100: 1 unit = 100, 2.5 units = 250');
            $table->foreignId('unit_measure_id')->nullable()->constrained('unit_measures')->nullOnDelete()->comment('FK to extensible unit_measures table (unit, kg, L, m, m², etc.)');

            // Pricing
            $table->integer('unit_price')->default(0)->comment('Base-100: price per unit measure');
            $table->integer('taxable_amount')->default(0)->comment('Base-100: taxable base = quantity * unit_price (before tax)');

            // Tax
            $table->integer('tax_rate')->default(0)->comment('Base-100: tax percentage. 21% = 2100');
            $table->foreignId('tax_category_id')->nullable()->constrained('tax_categories')->nullOnDelete()->comment('FK to tax_categories (VAT/Sales Tax/GST categories by country)');
            $table->integer('tax_amount')->default(0)->comment('Base-100: calculated tax = taxable_amount * (tax_rate / 10000)');
            $table->integer('total_amount')->default(0)->comment('Base-100: total line = taxable_amount + tax_amount');

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
