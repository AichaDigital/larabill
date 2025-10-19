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
        Schema::table('invoice_items', function (Blueprint $table) {
            // New columns for agnostic tax system
            $table->integer('total_tax_amount')->default(0)->after('taxable_amount')->comment('Base-100: Suma de todos los impuestos aplicados');
            $table->json('taxes_applied')->nullable()->after('total_tax_amount')->comment('Snapshot inmutable del desglose de impuestos aplicados');

            // Drop old columns
            $table->dropForeign(['tax_category_id']);
            $table->dropColumn(['tax_rate', 'tax_category_id', 'tax_amount']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // Restore old columns
            $table->integer('tax_rate')->default(0)->after('taxable_amount')->comment('Base-100: tax percentage. 21% = 2100');
            $table->foreignId('tax_category_id')->nullable()->after('tax_rate')->constrained('tax_categories')->nullOnDelete();
            $table->integer('tax_amount')->default(0)->after('tax_category_id')->comment('Base-100: calculated tax');

            // Drop new columns
            $table->dropColumn(['total_tax_amount', 'taxes_applied']);
        });
    }
};
