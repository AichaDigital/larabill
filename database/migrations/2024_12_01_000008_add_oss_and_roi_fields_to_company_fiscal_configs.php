<?php

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
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->boolean('is_oss')->default(false)->after('company_id')->comment('Whether the company is registered in OSS (One Stop Shop)');
            $table->boolean('is_roi')->default(false)->after('is_oss')->comment('Whether the company is a Reverse Charge Operator (ROI)');
            $table->boolean('threshold_exceeded')->default(false)->after('current_eu_sales_amount')->comment('Whether the threshold has been exceeded');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropColumn(['is_oss', 'is_roi', 'threshold_exceeded']);
        });
    }
};
