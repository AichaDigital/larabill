<?php

use AichaDigital\Larabill\Support\MigrationHelper;
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
        Schema::create('company_template_settings', function (Blueprint $table) {
            $table->id();

            // Agnostic user_id - auto-detects User model ID type (for multi-user support)
            MigrationHelper::userIdColumn($table);
            $table->string('setting_type', 50); // 'template', 'notes', 'payment_terms'
            $table->string('invoice_type', 50)->default('fiscal'); // 'fiscal', 'proforma', 'reverse-charge', 'exempt'
            $table->string('scope', 50)->default('global'); // 'global', 'client', 'individual'
            $table->string('client_id', 100)->nullable(); // For client-specific settings
            $table->text('value'); // Setting value
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'setting_type', 'invoice_type', 'scope', 'client_id'], 'user_setting_unique');
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

