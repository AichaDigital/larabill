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
        Schema::create('vat_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('country_code', 2)->index(); // ISO 3166-1 alpha-2
            $table->integer('vat_rate'); // Base 100: 21.50% = 2150
            $table->string('category_type', 50)->default('standard'); // standard, reduced, super_reduced, exempt
            $table->boolean('is_active')->default(true);
            $table->boolean('applies_to_products')->default(true);
            $table->boolean('applies_to_services')->default(true);
            $table->json('special_conditions')->nullable();
            $table->timestamp('last_updated')->nullable();
            $table->unsignedBigInteger('parent_category_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Foreign key for parent category
            $table->foreign('parent_category_id')
                ->references('id')
                ->on('vat_categories')
                ->onDelete('set null');

            // Indexes for common queries
            $table->unique(['name', 'country_code']); // Unique category per country
            $table->index('category_type');
            $table->index('is_active');
            $table->index('vat_rate');
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vat_categories');
    }
};

