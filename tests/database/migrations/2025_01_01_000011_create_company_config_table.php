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
        Schema::create('company_config', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_oss')->default(false);
            $table->boolean('is_roi')->default(false);
            $table->integer('eu_sales_threshold')->default(1000000); // Base-100: €10,000.00
            $table->integer('current_eu_sales_amount')->default(0); // Base-100
            $table->timestamp('threshold_exceeded_at')->nullable();
            $table->boolean('threshold_exceeded')->default(false);
            $table->integer('fiscal_year');
            $table->boolean('auto_apply_destination')->default(true);
            $table->boolean('notification_sent')->default(false);
            $table->string('fiscal_year_start')->default('01-01');
            $table->string('currency')->default('EUR');
            $table->string('threshold_notification_email')->nullable();
            $table->json('custom_threshold_rules')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_config');
    }
};
