<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the tax_groups table for the Configuration Layer.
     * Tax groups aggregate multiple tax rates that are applied together.
     */
    public function up(): void
    {
        Schema::create('tax_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Tax group name (e.g., "Servicios Digitales UE", "Venta Estándar en Boston")');
            $table->text('description')->nullable()->comment('Optional description of the tax group');
            $table->timestamps();

            // Index for name searches
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_groups');
    }
};
