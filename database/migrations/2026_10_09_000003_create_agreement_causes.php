<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-015 slice A (AID-949) — create the `agreement_causes` cause vocabulary.
 *
 * The closure/suspension causes are TABLE-BACKED, not an enum (ADR-015 §2.2):
 * new causes are grown by row with no code change, and each cause belongs to
 * exactly one scope — `closed` or `suspended`. The `scope` column prevents
 * assigning a suspension cause to a closure and vice versa at APPLICATION
 * level only: the FK on `article_service_status.closure_cause_id` (next
 * migration) proves existence, not scope, and Eloquent bulk `query()->update()`
 * bypasses model events anyway. The invariant is guarded by
 * `AgreementCause::findActive()` and pinned by tests; it is deliberately NOT
 * database-enforced (a cross-table CHECK is not expressible here).
 *
 * Seeds (§2.2 + §4.2 rationale), inserted idempotently by `key` so a re-run
 * of the migration on a partially seeded database converges instead of
 * colliding on the unique index:
 *
 * - `closed`: `unpaid_termination`, `client_request`, `fulfilment`, `fraud`,
 *   `provider_rescission`, `legacy_cancellation`. The last one is the seeded
 *   SHIM cause: a 6.x `cancel()` forwarding through the deprecated API records
 *   it (gate round 6 P1) with the original `CancellationType` preserved in
 *   metadata — it is never an invented commercial cause.
 * - `suspended`: `unpaid`, `hacking`, `other`.
 *
 * RESERVED WORD NOTE: `key` is a MySQL reserved word. The column is declared
 * and queried through the query builder / Eloquent only, which quote every
 * identifier (`where('key', ...)` emits `` `key` ``), so it is safe — but raw
 * concatenated SQL against this table MUST quote it manually.
 *
 * Retirement is `is_active = false`, never deletion: history rows and closure
 * metadata may keep pointing at a retired cause. Cause is OPTIONAL on closed
 * agreements (import requirement) — `closed` + NULL cause is a legal state.
 *
 * ADDITIVE ONLY: nothing existing reads this table until the slice-B
 * lifecycle migration owns the §4.2 status program; the `status` column stays
 * legacy (raw int 0-4 of `ServiceStatus`).
 */
return new class extends Migration
{
    /**
     * The §2.2 seed vocabulary: scope => [key => label].
     */
    private const SEEDS = [
        'closed' => [
            'unpaid_termination'  => 'Unpaid termination',
            'client_request'      => 'Client request',
            'fulfilment'          => 'Fulfilment',
            'fraud'               => 'Fraud',
            'provider_rescission' => 'Provider rescission',
            'legacy_cancellation' => 'Legacy cancellation (6.x shim)',
        ],
        'suspended' => [
            'unpaid'  => 'Unpaid',
            'hacking' => 'Hacking',
            'other'   => 'Other',
        ],
    ];

    public function up(): void
    {
        Schema::create('agreement_causes', function (Blueprint $table): void {
            $table->id();

            $table->string('scope', 12)
                ->comment("Cause scope: 'closed' | 'suspended' (ADR-015 §2.2). Application-enforced.");

            $table->string('key', 32)
                ->unique()
                ->comment('Stable cause key (MySQL reserved word — always access through the quoted query builder).');

            $table->string('label', 120)
                ->comment('Human-readable display label for the cause.');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Inactive causes remain resolvable in history but are rejected for new assignments.');

            $table->timestamps();
        });

        $this->seedCauses();
    }

    /**
     * Idempotent seeding by `key`: updateOrInsert converges on re-runs and on
     * databases where a consumer already created some causes by hand, without
     * ever colliding on the unique index.
     */
    private function seedCauses(): void
    {
        foreach (self::SEEDS as $scope => $causes) {
            foreach ($causes as $key => $label) {
                DB::table('agreement_causes')->updateOrInsert(
                    ['key' => $key],
                    [
                        'scope'      => $scope,
                        'label'      => $label,
                        'is_active'  => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    /**
     * Reverse the migrations. The seeds are table data — they go with the
     * table. Reversing is only safe while no `article_service_status` row
     * references a cause (the slice-B column holds an FK): the FK itself
     * makes the drop fail loudly rather than orphan facts, which is the
     * behaviour we want.
     */
    public function down(): void
    {
        Schema::dropIfExists('agreement_causes');
    }
};
