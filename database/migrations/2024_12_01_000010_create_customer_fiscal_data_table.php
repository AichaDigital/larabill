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
     * Tabla para histórico fiscal de clientes/usuarios.
     * Un usuario puede tener múltiples configuraciones fiscales en el tiempo,
     * pero solo una activa (valid_until = null) en cualquier momento.
     */
    public function up(): void
    {
        Schema::create('customer_fiscal_data', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id')->comment('FK a users - El cliente/usuario');

            // Identidad fiscal del cliente
            $table->string('fiscal_name')->comment('Nombre fiscal (puede diferir de user.name)');
            $table->string('tax_id')->nullable()->comment('NIF/CIF del cliente');
            $table->string('legal_entity_type')->nullable()->comment('SL, Autónomo, Particular, etc.');

            // Domicilio fiscal
            $table->string('address')->nullable()->comment('Dirección fiscal');
            $table->string('city')->nullable()->comment('Ciudad');
            $table->string('state')->nullable()->comment('Provincia/Estado');
            $table->string('zip_code')->nullable()->comment('Código postal');
            $table->string('country_code', 2)->default('ES')->comment('Código país ISO');

            // Configuración fiscal
            $table->boolean('is_company')->default(false)->comment('Empresa (true) vs Particular (false)');
            $table->boolean('is_eu_vat_registered')->default(false)->comment('Registro IVA intracomunitario');
            $table->boolean('is_exempt_vat')->default(false)->comment('Exento de IVA');

            // Temporalidad (CRÍTICO para inmutabilidad)
            $table->date('valid_from')->comment('Fecha inicio vigencia');
            $table->date('valid_until')->nullable()->comment('Fecha fin vigencia (null = actual/activa)');

            // Estado y notas
            $table->boolean('is_active')->default(true)->comment('Config activa');
            $table->text('notes')->nullable()->comment('Notas sobre cambio fiscal (ej: cambio domicilio, CIF)');

            $table->timestamps();
            $table->softDeletes();

            // Foreign key a users
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            // Índices para queries eficientes
            $table->index(['user_id', 'valid_from', 'valid_until', 'is_active'], 'idx_user_validity_active');
            $table->index('tax_id', 'idx_customer_tax_id');
            $table->index(['user_id', 'is_active'], 'idx_user_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_fiscal_data');
    }
};

