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
        Schema::create('user_roi_verifications', function (Blueprint $table) {
            $table->id();

            // User and VAT identification
            $table->string('user_id');
            $table->string('vat_number');
            $table->string('country_code', 2);

            // ROI verification data
            $table->boolean('is_roi')->default(false);
            $table->string('company_name')->nullable();
            $table->text('company_address')->nullable();

            // Cache and expiration
            $table->timestamp('last_check');
            $table->timestamp('expired_at');
            $table->boolean('cache_hit')->default(false);

            // API source and response
            $table->string('api_source')->nullable();
            $table->json('response_data')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->unique(['user_id', 'vat_number', 'country_code'], 'unique_user_vat_country');
            $table->index('expired_at');
            $table->index('last_check');
            $table->index(['user_id', 'country_code']);
            $table->index('is_roi');

            // Constraints - removed for SQLite compatibility
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_roi_verifications');
    }
};
