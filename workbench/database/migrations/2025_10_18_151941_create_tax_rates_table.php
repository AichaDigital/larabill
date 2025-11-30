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
            $table->unsignedTinyInteger('type')->default(0)->comment('0=vat, 1=sales_tax, 2=gst, 3=other (TaxType enum)');
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
