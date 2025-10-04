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
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();

            // Tax identification
            $table->string('country_code', 2);
            $table->string('country_name');
            $table->string('tax_name');
            $table->string('tax_type');

            // Tax rate (using base 100 format: 21.50% = 2150)
            $table->integer('rate'); // Base 100: 21.50% = 2150

            // Status and conditions
            $table->boolean('is_active')->default(true);
            $table->string('applies_to')->nullable();
            $table->json('special_conditions')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('country_code');
            $table->index('tax_type');
            $table->index('is_active');
            $table->index('rate');
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
