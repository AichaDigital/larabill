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
        if (! Schema::hasTable('tax_categories')) {
            Schema::create('tax_categories', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('tax_type'); // vat, sales_tax, gst, hst
                $table->text('description')->nullable();
                $table->integer('default_rate'); // Base-100 integer
                $table->string('country_code', 2); // ISO 3166-1 alpha-2
                $table->string('region_code', 10)->nullable(); // State/Province code
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['country_code', 'is_active']);
                $table->index(['tax_type', 'is_active']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_categories');
    }
};
