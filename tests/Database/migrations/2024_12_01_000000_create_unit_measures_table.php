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
        Schema::create('unit_measures', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20)->unique()->comment('Unique code: unit, kg, liter, meter, m2, m3, hour, day, month');
            $table->string('symbol', 10)->comment('Display symbol: ud., kg, L, m, m², m³, h, day, month');
            $table->string('name')->comment('Full name: "Unit", "Kilogram", "Liter", etc.');
            $table->unsignedTinyInteger('category')->default(0)->comment('UnitMeasureCategory enum: 0=count, 1=weight, 2=volume, 3=length, 4=area, 5=time');
            $table->boolean('is_active')->default(true)->comment('If false, unit measure is disabled');
            $table->unsignedInteger('sort_order')->default(0)->comment('Display order in dropdowns (lower = first)');
            $table->timestamps();

            $table->index(['is_active', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_measures');
    }
};
