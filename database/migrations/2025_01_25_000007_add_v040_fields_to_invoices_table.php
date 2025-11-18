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
     * Adds v0.4.0 fields to invoices table:
     * - customer_id (replaces user_id as billable entity)
     * - Encrypted snapshots (issuer, customer, fiscal)
     * - Fiscal verification fields (Verifactu, TicketBAI)
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // v0.4.0: Make user_id nullable (legacy, replaced by customer_id)
            $table->unsignedBigInteger('user_id')->nullable()->change();

            // v0.4.0: Customer reference (new billable entity)
            $table->unsignedBigInteger('customer_id')->nullable()->after('user_id')
                ->comment('FK a customers - Entidad facturable (reemplaza user_id en v0.4.0)');

            // v0.4.0: Encrypted snapshots (immutable fiscal data)
            $table->text('issuer_snapshot')->nullable()->after('user_tax_info_encrypted')
                ->comment('ENCRYPTED JSON: Snapshot del emisor en el momento de emisión');

            $table->text('customer_snapshot')->nullable()->after('issuer_snapshot')
                ->comment('ENCRYPTED JSON: Snapshot del cliente en el momento de emisión');

            $table->text('fiscal_snapshot')->nullable()->after('customer_snapshot')
                ->comment('ENCRYPTED JSON: Snapshot del contexto fiscal (ROI, OSS, thresholds, etc.)');

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
            $table->index('customer_id');
            $table->index('fiscal_verification_id');
            $table->index('fiscal_verified_at');

            // Foreign keys
            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Drop foreign keys first
            $table->dropForeign(['customer_id']);

            // Drop indexes
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['fiscal_verification_id']);
            $table->dropIndex(['fiscal_verified_at']);

            // Drop columns
            $table->dropColumn([
                'customer_id',
                'issuer_snapshot',
                'customer_snapshot',
                'fiscal_snapshot',
                'fiscal_verification_id',
                'fiscal_verification_qr',
                'fiscal_verification_hash',
                'fiscal_verified_at',
                'fiscal_verification_metadata',
            ]);
        });
    }
};
