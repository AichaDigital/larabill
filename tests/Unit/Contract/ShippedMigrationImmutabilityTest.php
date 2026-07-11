<?php

declare(strict_types=1);

/*
 * Shipped-migration immutability gate (AID-412 / AID-398 "stable versions =
 * respect for data").
 *
 * tests/Contract/release-migration-manifest.json records every package
 * migration shipped in the latest release (name + sha256). Consumers already
 * ran those migrations BY NAME — renaming, editing or deleting one is a
 * silent break for every installed database, even if the suite stays green
 * (a renamed migration would re-run; an edited one diverges dev from prod).
 *
 * Any schema change ships as a NEW migration that treats existing data
 * (canonical example: the AID-390 backfill). The manifest is regenerated only
 * at release time via `bin/sync-upgrade-manifest` (bin/tag-release preflight),
 * which itself refuses to regenerate over a deleted/edited shipped migration.
 *
 * The `.php.stub` twins are covered transitively: MigrationOrderConsistencyTest
 * (ADR-007) already enforces byte-identity between each `.php` and its stub.
 */

/** @return array{release: string, upgrade_base: string, migrations: array<int, array{name: string, sha256: string, in_base: bool}>} */
function larabillReleaseManifest(): array
{
    $path = dirname(__DIR__, 2).'/Contract/release-migration-manifest.json';

    /** @var array{release: string, upgrade_base: string, migrations: array<int, array{name: string, sha256: string, in_base: bool}>} */
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

// Manifest entries allowed to diverge from HEAD. MUST stay empty: a shipped
// migration is immutable by policy (AID-398) — never add an entry here to
// silence a failure; ship the change as a NEW migration instead.
const LARABILL_KNOWN_SHIPPED_MIGRATION_DIVERGENCES = [];

it('has a well-formed release manifest consistent with the CHANGELOG', function () {
    $manifest = larabillReleaseManifest();

    expect($manifest['migrations'])->not->toBeEmpty()
        ->and($manifest['release'])->toMatch('/^\d+\.\d+\.\d+$/')
        ->and($manifest['upgrade_base'])->toMatch('/^\d+\.\d+\.\d+$/');

    $changelog = (string) file_get_contents(dirname(__DIR__, 3).'/CHANGELOG.md');

    expect(str_contains($changelog, "## [{$manifest['release']}]"))->toBeTrue(
        "Manifest release {$manifest['release']} has no `## [{$manifest['release']}]` heading in CHANGELOG.md — "
        .'regenerate it with `bin/sync-upgrade-manifest`.'
    );
});

it('keeps every shipped migration present and byte-identical (name + sha256)', function () {
    $migrationsDir = dirname(__DIR__, 3).'/database/migrations';
    $violations    = [];

    foreach (larabillReleaseManifest()['migrations'] as $entry) {
        if (in_array($entry['name'], LARABILL_KNOWN_SHIPPED_MIGRATION_DIVERGENCES, true)) {
            continue; // must stay empty — see the constant's note
        }

        $file = "{$migrationsDir}/{$entry['name']}";

        if (! is_file($file)) {
            $violations[] = "{$entry['name']} (deleted or renamed)";

            continue;
        }

        if (hash_file('sha256', $file) !== $entry['sha256']) {
            $violations[] = "{$entry['name']} (content changed)";
        }
    }

    expect($violations)->toBe(
        [],
        'Shipped migrations are IMMUTABLE — consumers already ran them by name (AID-398). '
        .'Restore the original file and ship the change as a NEW data-aware migration instead: '
        .implode(', ', $violations)
    );
});
