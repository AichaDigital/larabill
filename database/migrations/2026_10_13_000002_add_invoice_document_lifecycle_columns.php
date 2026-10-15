<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-016 §8.1 (PR-1 of AID-1627) — column changes on `invoices` for the
     * document axis and the submission axis.
     *
     * - ADD `invoice_document_status` / `fiscal_submission_status`: NULLABLE
     *   unsigned tinyints (round 1, V1 — NULLABLE is what makes proforma rows
     *   possible under the spec's own axis invariant).
     * - ADD `document_cancelled_at` / `document_cancelled_by`: the §10 audit
     *   trail, written by ANY transition that cancels the document axis.
     * - MODIFY the five number/issuance fields to NULLABLE: they exist only on
     *   ISSUED rows from now on (§8.2 C4) — an unissued row cannot falsify
     *   them anymore.
     * - MODIFY `issued_at` to DROP its `DEFAULT CURRENT_TIMESTAMP`: that
     *   default would stamp every unnumbered draft with a false issuance
     *   instant (§8.1).
     *
     * Every operation is introspection-guarded so a partially-applied failure
     * re-runs cleanly (v7 §7 execution model). The named MySQL CHECK over the
     * whole clause set ships with the BACKFILL migration (it must land over
     * healed data, and MySQL does not validate existing rows when adding a
     * CHECK).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'invoice_document_status')) {
                $table->unsignedTinyInteger('invoice_document_status')->nullable()->comment(
                    'InvoiceDocumentStatus enum (ADR-016 §5.1): 0=draft, 1=prepared, 2=issued, 3=cancelled. NULL on proforma rows'
                );
            }

            if (! Schema::hasColumn('invoices', 'fiscal_submission_status')) {
                $table->unsignedTinyInteger('fiscal_submission_status')->nullable()->comment(
                    'FiscalSubmissionStatus enum (ADR-016 §5.3): 0=not_required, 1=pending, 2=registered, 3=action_required, 4=content_rejected, 5=legacy_unknown. Set at issuance only'
                );
            }

            if (! Schema::hasColumn('invoices', 'document_cancelled_at')) {
                $table->timestamp('document_cancelled_at')->nullable()->comment(
                    'ADR-016 §8.1: instant the document axis was cancelled (audit trail)'
                );
            }

            if (! Schema::hasColumn('invoices', 'document_cancelled_by')) {
                $table->string('document_cancelled_by')->nullable()->comment(
                    'ADR-016 §8.1: actor of the document cancellation (NULL on direct-draft cancellations)'
                );
            }
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('fiscal_number')->nullable()->comment('Complete fiscal number for display: FAC-2025-000047')->change();
            $table->unsignedBigInteger('series_number')->nullable()->comment('Pure correlative number: 1, 2, 3... for fiscal validation & accounting APIs')->change();
            $table->year('fiscal_year')->nullable()->comment('Fiscal year (calculated from config fiscal_year_start)')->change();
            $table->date('invoice_date')->nullable()->comment('Legal invoice date (appears on PDF). For proforma→invoice: conversion date')->change();
            // The useCurrent() default dies with the nullability flip: an
            // unnumbered draft must not carry a false issuance instant (§8.1).
            $table->timestamp('issued_at')->nullable()->default(null)->comment('Technical immutable timestamp for chronological validation (issued_at_N > issued_at_N-1)')->change();
        });
    }

    /**
     * Drop the added lifecycle columns. The nullability relaxation of the
     * five number/issuance fields is deliberately NOT reverted: rows created
     * since (unnumbered drafts, PR-2) would violate the restored NOT NULL.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'invoice_document_status',
                'fiscal_submission_status',
                'document_cancelled_at',
                'document_cancelled_by',
            ]);
        });
    }
};
