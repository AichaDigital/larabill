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
        Schema::create('issuer_tax_profiles', function (Blueprint $table) {
            $table->id();

            // Identification
            $table->string('legal_name')->comment('Nombre legal/razón social del emisor');
            $table->string('commercial_name')->nullable()->comment('Nombre comercial (si difiere del legal)');
            $table->string('tax_id')->comment('NIF/CIF del emisor');
            $table->string('legal_entity_type_code', 20)->comment('Tipo de entidad jurídica (FK a legal_entity_types)');

            // Address
            $table->string('address')->comment('Dirección fiscal completa');
            $table->string('address_line_2')->nullable()->comment('Línea adicional de dirección');
            $table->string('city')->comment('Ciudad');
            $table->string('state')->nullable()->comment('Provincia/Estado');
            $table->string('postal_code', 20)->comment('Código postal');
            $table->string('country_code', 2)->default('ES')->comment('Código ISO 3166-1 alpha-2');

            // Contact
            $table->string('phone')->nullable()->comment('Teléfono de contacto');
            $table->string('email')->nullable()->comment('Email de contacto fiscal');
            $table->string('website')->nullable()->comment('Sitio web');

            // VAT/ROI
            $table->string('vat_number')->nullable()->comment('Número de IVA intracomunitario (ESB12345678)');
            $table->boolean('is_roi_registered')->default(false)->comment('Si está registrado en ROI en este periodo');
            $table->boolean('is_oss_registered')->default(false)->comment('Si está registrado en OSS en este periodo');

            // Validity Period
            $table->date('valid_from')->comment('Fecha desde la cual es válido este perfil');
            $table->date('valid_until')->nullable()->comment('Fecha hasta la cual es válido (null = actual)');
            $table->boolean('is_current')->default(true)->comment('Si es el perfil fiscal activo actualmente');

            // Audit
            $table->text('change_reason')->nullable()->comment('Motivo del cambio de identidad fiscal');
            $table->json('metadata')->nullable()->comment('Metadatos adicionales (registro mercantil, etc.)');

            $table->timestamps();

            // Indexes
            $table->index('tax_id');
            $table->index('is_current');
            $table->index(['valid_from', 'valid_until']);
            $table->index('legal_entity_type_code');

            // Foreign keys
            $table->foreign('legal_entity_type_code')
                ->references('code')
                ->on('legal_entity_types')
                ->restrictOnDelete();
        });

        // Add foreign key to issuer_config
        Schema::table('issuer_config', function (Blueprint $table) {
            $table->foreign('current_tax_profile_id')
                ->references('id')
                ->on('issuer_tax_profiles')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('issuer_config', function (Blueprint $table) {
            $table->dropForeign(['current_tax_profile_id']);
        });

        Schema::dropIfExists('issuer_tax_profiles');
    }
};
