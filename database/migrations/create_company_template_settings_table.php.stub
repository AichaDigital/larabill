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

            // Type fields as tinyint with PHP Enum casts
            $table->unsignedTinyInteger('setting_type')
                ->comment('0=template, 1=notes, 2=payment_terms (SettingType enum)');
            $table->unsignedTinyInteger('invoice_type')
                ->default(0)
                ->comment('0=fiscal, 1=proforma, 2=reverse_charge, 3=exempt (TemplateInvoiceType enum)');
            $table->unsignedTinyInteger('scope')
                ->default(0)
                ->comment('0=global, 1=client, 2=individual (SettingScope enum)');

            // Client ID - agnostic type (nullable for global/individual scope)
            MigrationHelper::agnosticIdColumn($table, 'client_id', nullable: true);

            $table->text('value'); // Setting value
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Unique constraint - now fits within index size limits
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
