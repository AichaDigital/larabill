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
        if (Schema::hasTable('company_template_settings')) {
            return;
        }

        Schema::create('company_template_settings', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index(); // For multi-company support
            $table->string('setting_type'); // 'template', 'notes', 'payment_terms'
            $table->string('invoice_type')->default('fiscal'); // 'fiscal', 'proforma', 'reverse-charge', 'exempt'
            $table->string('scope')->default('global'); // 'global', 'client', 'individual'
            $table->string('client_id')->nullable(); // For client-specific settings
            $table->text('value'); // Setting value
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'setting_type', 'invoice_type', 'scope', 'client_id'], 'comp_setting_unique');
            $table->index(['user_id', 'invoice_type']);
            $table->index(['setting_type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_template_settings');
    }
};
