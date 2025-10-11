<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Rename company_fiscal_configs to fiscal_settings
        Schema::rename('company_fiscal_configs', 'fiscal_settings');

        // Rename user_tax_infos to user_tax_profiles
        Schema::rename('user_tax_infos', 'user_tax_profiles');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert table names
        Schema::rename('fiscal_settings', 'company_fiscal_configs');
        Schema::rename('user_tax_profiles', 'user_tax_infos');
    }
};

