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
        Schema::create('vat_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('vat_code');
            $table->string('country_code', 2);
            $table->boolean('is_valid');
            $table->string('company_name')->nullable();
            $table->string('company_address')->nullable();
            $table->string('api_source')->nullable();
            $table->json('response_data')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['vat_code', 'is_valid']);
            $table->index(['is_valid', 'verified_at']);
            $table->unique(['vat_code', 'country_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vat_verifications');
    }
};
