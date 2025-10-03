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
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2);
            $table->string('country_name');
            $table->string('tax_name');
            $table->string('tax_type');
            $table->decimal('rate', 5, 4);
            $table->boolean('is_active')->default(true);
            $table->string('applies_to')->nullable();
            $table->json('special_conditions')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['country_code']);
            $table->index(['is_active']);
            $table->index(['country_code', 'tax_type']);
            $table->unique(['country_code', 'tax_type', 'applies_to']);
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
