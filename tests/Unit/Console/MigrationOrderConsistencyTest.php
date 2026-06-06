<?php

declare(strict_types=1);

use AichaDigital\Larabill\Console\LarabillInstallCommand;

/*
 * Guardrail for the package migration contract (see CONTRIBUTING.md / AGENTS.md).
 *
 * Every package table is shipped as BOTH a timestamped `.php` (auto-loaded in
 * dev/testbench) AND a `.php.stub` (published to consumers by `larabill:install`).
 * `LarabillInstallCommand::$migrationOrder` must reference one dedicated `.php.stub`
 * per entry — relying on the install command's timestamped-`.php` fallback is NOT
 * a valid substitute (it silently masks a missing stub, which is how v0.8.2 shipped
 * an order entry without its stub).
 *
 * This test would have failed that change in CI. It also detects NEW structural
 * drift between a table's `.php` and its `.php.stub` (what dev tests vs. what prod
 * installs), guarding against the systemic divergence documented for reconciliation.
 */

/** @return array<int,string> migration base names, in order */
function larabillMigrationOrder(): array
{
    $command = new LarabillInstallCommand;
    $ref     = new ReflectionProperty($command, 'migrationOrder');
    $ref->setAccessible(true);

    return array_values($ref->getValue($command));
}

function larabillMigrationsDir(): string
{
    return dirname(__DIR__, 3).'/database/migrations';
}

/**
 * Locate the timestamped `.php` for a migration base name (date prefix varies:
 * some use 6-digit sequences, a few legacy ones use 4-digit).
 */
function larabillTimestampedPhp(string $name): ?string
{
    $matches = glob(larabillMigrationsDir()."/[0-9]*_{$name}.php") ?: [];

    return $matches[0] ?? null;
}

// Stubs that intentionally modify the CONSUMER's users table: they have a `.php.stub`
// but no timestamped `.php`, and are NOT part of $migrationOrder.
const LARABILL_CONSUMER_ONLY_STUBS = [
    'add_user_relationships_to_users_table',
    'rename_user_id_to_owner_user_id_in_user_tax_profiles',
];

// Tables whose `.php` (dev) and `.php.stub` (prod) are allowed to diverge.
// MUST stay empty: per ADR-007 the `.php.stub` is a DERIVED ARTIFACT of its `.php`
// (byte-for-byte identical). Never add an entry here to silence drift — run
// `bin/sync-migration-stubs` to regenerate the stub from its `.php` instead.
const LARABILL_KNOWN_SCHEMA_DIVERGENCES = [];

it('has a dedicated .php.stub for every $migrationOrder entry', function () {
    $dir     = larabillMigrationsDir();
    $missing = [];

    foreach (larabillMigrationOrder() as $name) {
        if (! file_exists("{$dir}/{$name}.php.stub")) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe(
        [],
        'These $migrationOrder entries have no dedicated .php.stub (the install command would fall back to the timestamped .php, masking the gap): '.implode(', ', $missing)
    );
});

it('references every package .php.stub in $migrationOrder (except consumer-only stubs)', function () {
    $order   = larabillMigrationOrder();
    $orphans = [];

    foreach (glob(larabillMigrationsDir().'/*.php.stub') ?: [] as $stub) {
        $name = basename($stub, '.php.stub');
        if (in_array($name, LARABILL_CONSUMER_ONLY_STUBS, true)) {
            continue;
        }
        if (! in_array($name, $order, true)) {
            $orphans[] = $name;
        }
    }

    expect($orphans)->toBe(
        [],
        'These .php.stub files are not referenced in $migrationOrder (add them, or list them as consumer-only): '.implode(', ', $orphans)
    );
});

it('keeps each table .php.stub byte-identical to its .php (ADR-007 — run bin/sync-migration-stubs)', function () {
    $drift = [];

    foreach (larabillMigrationOrder() as $name) {
        $stub = larabillMigrationsDir()."/{$name}.php.stub";
        $php  = larabillTimestampedPhp($name);

        if ($php === null || ! file_exists($stub)) {
            continue; // existence is covered by the other tests
        }

        if (in_array($name, LARABILL_KNOWN_SCHEMA_DIVERGENCES, true)) {
            continue; // must stay empty — see the constant's note
        }

        // The `.php.stub` is a derived artifact of its timestamped `.php` (ADR-007):
        // it must be byte-for-byte identical. dev/tests run the `.php`; consumers
        // install the `.stub`. Byte equality makes that distinction impossible to drift.
        if (file_get_contents($php) !== file_get_contents($stub)) {
            $drift[] = $name;
        }
    }

    expect($drift)->toBe(
        [],
        'These .php.stub files are not byte-identical to their .php source. The stub is a '
        .'derived artifact (ADR-007); run `bin/sync-migration-stubs` to regenerate it: '.implode(', ', $drift)
    );
});
