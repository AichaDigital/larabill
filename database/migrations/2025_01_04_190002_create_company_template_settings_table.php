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
        Schema::create('company_template_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_id')->index(); // For multi-company support
            $table->string('invoice_type')->default('fiscal'); // 'fiscal', 'proforma', 'reverse-charge', 'exempt'
            $table->unsignedBigInteger('user_id')->nullable(); // For client-specific settings
            $table->string('default_template_name')->nullable();
            $table->text('default_notes')->nullable();
            $table->text('default_payment_terms')->nullable();
            $table->json('settings')->nullable(); // General settings for templates
            $table->timestamps();

            $table->unique(['company_id', 'invoice_type', 'user_id'], 'comp_type_user_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_template_settings');
    }
};
