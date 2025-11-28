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
     * Tabla para configuración fiscal de la empresa emisora de facturas.
     * Una empresa puede tener múltiples configuraciones fiscales en el tiempo,
     * pero solo una activa (valid_until = null) en cualquier momento.
     */
    public function up(): void
    {
        Schema::create('company_fiscal_configs', function (Blueprint $table) {
            $table->id();

            // Identidad fiscal de la empresa
            $table->string('business_name')->comment('Razón social de la empresa');
            $table->string('tax_id')->comment('CIF/NIF fiscal (ej: ESB12345678)');
            $table->string('legal_entity_type')->comment('Tipo: SL, SA, Autónomo, etc.');

            // Domicilio fiscal
            $table->string('address')->comment('Dirección fiscal completa');
            $table->string('city')->comment('Ciudad');
            $table->string('state')->nullable()->comment('Provincia/Estado');
            $table->string('zip_code')->comment('Código postal');
            $table->string('country_code', 2)->default('ES')->comment('Código país ISO (ES, FR, etc.)');

            // Configuración fiscal
            $table->boolean('is_oss')->default(false)->comment('Operador OSS (One Stop Shop)');
            $table->boolean('is_roi')->default(false)->comment('Operador Intracomunitario (ROI)');
            $table->string('currency', 3)->default('EUR')->comment('Moneda por defecto');
            $table->string('fiscal_year_start', 5)->default('01-01')->comment('Inicio año fiscal (MM-DD)');

            // Temporalidad (CRÍTICO para inmutabilidad)
            $table->date('valid_from')->comment('Fecha inicio vigencia');
            $table->date('valid_until')->nullable()->comment('Fecha fin vigencia (null = actual/activa)');

            // Estado y notas
            $table->boolean('is_active')->default(true)->comment('Config activa');
            $table->text('notes')->nullable()->comment('Notas sobre cambio fiscal (ej: fusión, cambio CIF)');

            $table->timestamps();
            $table->softDeletes();

            // Índices para queries eficientes
            $table->index(['valid_from', 'valid_until', 'is_active'], 'idx_validity_active');
            $table->index('tax_id', 'idx_tax_id');
            $table->index('is_active', 'idx_is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_fiscal_configs');
    }
};

