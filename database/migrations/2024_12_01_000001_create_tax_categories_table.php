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
        Schema::create('tax_categories', function (Blueprint $table) {
            $table->id();

            $table->string('code', 50)->unique()->comment('Tax code: vat_standard, vat_reduced, sales_tax_ca, gst_standard, etc.');
            $table->string('name')->comment('Display name: "IVA General" (ES), "Sales Tax" (US), "GST" (AU), etc.');
            $table->string('tax_type', 50)->default('vat')->comment('Tax system type: vat (EU), sales_tax (US), gst (AU), hst (CA), etc.');
            $table->text('description')->nullable()->comment('Detailed description of tax category and when it applies');
            $table->integer('default_rate')->default(0)->comment('Base-100: 21% = 2100, 7.25% = 725');
            $table->string('country_code', 2)->default('ES')->comment('ISO 3166-1 alpha-2 country code: ES, US, AU, CA, etc.');
            $table->string('region_code', 10)->nullable()->comment('State/Province code for regional taxes (US: CA, NY; Canada: ON, BC)');
            $table->boolean('is_active')->default(true)->comment('If false, category is disabled');
            $table->unsignedInteger('sort_order')->default(0)->comment('Display order in dropdowns (lower = first)');
            $table->json('metadata')->nullable()->comment('Additional flexible data (rules, exemptions, etc.)');
            $table->timestamps();

            $table->index(['country_code', 'is_active']);
            $table->index(['tax_type', 'country_code']);
            $table->index(['region_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_categories');
    }
};

