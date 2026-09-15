<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Tests\Models\SeparateConnectionUser;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AID-1301 — the `created` hook relinks every user of a closed profile.
 *
 * `valid_until` is a `date` column and closeActiveForOwner() stamps it as the
 * NEW profile's `valid_from - 1 day`. Two successive creations whose new
 * profiles take effect on the same date therefore close their respective
 * predecessors with the same `valid_until`. The hook used to pick ONE
 * "previous" profile ordering by `valid_until` alone, letting the engine break
 * the tie: users linked to the owner's profile were left on a closed one.
 * Spec: docs/superpowers/specs/2026-09-14-aid-1301-tax-profile-relink-tiebreak.md
 *
 * Pointers are read and seeded through the query builder on purpose: the
 * package never owns the consumer's `current_tax_profile_id`, and a model
 * write would add events these tests must not depend on.
 */
describe('UserTaxProfile created hook — relink (AID-1301)', function () {
    beforeEach(function () {
        // A fixed time of day, far from midnight: the whole defect is about
        // two creations taking effect on the SAME date.
        $this->travelTo(Carbon::create(2026, 9, 14, 12, 0, 0));

        $this->pointer = fn (string $userId): ?int => ($value = DB::table('test_users')->where('id', $userId)->value('current_tax_profile_id')) === null
            ? null
            : (int) $value;

        $this->point = fn (array $userIds, ?int $profileId) => DB::table('test_users')
            ->whereIn('id', $userIds)
            ->update(['current_tax_profile_id' => $profileId]);

        $this->closedProfile = fn (string $ownerId, string $from, string $until): UserTaxProfile => UserTaxProfile::create([
            'owner_user_id' => $ownerId,
            'fiscal_name'   => 'Closed '.$from,
            'valid_from'    => $from,
            'valid_until'   => $until,
            'is_active'     => false,
        ]);

        // The consumer's users on a connection of their own, whose database has
        // no fiscal tables (round 2 of the adversarial gate measured that the
        // previous recipe broke there). Returns pointer helpers bound to it.
        $this->useSeparateUserConnection = function (): array {
            config()->set('database.connections.larabill_users', [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ]);

            Schema::connection('larabill_users')->create('test_users', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->rememberToken();
                $table->unsignedBigInteger('current_tax_profile_id')->nullable();
                $table->timestamps();
            });

            config()->set('larabill.user_model', SeparateConnectionUser::class);

            $users = fn () => DB::connection('larabill_users')->table('test_users');

            return [
                // create a user, return its id
                fn (): string => SeparateConnectionUser::create([
                    'id'       => (string) Str::orderedUuid(),
                    'name'     => 'Linked',
                    'email'    => Str::random(12).'@example.test',
                    'password' => 'secret',
                ])->getKey(),
                // point users at a profile
                fn (array $userIds, int $profileId) => $users()->whereIn('id', $userIds)->update(['current_tax_profile_id' => $profileId]),
                // read a pointer
                fn (string $userId): ?int => ($value = $users()->where('id', $userId)->value('current_tax_profile_id')) === null
                    ? null
                    : (int) $value,
            ];
        };
    });

    it('moves every linked user to the active profile after two changes taking effect on the same date', function () {
        $owner    = TestCase::USER_UUID_1;
        $delegate = TestCase::USER_UUID_2;

        $p0 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P0', 'valid_from' => now()->subMonth()]);
        ($this->point)([$owner, $delegate], $p0->id);

        $p1 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P1', 'valid_from' => now()]);
        expect(($this->pointer)($delegate))->toBe($p1->id);

        $p2 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P2', 'valid_from' => now()]);

        // Both closed profiles now share valid_until: the tie the old query lost.
        expect($p0->fresh()->valid_until->toDateString())->toBe($p1->fresh()->valid_until->toDateString());

        expect(($this->pointer)($owner))->toBe($p2->id)
            ->and(($this->pointer)($delegate))->toBe($p2->id);
    });

    it('relinks the users of the single previous profile, as it always did', function () {
        $owner    = TestCase::USER_UUID_1;
        $delegate = TestCase::USER_UUID_2;

        $p0 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P0', 'valid_from' => now()->subMonths(2)]);
        ($this->point)([$owner, $delegate], $p0->id);

        $p1 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P1', 'valid_from' => now()->subMonth()]);

        expect(($this->pointer)($owner))->toBe($p1->id)
            ->and(($this->pointer)($delegate))->toBe($p1->id);
    });

    it('never touches a user pointing at a closed profile of another owner', function () {
        $ownerA   = TestCase::USER_UUID_1;
        $ownerB   = TestCase::USER_UUID_2;
        $outsider = TestCase::USER_UUID_3;

        $foreignClosed = ($this->closedProfile)($ownerB, '2026-01-01', '2026-06-30');
        ($this->point)([$outsider], $foreignClosed->id);

        $a0 = UserTaxProfile::createForOwner($ownerA, ['fiscal_name' => 'A0', 'valid_from' => now()->subMonth()]);
        ($this->point)([$ownerA], $a0->id);

        $a1 = UserTaxProfile::createForOwner($ownerA, ['fiscal_name' => 'A1', 'valid_from' => now()]);

        expect(($this->pointer)($ownerA))->toBe($a1->id)
            ->and(($this->pointer)($outsider))->toBe($foreignClosed->id);
    });

    it('repairs a user left on an older closed profile of the owner', function () {
        $owner    = TestCase::USER_UUID_1;
        $stranded = TestCase::USER_UUID_2;

        $p0 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P0', 'valid_from' => now()->subMonths(3)]);
        $p1 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P1', 'valid_from' => now()->subMonth()]);

        // The state the defect leaves behind: a user still on an OLDER closed
        // profile, while the owner already moved on.
        ($this->point)([$owner], $p1->id);
        ($this->point)([$stranded], $p0->id);

        $p2 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P2', 'valid_from' => now()]);

        expect(($this->pointer)($owner))->toBe($p2->id)
            ->and(($this->pointer)($stranded))->toBe($p2->id);
    });

    it('does not touch the users table when the owner has no closed profile yet', function () {
        $owner    = TestCase::USER_UUID_1;
        $delegate = TestCase::USER_UUID_2;

        $userQueries = 0;
        DB::listen(function ($query) use (&$userQueries): void {
            if (str_contains($query->sql, 'test_users')) {
                $userQueries++;
            }
        });

        UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'First', 'valid_from' => now()]);
        $seen = $userQueries;   // before the pointer reads below add their own

        // Before AID-1301 the hook returned early here, so an install whose
        // users table lacks current_tax_profile_id could still create a first
        // profile. The relink must keep that: no users query at all. (The
        // first link of the owner is AID-967, deliberately out of scope.)
        expect($seen)->toBe(0)
            ->and(($this->pointer)($owner))->toBeNull()
            ->and(($this->pointer)($delegate))->toBeNull();
    });

    it('does not relink a user pointing at a closed profile that was soft-deleted', function () {
        $owner    = TestCase::USER_UUID_1;
        $orphaned = TestCase::USER_UUID_2;
        $stranded = TestCase::USER_UUID_3;

        $deleted = ($this->closedProfile)($owner, '2026-01-01', '2026-03-31');
        $deleted->delete();
        $visible = ($this->closedProfile)($owner, '2026-04-01', '2026-06-30');

        ($this->point)([$orphaned], $deleted->id);
        ($this->point)([$stranded], $visible->id);

        $active = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'Active', 'valid_from' => now()]);

        // A visible closed profile sits next to the deleted one ON PURPOSE: with
        // only the deleted one the hook stops before its update and this test
        // could not tell whether the update itself honours the soft-delete
        // scope (adversarial gate, round 1). Declared limit of the fix (spec D1).
        expect(($this->pointer)($stranded))->toBe($active->id)
            ->and(($this->pointer)($orphaned))->toBe($deleted->id);
    });

    it('does not run the relink for a profile created inactive or already closed', function () {
        $owner    = TestCase::USER_UUID_1;
        $delegate = TestCase::USER_UUID_2;

        $closed = ($this->closedProfile)($owner, '2026-01-01', '2026-03-31');
        ($this->point)([$delegate], $closed->id);

        ($this->closedProfile)($owner, '2026-04-01', '2026-06-30');

        expect(($this->pointer)($delegate))->toBe($closed->id);
    });

    it('relinks with ONE mass update that fires no user model events', function () {
        $owner    = TestCase::USER_UUID_1;
        $delegate = TestCase::USER_UUID_2;

        $fired = 0;
        TestUser::updating(function () use (&$fired): void {
            $fired++;
        });

        $p0 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P0', 'valid_from' => now()->subMonth()]);
        ($this->point)([$owner, $delegate], $p0->id);

        $userUpdates = 0;
        DB::listen(function ($query) use (&$userUpdates): void {
            // Unanchored on purpose: a leading SQL comment must not hide a
            // second write (adversarial gate, round 2).
            $userUpdates += preg_match_all('/\bupdate\s+["`]?test_users["`]?/i', $query->sql);
        });

        $p1 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P1', 'valid_from' => now()]);

        // "No events" alone does not prove "one write": a loop of saveQuietly()
        // fires none either and still issues one UPDATE per user (adversarial
        // gate, round 1). Two linked users here, one statement expected.
        expect($userUpdates)->toBe(1)
            ->and($fired)->toBe(0)
            ->and(($this->pointer)($owner))->toBe($p1->id)
            ->and(($this->pointer)($delegate))->toBe($p1->id);
    });

    it('relinks when the user model lives on another connection than the fiscal tables', function () {
        [$user, $point, $pointer] = ($this->useSeparateUserConnection)();

        $owner    = $user();
        $delegate = $user();

        $p0 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P0', 'valid_from' => now()->subMonth()]);
        $point([$owner, $delegate], $p0->id);

        UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P1', 'valid_from' => now()]);
        $p2 = UserTaxProfile::createForOwner($owner, ['fiscal_name' => 'P2', 'valid_from' => now()]);

        // A subquery over user_tax_profiles embedded in the users UPDATE would
        // run on the users connection and die with "no such table".
        expect($pointer($owner))->toBe($p2->id)
            ->and($pointer($delegate))->toBe($p2->id);
    });

    it('runs the published diagnosis and repair recipe exactly as documented', function () {
        // The users live on their own connection: the recipe must not assume
        // they share one with the fiscal tables (adversarial gate, round 2).
        [$user, $point, $pointer] = ($this->useSeparateUserConnection)();

        $ownerA = $user();   // one active profile                  → repairable
        $ownerB = $user();   // no active profile                   → anomaly
        $ownerC = $user();   // two active profiles                 → anomaly
        $ownerD = $user();   // single active, DELETED before repair
        $ownerE = $user();   // single active, REPLACED before repair
        $ownerF = $user();   // single active, JOINED by another before repair

        $repairableUser = $user();
        $movedUser      = $user();
        $noActiveUser   = $user();
        $twoActiveUser  = $user();
        $deletedTarget  = $user();
        $replacedTarget = $user();
        $joinedTarget   = $user();

        $a0    = ($this->closedProfile)($ownerA, '2026-01-01', '2026-03-31');
        $a0bis = ($this->closedProfile)($ownerA, '2026-04-01', '2026-06-30');
        $a1    = UserTaxProfile::createForOwner($ownerA, ['fiscal_name' => 'A1', 'valid_from' => '2026-07-01']);

        $b0 = ($this->closedProfile)($ownerB, '2026-01-01', '2026-06-30');

        // Actives created without events: two at once is only reachable
        // bypassing createForOwner() or through concurrent writes (spec D7).
        $rawActive = fn (string $ownerId, string $name): UserTaxProfile => UserTaxProfile::withoutEvents(
            fn (): UserTaxProfile => UserTaxProfile::create(['owner_user_id' => $ownerId, 'fiscal_name' => $name, 'valid_from' => '2026-07-01', 'is_active' => true]),
        );

        $c0 = ($this->closedProfile)($ownerC, '2026-01-01', '2026-06-30');
        $rawActive($ownerC, 'C1');
        $rawActive($ownerC, 'C2');

        $d0 = ($this->closedProfile)($ownerD, '2026-01-01', '2026-06-30');
        $d1 = UserTaxProfile::createForOwner($ownerD, ['fiscal_name' => 'D1', 'valid_from' => '2026-07-01']);

        $e0 = ($this->closedProfile)($ownerE, '2026-01-01', '2026-06-30');
        $e1 = UserTaxProfile::createForOwner($ownerE, ['fiscal_name' => 'E1', 'valid_from' => '2026-07-01']);

        $f0 = ($this->closedProfile)($ownerF, '2026-01-01', '2026-06-30');
        UserTaxProfile::createForOwner($ownerF, ['fiscal_name' => 'F1', 'valid_from' => '2026-07-01']);

        // Pointers seeded AFTER the profiles, so no hook has repaired them.
        $point([$repairableUser, $movedUser], $a0->id);
        $point([$noActiveUser], $b0->id);
        $point([$twoActiveUser], $c0->id);
        $point([$deletedTarget], $d0->id);
        $point([$replacedTarget], $e0->id);
        $point([$joinedTarget], $f0->id);

        // ---- recipe: diagnosis (verbatim from the CHANGELOG) ----
        $userModel = config('larabill.models.user');
        $userModel = is_string($userModel) && class_exists($userModel)
            ? $userModel
            : config('larabill.user_model');

        $repairable = [];
        $anomalies  = [];

        $userModel::query()
            ->whereNotNull('current_tax_profile_id')
            ->lazyById()
            ->each(function ($user) use (&$repairable, &$anomalies): void {
                $closedProfile = UserTaxProfile::query()
                    ->whereKey($user->current_tax_profile_id)
                    ->whereNotNull('valid_until')
                    ->first();

                if ($closedProfile === null) {
                    return;
                }

                $active = UserTaxProfile::query()
                    ->active()
                    ->forOwner($closedProfile->owner_user_id)
                    ->limit(2)
                    ->pluck('id');

                $row = [$user->getKey(), $closedProfile->getKey(), $closedProfile->owner_user_id, $active->all()];

                if ($active->count() === 1) {
                    $repairable[] = $row;
                } else {
                    $anomalies[] = $row;
                }
            });
        // ---- end of diagnosis ----

        expect(collect($repairable)->pluck(0)->sort()->values()->all())
            ->toBe(collect([$repairableUser, $movedUser, $deletedTarget, $replacedTarget, $joinedTarget])->sort()->values()->all())
            ->and(collect($anomalies)->pluck(0)->sort()->values()->all())
            ->toBe(collect([$noActiveUser, $twoActiveUser])->sort()->values()->all());

        // Between diagnosis and repair (adversarial gate, rounds 1 and 2):
        // - a diagnosed pointer changes                 → the compare-and-set skips it;
        // - D's single active profile is deleted        → no active left;
        // - E's single active is replaced by another    → still one active, a different one;
        // - F's single active is joined by a second one → two actives.
        // A re-check of "not empty" misses E and F; "exactly one" misses E.
        $point([$movedUser], $a0bis->id);
        $d1->delete();
        $e1->delete();
        $rawActive($ownerE, 'E2');
        $rawActive($ownerF, 'F2');

        // ---- recipe: optional repair (verbatim from the CHANGELOG) ----
        foreach ($repairable as [$userId, $closedId, $ownerId, [$activeId]]) {
            $active = UserTaxProfile::query()
                ->active()
                ->forOwner($ownerId)
                ->limit(2)
                ->pluck('id');

            if ($active->all() !== [$activeId]) {
                continue;
            }

            $userModel::query()
                ->whereKey($userId)
                ->where('current_tax_profile_id', $closedId)
                ->update(['current_tax_profile_id' => $activeId]);
        }
        // ---- end of repair ----

        expect($pointer($repairableUser))->toBe($a1->id)
            ->and($pointer($movedUser))->toBe($a0bis->id)
            ->and($pointer($noActiveUser))->toBe($b0->id)
            ->and($pointer($twoActiveUser))->toBe($c0->id)
            ->and($pointer($deletedTarget))->toBe($d0->id)
            ->and($pointer($replacedTarget))->toBe($e0->id)
            ->and($pointer($joinedTarget))->toBe($f0->id);
    });

    it('keeps the recipe published in the CHANGELOG identical to the one executed above', function () {
        // Without this, the test above protects its own copy of the recipe and
        // nothing stops the published text from drifting (adversarial gate,
        // round 1). Comments, blank lines and indentation are ignored; every
        // other line must match, and every `use` in the published block must
        // be an import this file really declares (round 2: an aliased import
        // changes which model the recipe queries).
        $lines = static fn (string $text): array => array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '//'),
        ));

        $between = static function (string $text, string $from, ?string $to): string {
            $start = strpos($text, $from);
            expect($start)->not->toBeFalse("marker not found: {$from}");
            $start = (int) $start + strlen($from);

            if ($to === null) {
                return substr($text, $start);
            }

            $end = strpos($text, $to, $start);
            expect($end)->not->toBeFalse("marker not found: {$to}");

            return substr($text, $start, (int) $end - $start);
        };

        $changelog = (string) file_get_contents(__DIR__.'/../../../CHANGELOG.md');
        $section   = $between($changelog, '### Users left on a closed tax profile', null);
        $nextEntry = strpos($section, "\n## [");
        $section   = $nextEntry === false ? $section : substr($section, 0, $nextEntry);
        $published = $lines($between($section, "```php\n", "\n```"));

        $self     = (string) file_get_contents(__FILE__);
        $executed = $lines(
            $between($self, '// ---- recipe: diagnosis (verbatim from the CHANGELOG) ----', '// ---- end of diagnosis ----')
            ."\n".$between($self, '// ---- recipe: optional repair (verbatim from the CHANGELOG) ----', '// ---- end of repair ----'),
        );

        $imports = array_values(array_filter($published, static fn (string $line): bool => str_starts_with($line, 'use ')));
        $code    = array_values(array_filter($published, static fn (string $line): bool => ! str_starts_with($line, 'use ')));

        expect($imports)->not->toBeEmpty();

        foreach ($imports as $import) {
            expect(preg_match('/^'.preg_quote($import, '/').'$/m', $self))->toBe(1, "published import not declared here: {$import}");
        }

        expect($code)->toBe($executed);
    });
});
