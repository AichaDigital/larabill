<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds v0.4.0 fiscal verification fields to invoices table.
     *
     * NOTE: customer_id, issuer_snapshot, customer_snapshot, and fiscal_snapshot
     * are now created directly in create_invoices_table.php as part of ADR-001.
     * This migration now only adds fiscal verification columns.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // v0.4.0: Digital Fiscal Verification (Verifactu, TicketBAI, etc.)
            $table->string('fiscal_verification_id')->nullable()->after('fiscal_snapshot')
                ->comment('ID de verificación fiscal (ej: Verifactu, TicketBAI)');

            $table->text('fiscal_verification_qr')->nullable()->after('fiscal_verification_id')
                ->comment('Código QR para verificación fiscal (base64 o URL)');

            $table->string('fiscal_verification_hash')->nullable()->after('fiscal_verification_qr')
                ->comment('Hash de la factura para verificación de integridad');

            $table->timestamp('fiscal_verified_at')->nullable()->after('fiscal_verification_hash')
                ->comment('Timestamp de cuándo se verificó fiscalmente');

            $table->json('fiscal_verification_metadata')->nullable()->after('fiscal_verified_at')
                ->comment('Metadatos adicionales de la verificación fiscal');

            // Indexes
            $table->index('fiscal_verification_id');
            $table->index('fiscal_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex(['fiscal_verification_id']);
            $table->dropIndex(['fiscal_verified_at']);

            // Drop columns
            $table->dropColumn([
                'fiscal_verification_id',
                'fiscal_verification_qr',
                'fiscal_verification_hash',
                'fiscal_verified_at',
                'fiscal_verification_metadata',
            ]);
        });
    }
};
