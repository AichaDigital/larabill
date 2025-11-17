<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the pivot table between tax_groups and tax_rates.
     * Allows multiple tax rates to be grouped and applied together with priority ordering.
     */
    public function up(): void
    {
        Schema::create('tax_group_tax_rate', function (Blueprint $table) {
            $table->foreignId('tax_group_id')->constrained('tax_groups')->onDelete('cascade')->comment('FK to tax_groups');
            $table->foreignId('tax_rate_id')->constrained('tax_rates')->onDelete('cascade')->comment('FK to tax_rates');
            $table->unsignedInteger('priority')->default(0)->comment('Order for compound tax calculations where sequence matters');

            $table->primary(['tax_group_id', 'tax_rate_id'], 'tax_group_tax_rate_primary');
            $table->index('priority');
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
