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
            $table->string('country_code', 2)->unique()->index(); // ISO 3166-1 alpha-2
            $table->string('country_name', 100);
            $table->integer('standard_rate'); // Base 100: 21.50% = 2150
            $table->json('reduced_rates')->nullable(); // {"food": 1000, "books": 400}
            $table->json('exempt_categories')->nullable(); // ["education", "healthcare"]
            $table->timestamp('last_updated')->nullable();
            $table->string('data_source')->default(''); // Cannot be nullable per model boot()
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes for common queries
            $table->index('is_active');
            $table->index('standard_rate');
            $table->index('last_updated');
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

