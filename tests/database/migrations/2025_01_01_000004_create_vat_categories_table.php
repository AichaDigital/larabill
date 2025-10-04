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

            // Category identification
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('country_code', 2);

            // VAT rate information
            $table->decimal('vat_rate', 5, 2);
            $table->enum('category_type', ['standard', 'reduced', 'super_reduced', 'exempt']);

            // Category scope
            $table->boolean('applies_to_products')->default(true);
            $table->boolean('applies_to_services')->default(true);

            // Special conditions and metadata
            $table->json('special_conditions')->nullable();
            $table->integer('sort_order')->default(0);

            // Hierarchy
            $table->unsignedBigInteger('parent_category_id')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_updated');

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->unique(['name', 'country_code'], 'unique_name_country');
            $table->index('category_type');
            $table->index('is_active');
            $table->index('country_code');
            $table->index('sort_order');
            $table->index('parent_category_id');
            $table->index('applies_to_products');
            $table->index('applies_to_services');

            // Foreign keys
            $table->foreign('parent_category_id')->references('id')->on('vat_categories')->onDelete('set null');

            // Constraints - removed for SQLite compatibility
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
