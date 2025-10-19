<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the tax_rates table for the Configuration Layer.
     * This table stores atomic tax rate definitions that can be updated when tax laws change.
     */
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Tax rate name (e.g., "IVA General", "MA State Sales Tax")');
            $table->integer('rate')->comment('Base-100 integer (e.g., 2100 for 21%)');
            $table->string('region')->nullable()->comment('Region/jurisdiction code (e.g., "ES", "US-MA", "US-MA-BOSTON")');
            $table->enum('type', ['vat', 'sales_tax', 'gst', 'other'])->default('vat')->comment('Tax type: vat, sales_tax, gst, other');
            $table->timestamps();

            // Indexes for common queries
            $table->index(['region', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
