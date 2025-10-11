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
        // Rename tax_id to tax_code in user_tax_profiles
        Schema::table('user_tax_profiles', function (Blueprint $table) {
            $table->renameColumn('tax_id', 'tax_code');
        });

        // Rename vat_number to vat_code in vat_verifications
        Schema::table('vat_verifications', function (Blueprint $table) {
            $table->renameColumn('vat_number', 'vat_code');
        });

        // Rename company_id to user_id in fiscal_settings
        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->renameColumn('company_id', 'user_id');
        });

        // Rename company_id to user_id in company_template_settings
        Schema::table('company_template_settings', function (Blueprint $table) {
            $table->renameColumn('company_id', 'user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert column names
        Schema::table('user_tax_profiles', function (Blueprint $table) {
            $table->renameColumn('tax_code', 'tax_id');
        });

        Schema::table('vat_verifications', function (Blueprint $table) {
            $table->renameColumn('vat_code', 'vat_number');
        });

        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->renameColumn('user_id', 'company_id');
        });

        Schema::table('company_template_settings', function (Blueprint $table) {
            $table->renameColumn('user_id', 'company_id');
        });
    }
};

