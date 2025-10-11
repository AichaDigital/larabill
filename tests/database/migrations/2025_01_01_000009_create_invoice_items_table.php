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
        // Skip if table already exists (created by package migrations)
        if (Schema::hasTable('invoice_items')) {
            return;
        }

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();

            // Invoice reference (UUID)
            $table->uuid('invoice_id');

            // Item details
            $table->string('description');

            // Quantities and prices (using base 100 format)
            $table->integer('quantity'); // Base 100: 1.5 => 150
            $table->integer('unit_price'); // Base 100: €12.34 = 1234
            $table->integer('subtotal'); // Base 100: €12.34 = 1234

            // Tax information
            $table->integer('tax_rate'); // Base 100: 21.50% = 2150
            $table->integer('tax_amount'); // Base 100: €12.34 = 1234
            $table->integer('total'); // Base 100: €12.34 = 1234

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('invoice_id');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
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
