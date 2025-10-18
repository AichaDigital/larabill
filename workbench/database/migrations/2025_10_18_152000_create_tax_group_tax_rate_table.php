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
        Schema::create('tax_group_tax_rate', function (Blueprint $table) {
            $table->foreignId('tax_group_id')->constrained('tax_groups')->onDelete('cascade');
            $table->foreignId('tax_rate_id')->constrained('tax_rates')->onDelete('cascade');
            $table->unsignedInteger('priority')->default(0)->comment('Para cálculos compuestos donde el orden importa');

            $table->primary(['tax_group_id', 'tax_rate_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_group_tax_rate');
    }
};
