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
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUuid('converted_invoice_id')
                ->nullable()
                ->after('rectifies_invoice_id')
                ->constrained('invoices')
                ->nullOnDelete()
                ->comment('UUID of final invoice when a proforma is converted');

            $table->timestamp('converted_at')
                ->nullable()
                ->after('converted_invoice_id')
                ->comment('Timestamp when proforma was converted to final invoice');

            $table->index('converted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['converted_at']);
            $table->dropForeign(['converted_invoice_id']);
            $table->dropColumn(['converted_invoice_id', 'converted_at']);
        });
    }
};
