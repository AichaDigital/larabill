<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Añade columnas para snapshot de configs fiscales en invoices.
     * Estas columnas almacenan IDs de las configs vigentes al momento de emisión.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // FK a CompanyFiscalConfig (config de empresa vigente en invoice_date)
            $table->unsignedBigInteger('company_fiscal_config_id')
                ->nullable()
                ->after('user_id')
                ->comment('FK to company_fiscal_configs - Snapshot fiscal empresa');

            // FK a CustomerFiscalData (config de cliente vigente en invoice_date)
            $table->unsignedBigInteger('customer_fiscal_data_id')
                ->nullable()
                ->after('company_fiscal_config_id')
                ->comment('FK to customer_fiscal_data - Snapshot fiscal cliente');

            // Índices para queries eficientes
            $table->foreign('company_fiscal_config_id')
                ->references('id')
                ->on('company_fiscal_configs')
                ->nullOnDelete(); // Si se elimina config, mantener invoice

            $table->foreign('customer_fiscal_data_id')
                ->references('id')
                ->on('customer_fiscal_data')
                ->nullOnDelete(); // Si se elimina data, mantener invoice
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['company_fiscal_config_id']);
            $table->dropForeign(['customer_fiscal_data_id']);
            $table->dropColumn(['company_fiscal_config_id', 'customer_fiscal_data_id']);
        });
    }
};
