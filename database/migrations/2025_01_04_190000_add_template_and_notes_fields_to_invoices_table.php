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
        Schema::table('invoices', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('paid_at');
            $table->string('payment_terms')->nullable()->after('notes');
            $table->string('template_name')->nullable()->after('payment_terms');

            // Index for template_name for faster queries
            $table->index('template_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['template_name']);
            $table->dropColumn(['notes', 'payment_terms', 'template_name']);
        });
    }
};
