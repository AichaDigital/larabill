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
        Schema::create('fiscal_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_oss')->default(false)->comment('Whether the company is registered in OSS (One Stop Shop)');
            $table->boolean('is_roi')->default(false)->comment('Whether the company is a Reverse Charge Operator (ROI)');
            $table->integer('fiscal_year');
            $table->boolean('apply_destination_iva')->default(false);
            $table->boolean('auto_apply_destination')->default(true);
            $table->integer('eu_sales_threshold')->default(1000000)->comment('EU sales threshold in base-100 integer (€10,000 = 1000000)');
            $table->integer('current_eu_sales_amount')->default(0)->comment('Current EU sales amount in base-100 integer');
            $table->boolean('threshold_exceeded')->default(false)->comment('Whether the threshold has been exceeded');
            $table->timestamp('threshold_exceeded_at')->nullable()->comment('When the threshold was exceeded');
            $table->boolean('notification_sent')->default(false)->comment('Whether notification has been sent for threshold exceeded');
            $table->string('fiscal_year_start', 5)->default('01-01')->comment('Fiscal year start date (MM-DD format)');
            $table->string('currency', 3)->default('EUR')->comment('Company currency code');
            $table->string('threshold_notification_email')->nullable()->comment('Email for threshold notifications');
            $table->json('custom_threshold_rules')->nullable()->comment('Custom threshold rules for specific countries/products');
            $table->timestamps();

            // Add indexes
            $table->index(['user_id', 'fiscal_year']);
            $table->unique(['user_id', 'fiscal_year']);
            $table->index(['notification_sent']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_settings');
    }
};
