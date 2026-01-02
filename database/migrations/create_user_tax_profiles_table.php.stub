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
     *
     * Unified fiscal history table for all users.
     * A user can have multiple tax profiles over time,
     * but only one active (valid_until = null) at any moment.
     *
     * ADR-004 Change: Renamed user_id to owner_user_id.
     * - owner_user_id: The user who owns/can edit this tax profile
     * - Multiple users can share the same profile via users.current_tax_profile_id
     *
     * @see ADR-003 for user unification architecture
     * @see ADR-004 for authorization architecture (owner_user_id change)
     */
    public function up(): void
    {
        Schema::create('user_tax_profiles', function (Blueprint $table) {
            $table->id();
            MigrationHelper::userIdColumn($table, 'owner_user_id');

            // Fiscal identity
            $table->string('fiscal_name')->comment('Fiscal name (may differ from user.name)');
            $table->string('tax_id')->nullable()->comment('NIF/CIF/VAT number');
            $table->string('legal_entity_type_code')->nullable()->comment('FK to legal_entity_types.code');

            // Fiscal address
            $table->string('address')->nullable()->comment('Fiscal address');
            $table->string('city')->nullable()->comment('City');
            $table->string('state')->nullable()->comment('Province/State');
            $table->string('zip_code')->nullable()->comment('Postal code');
            $table->string('country_code', 2)->default('ES')->comment('ISO 3166-1 alpha-2 country code');

            // Fiscal configuration
            $table->boolean('is_company')->default(false)->comment('Company (true) vs Individual (false)');
            $table->boolean('is_eu_vat_registered')->default(false)->comment('EU VAT registration (intra-community)');
            $table->boolean('is_exempt_vat')->default(false)->comment('VAT exempt');

            // Temporal validity (CRITICAL for immutability)
            $table->date('valid_from')->comment('Validity start date');
            $table->date('valid_until')->nullable()->comment('Validity end date (null = current/active)');

            // Status and notes
            $table->boolean('is_active')->default(true)->comment('Active config');
            $table->text('notes')->nullable()->comment('Notes about fiscal change (e.g., address change, CIF change)');

            $table->timestamps();
            $table->softDeletes();

            // Indexes for efficient queries
            $table->index(['owner_user_id', 'valid_from', 'valid_until', 'is_active'], 'idx_utp_owner_validity_active');
            $table->index('tax_id', 'idx_utp_tax_id');
            $table->index(['owner_user_id', 'is_active'], 'idx_utp_owner_active');

            // Foreign key to legal_entity_types (if table exists)
            // Note: This is a soft reference via code, not a strict FK
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_tax_profiles');
    }
};
