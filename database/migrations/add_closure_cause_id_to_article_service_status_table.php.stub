<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-015 slice A (AID-949) — ADD `closure_cause_id` to `article_service_status`.
 *
 * Nullable FK to `agreement_causes` (previous migration): it records WHICH
 * seeded cause closed or suspended the agreement (ADR-015 §2.2). NULL is a
 * legal state — cause is OPTIONAL on closed agreements (import requirement:
 * the narrative lives in the consumer's order notes, never invented by the
 * package) — and only meaningful while `status` is `closed` or `suspended`.
 *
 * ADDITIVE ONLY: added, NOT backfilled — the §4.2 mapping is the slice-B
 * migration's program (it owns the status re-key together with the
 * close/suspend API, atomic w.r.t. suite greenness), and it sets the cause
 * only where the data establishes one (`EXPIRED` → `fulfilment`; every other
 * legacy row stays cause-NULL because the data cannot prove a cause). Until
 * then nothing existing reads or writes this column.
 *
 * FK note: delete-restrict on purpose. Causes retire via `is_active = false`
 * — silently nulling recorded causes (nullOnDelete) would erase facts.
 *
 * SCOPE WARNING: the FK proves EXISTENCE, never scope — a suspension cause
 * can be written here by any raw write. The closed/suspended scope invariant
 * is application-enforced (`AgreementCause::findActive()`, Eloquent model
 * events) and Eloquent bulk `query()->update()` bypasses model events: the
 * limit is declared in ADR-015 §2.2 and pinned by tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->foreignId('closure_cause_id')
                ->nullable()
                ->comment('Cause vocabulary FK (agreement_causes); only meaningful for closed/suspended, NULL is legal (ADR-015 §2.2).')
                ->constrained('agreement_causes');
        });
    }

    /**
     * Reverse the migrations. `dropConstrainedForeignId` drops the FK, its
     * backing index and the column in one statement (house pattern of
     * `add_article_id_to_invoice_items_table`).
     */
    public function down(): void
    {
        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('closure_cause_id');
        });
    }
};
