<?php

declare(strict_types=1);

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
        Schema::create('invoice_series_control', function (Blueprint $table) {
            $table->id();

            $table->string('prefix', 10)->comment('User customizable prefix (max 10 chars): FAC, PRO, RECT, INTERNAL, etc.');
            $table->unsignedTinyInteger('serie')->comment('InvoiceSerieType enum: 0=proforma, 1=invoice, 2=rectificative');
            $table->year('fiscal_year')->comment('Fiscal year number for this series');
            $table->date('fiscal_year_start')->comment('Fiscal year start date (for accurate date range calculations)');
            $table->date('fiscal_year_end')->comment('Fiscal year end date (calculated: start + 1 year - 1 day)');

            $table->unsignedBigInteger('last_number')->default(0)->comment('Last issued number in this series');
            $table->unsignedBigInteger('start_number')->default(1)->comment('Starting number (default 1, user can customize)');
            $table->boolean('reset_annually')->default(true)->comment('If true, resets counter when fiscal year changes');

            $table->string('number_format', 100)->default('{{PREFIX}}-{{YEAR}}-{{NUMBER}}')->comment('Mustache template. Examples: {{PREFIX}}-{{YEAR}}-{{NUMBER}}, {{PREFIX}}-{{TIMESTAMP}}-{{NUMBER}}, {{CLIENT_UUID}}-{{NUMBER}}. Max 100 chars for generated result');

            $table->boolean('is_active')->default(true)->comment('If false, this series configuration is disabled');
            $table->text('description')->nullable()->comment('User notes about this series configuration');
            $table->json('validation_rules')->nullable()->comment('Custom validation rules in JSON format');

            $table->timestamps();
            $table->timestamp('last_used_at')->nullable()->comment('Last time this series was used to generate an invoice number');

            MigrationHelper::userIdColumn($table, 'user_id', nullable: true);

            $table->unique(['prefix', 'serie', 'fiscal_year', 'user_id']);
            $table->index(['is_active']);
            $table->index(['fiscal_year_start', 'fiscal_year_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_series_control');
    }
};

