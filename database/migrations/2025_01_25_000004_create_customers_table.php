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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // User relationship (optional - customer puede existir sin User del sistema)
            MigrationHelper::userIdColumn($table, nullable: true);

            // Customer type and relationship to User
            $table->enum('relationship_type', ['self', 'self_company', 'client', 'other'])
                ->default('self')
                ->comment('self=User mismo, self_company=empresa del User, client=cliente del User');

            // Basic Info
            $table->string('display_name')->comment('Nombre para mostrar (puede ser nombre legal o comercial)');
            $table->string('internal_code')->nullable()->comment('Código interno del cliente (opcional)');

            // Legal Entity Type
            $table->string('legal_entity_type_code', 20)->comment('Tipo de entidad jurídica (FK a legal_entity_types)');

            // Current Tax Profile (points to active profile)
            $table->unsignedBigInteger('current_tax_profile_id')->nullable()->comment('FK a customer_tax_profiles - Perfil fiscal activo');

            // Status
            $table->boolean('is_active')->default(true)->comment('Si el cliente está activo');
            $table->timestamp('inactive_since')->nullable()->comment('Cuándo se inactivó');
            $table->string('inactive_reason')->nullable()->comment('Motivo de inactivación');

            // Commercial
            $table->text('notes')->nullable()->comment('Notas internas sobre el cliente');
            $table->json('metadata')->nullable()->comment('Metadatos adicionales (preferencias, tags, etc.)');

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('user_id');
            $table->index('relationship_type');
            $table->index('legal_entity_type_code');
            $table->index('is_active');
            $table->index('internal_code');

            // Foreign keys
            $table->foreign('legal_entity_type_code')
                ->references('code')
                ->on('legal_entity_types')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
