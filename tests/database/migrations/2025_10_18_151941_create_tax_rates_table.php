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
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Ej: "IVA General", "MA State Sales Tax"');
            $table->integer('rate')->comment('Base-100 integer, ej: 2100 para 21%');
            $table->string('region')->nullable()->comment('Ej: "ES", "US-MA"');
            $table->enum('type', ['vat', 'sales_tax', 'gst', 'other'])->default('vat');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
