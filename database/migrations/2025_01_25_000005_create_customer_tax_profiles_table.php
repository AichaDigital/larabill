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
        Schema::create('customer_tax_profiles', function (Blueprint $table) {
            $table->id();

            // Customer relationship
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete()
                ->comment('FK a customers - Cliente propietario de este perfil fiscal');

            // Identification
            $table->string('legal_name')->comment('Nombre legal/razón social');
            $table->string('commercial_name')->nullable()->comment('Nombre comercial (si difiere)');
            $table->string('tax_code')->comment('NIF/CIF/NIE del cliente');
            $table->string('legal_entity_type_code', 20)->comment('Tipo de entidad jurídica');

            // Address
            $table->string('address')->comment('Dirección fiscal completa');
            $table->string('address_line_2')->nullable()->comment('Línea adicional de dirección');
            $table->string('city')->comment('Ciudad');
            $table->string('state')->nullable()->comment('Provincia/Estado');
            $table->string('postal_code', 20)->comment('Código postal');
            $table->string('country_code', 2)->default('ES')->comment('Código ISO 3166-1 alpha-2');

            // Contact
            $table->string('phone')->nullable()->comment('Teléfono de contacto');
            $table->string('email')->nullable()->comment('Email de contacto');

            // VAT/ROI (for B2B)
            $table->string('vat_number')->nullable()->comment('Número de IVA intracomunitario (para B2B EU)');
            $table->boolean('vat_number_verified')->default(false)->comment('Si el VAT ha sido verificado');
            $table->timestamp('vat_verified_at')->nullable()->comment('Cuándo se verificó el VAT');
            $table->json('vat_verification_data')->nullable()->comment('Datos de la última verificación VAT');

            // Validity Period
            $table->date('valid_from')->comment('Fecha desde la cual es válido este perfil');
            $table->date('valid_until')->nullable()->comment('Fecha hasta la cual es válido (null = actual)');
            $table->boolean('is_current')->default(true)->comment('Si es el perfil fiscal activo del cliente');

            // Audit
            $table->text('change_reason')->nullable()->comment('Motivo del cambio de datos fiscales');
            $table->json('metadata')->nullable()->comment('Metadatos adicionales');

            $table->timestamps();

            // Indexes
            $table->index('customer_id');
            $table->index('tax_code');
            $table->index('is_current');
            $table->index(['customer_id', 'is_current']);
            $table->index(['valid_from', 'valid_until']);
            $table->index('legal_entity_type_code');

            // Foreign keys
            $table->foreign('legal_entity_type_code')
                ->references('code')
                ->on('legal_entity_types')
                ->restrictOnDelete();
        });

        // Add foreign key back to customers table
        Schema::table('customers', function (Blueprint $table) {
            $table->foreign('current_tax_profile_id')
                ->references('id')
                ->on('customer_tax_profiles')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['current_tax_profile_id']);
        });

        Schema::dropIfExists('customer_tax_profiles');
    }
};
