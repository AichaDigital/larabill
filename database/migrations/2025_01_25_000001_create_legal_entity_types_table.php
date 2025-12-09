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
        Schema::create('legal_entity_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->comment('Código único de tipo de entidad (PERSONA_FISICA, SOCIEDAD_LIMITADA, etc.)');
            $table->json('name')->comment('Nombre del tipo de entidad jurídica (translatable)');
            $table->json('abbreviation')->nullable()->comment('Abreviatura oficial translatable (SL, SA, Ltd, etc.)');
            $table->string('country_code', 2)->default('ES')->comment('Código ISO 3166-1 alpha-2 del país');
            $table->json('description')->nullable()->comment('Descripción del tipo de entidad (translatable)');
            $table->boolean('requires_tax_id')->default(true)->comment('Si requiere identificación fiscal (CIF/NIF)');
            $table->boolean('is_company')->default(false)->comment('Si es entidad empresarial (true) vs persona física (false)');
            $table->boolean('is_active')->default(true)->comment('Si está activo para nuevas entidades');
            $table->unsignedInteger('sort_order')->default(0)->comment('Orden de presentación');
            $table->json('metadata')->nullable()->comment('Metadatos adicionales (requisitos legales, etc.)');
            $table->timestamps();

            // Indexes
            $table->index(['country_code', 'is_active']);
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_entity_types');
    }
};
