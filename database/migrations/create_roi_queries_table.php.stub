<?php

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
        Schema::create('roi_queries', function (Blueprint $table) {
            $table->id();

            // User ID - agnostic type based on larabill config or users table detection
            MigrationHelper::userIdColumn($table, 'user_id');

            $table->string('vat_code', 50); // VAT/NIF number
            $table->string('country_code', 2); // ISO 3166-1 alpha-2
            $table->string('query_type', 20)->default('api'); // api, cache
            $table->string('api_source', 100)->nullable(); // VIES, local_provider, etc.
            $table->json('response_data')->nullable(); // Full API response for audit
            $table->timestamp('queried_at');
            $table->boolean('cache_used')->default(false);
            $table->timestamp('legal_retention_until'); // 7 years by default
            $table->timestamps();

            // Indexes for common queries
            $table->index(['user_id', 'vat_code']);
            $table->index('country_code');
            $table->index('queried_at');
            $table->index('legal_retention_until'); // For cleanup jobs
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roi_queries');
    }
};
