<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ADR-016 §8.3 + §8.2 (PR-1 of AID-1627) — backfill of the document axis
     * over the legacy rows, then the named MySQL CHECK.
     *
     * Precedence (§8.3):
     * 1. Proforma rows (`serie = PROFORMA`) are UNTOUCHED: their document axis
     *    stays NULL (they keep their five number/issuance fields NOT NULL —
     *    birth numbering on the PRO serie is preserved, §3.1).
     * 2. Every numbered fiscal row (all five fields non-NULL — which is every
     *    fiscal row today) → `invoice_document_status = ISSUED` +
     *    `fiscal_submission_status = LEGACY_UNKNOWN`. There is no data signal
     *    separating "issued" from "numbered draft" (§8.3); the preflight
     *    migration reported the operator-visible populations. Rows whose
     *    legacy `status = CANCELLED` keep the legacy status on the collection
     *    axis; the document axis records the historical truth (the number WAS
     *    consumed) as ISSUED.
     * 3. Legacy `status = PENDING` fiscal rows (round 2, B5 — conversion
     *    births them): the document axis classifies them ISSUED like every
     *    numbered row, and their collection `status` maps to `DRAFT` — the
     *    truthful collection value, since PENDING encoded the collapsed
     *    conversion/issuance lifecycle that dies with this major.
     *
     * Idempotent by construction: the UPDATE re-classifies from the observable
     * state (only rows still carrying a NULL document axis are legacy-shaped).
     *
     * The named CHECK (`larabill_invoice_document_axis_coherence`, clause set
     * amended by V1/V9/V10/V11/V12/V13/V17/S4-r/S5) is added AFTER the data is
     * coherent, on MySQL only — SQLite cannot ALTER-add a CHECK, so the
     * application enforcer shipped in the same PR is its only enforcement
     * there (§8.2 engine split; per-engine DDL forks are exactly the drift
     * ADR-007 forbids).
     */
    public function up(): void
    {
        // Each data migration re-validates its preconditions (§8 house rule):
        // a fiscal row without its number AND without a document axis cannot
        // be classified (the preflight fails loud on this case; this guard
        // makes the backfill safe even if it is ever re-run standalone).
        $unclassifiable = DB::table('invoices')
            ->where('serie', '!=', 0)
            ->whereNull('invoice_document_status')
            ->where(function ($query): void {
                $query->whereNull('fiscal_number')
                    ->orWhereNull('series_number')
                    ->orWhereNull('fiscal_year')
                    ->orWhereNull('invoice_date')
                    ->orWhereNull('issued_at');
            })
            ->count();

        if ($unclassifiable > 0) {
            throw new RuntimeException(sprintf(
                'larabill[ADR-016 backfill]: %d fiscal row(s) are unnumbered AND unclassified — the backfill cannot heal them (§8.3 case 3).',
                $unclassifiable,
            ));
        }

        DB::transaction(function (): void {
            // §8.3 precedence 2 + 4: every legacy fiscal row → ISSUED +
            // LEGACY_UNKNOWN. WHERE invoice_document_status IS NULL is what
            // makes this idempotent (re-classifies from observable state).
            DB::table('invoices')
                ->where('serie', '!=', 0)
                ->whereNull('invoice_document_status')
                ->update([
                    'invoice_document_status'  => 2, // InvoiceDocumentStatus::ISSUED
                    'fiscal_submission_status' => 5, // FiscalSubmissionStatus::LEGACY_UNKNOWN
                ]);

            // §8.3 precedence 2 carve-out (B5): legacy PENDING fiscal rows map
            // their COLLECTION status to DRAFT. Scoped to already-classified
            // rows, so a re-run finds nothing.
            DB::table('invoices')
                ->where('serie', '!=', 0)
                ->where('invoice_document_status', 2)
                ->where('status', 5) // InvoiceStatus::PENDING
                ->update(['status' => 0]); // InvoiceStatus::DRAFT
        });

        $this->addCoherenceCheck();
    }

    /**
     * Drop the named CHECK (the added columns are owned by the schema
     * migration; the backfilled values are left in place).
     */
    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) && $this->coherenceCheckExists()) {
            DB::statement('ALTER TABLE `invoices` DROP CHECK `larabill_invoice_document_axis_coherence`');
        }
    }

    private function addCoherenceCheck(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return; // SQLite: the application enforcer is the only enforcement (§8.2)
        }

        if ($this->coherenceCheckExists()) {
            return; // introspection guard: a partially-applied failure re-runs cleanly
        }

        // One named constraint holding the WHOLE §8.2 clause set (the truth
        // table is the contract; every clause must be TRUE for a row to pass).
        // MySQL/MariaDB do not validate existing rows when adding a CHECK —
        // that is why it lands here, after the backfill healed the data.
        //
        // The hand-written integers mirror the enum backing values pinned by
        // InvoiceDocumentStatus::checkDomainValues() and
        // FiscalSubmissionStatus::checkDomainValues() (test-guarded).
        DB::statement(<<<'SQL'
            ALTER TABLE `invoices` ADD CONSTRAINT `larabill_invoice_document_axis_coherence` CHECK (
                (`invoice_document_status` IS NULL OR `invoice_document_status` IN (0, 1, 2, 3))
                AND (`serie` IN (0, 1, 2, 3))
                AND ((`serie` = 0) = (`invoice_document_status` IS NULL))
                AND ((`serie` != 0) OR (`fiscal_number` IS NOT NULL AND `series_number` IS NOT NULL AND `fiscal_year` IS NOT NULL AND `invoice_date` IS NOT NULL AND `issued_at` IS NOT NULL))
                AND ((`invoice_document_status` IS NULL) OR (
                    ((`invoice_document_status` = 2) = (`fiscal_number` IS NOT NULL AND `series_number` IS NOT NULL AND `fiscal_year` IS NOT NULL AND `invoice_date` IS NOT NULL AND `issued_at` IS NOT NULL))
                    AND ((`invoice_document_status` IN (0, 1, 3)) = (`fiscal_number` IS NULL AND `series_number` IS NULL AND `fiscal_year` IS NULL AND `invoice_date` IS NULL AND `issued_at` IS NULL))
                    AND ((`invoice_document_status` != 3) OR (`document_cancelled_at` IS NOT NULL))
                ))
                AND ((`invoice_document_status` IS NULL) OR ((`invoice_document_status` = 2) = (`fiscal_submission_status` IS NOT NULL)))
                AND ((`fiscal_submission_status` IS NULL) OR (`fiscal_submission_status` IN (0, 1, 2, 3, 4, 5)))
                AND ((`invoice_document_status` IS NOT NULL) OR (`fiscal_submission_status` IS NULL AND `document_cancelled_at` IS NULL AND `document_cancelled_by` IS NULL))
                AND ((`document_cancelled_by` IS NULL) OR (`document_cancelled_at` IS NOT NULL))
                AND ((`document_cancelled_at` IS NULL) OR (`invoice_document_status` = 3))
            )
            SQL);
    }

    private function coherenceCheckExists(): bool
    {
        $result = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            ['invoices', 'larabill_invoice_document_axis_coherence', 'CHECK'],
        );

        return ((int) ($result->aggregate ?? 0)) > 0;
    }
};
