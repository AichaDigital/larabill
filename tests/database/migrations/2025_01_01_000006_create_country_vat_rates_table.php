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
        Schema::create('country_vat_rates', function (Blueprint $table) {
            $table->id();

            // Country identification
            $table->string('country_code', 2)->unique();
            $table->string('country_name');

            // VAT rates (using base 100 format: 21.50% = 2150)
            $table->integer('standard_rate'); // Base 100: 21.50% = 2150
            $table->json('reduced_rates')->nullable(); // Array of integers in base 100
            $table->json('exempt_categories')->nullable();

            // Metadata
            $table->timestamp('last_updated');
            $table->string('data_source')->default('manual');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('is_active');
            $table->index('last_updated');
            $table->index('data_source');
            $table->index('standard_rate'); // Integer index for base 100 rates

            // Constraints - removed for SQLite compatibility
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('country_vat_rates');
    }
};
