<?php

declare(strict_types=1);

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/Mysql/ — no per-file uses() needed.

use AichaDigital\Larabill\Models\UserTaxProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AID-1301 — same-day profile changes relink every user, on a real engine.
 *
 * Which closed profile won the `valid_until` tie was decided by the engine's
 * traversal, so the SQLite regression alone proves nothing about MySQL or
 * MariaDB (lesson AID-836). This pins the sequence of the ticket against the
 * engines CI runs: MySQL 9 and MariaDB 11.4 with both drivers.
 *
 * The harness creates a minimal consumer `users` table without
 * `current_tax_profile_id`. The column is a consumer contract
 * (SCHEMA_REQUIREMENTS.md, ADR-004 section), so the test adds it here, in the
 * open, instead of widening the shared harness.
 */
describe('AID-1301 — same-day relink (MySQL/MariaDB)', function () {

    it('moves every linked user to the active profile after two changes on the same day', function () {
        $this->bootstrap();

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_tax_profile_id')->nullable();
        });

        $this->travelTo(Carbon::create(2026, 9, 14, 12, 0, 0));

        $owner    = $this->seedUser();
        $delegate = $this->seedUser();

        $pointer = fn (string $id): ?int => ($value = DB::table('users')->where('id', $id)->value('current_tax_profile_id')) === null
            ? null
            : (int) $value;

        $p0 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P0', 'valid_from' => now()->subMonth()]);
        DB::table('users')->whereIn('id', [$owner, $delegate])->update(['current_tax_profile_id' => $p0->id]);

        $p1 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P1', 'valid_from' => now()]);
        expect($pointer($delegate))->toBe($p1->id);

        $p2 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P2', 'valid_from' => now()]);

        expect($p0->fresh()->valid_until->toDateString())->toBe($p1->fresh()->valid_until->toDateString())
            ->and($pointer($owner))->toBe($p2->id)
            ->and($pointer($delegate))->toBe($p2->id);
    });

});
