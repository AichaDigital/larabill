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
        // Skip if table already exists (created by package migrations)
        if (Schema::hasTable('user_tax_profiles')) {
            return;
        }

        Schema::create('user_tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_current')->default(false);
            $table->string('tax_code');
            $table->string('company_name');
            $table->text('address');
            $table->string('city');
            $table->string('postal_code');
            $table->string('country');
            $table->string('state')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_current']);
            $table->index('tax_code');
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
