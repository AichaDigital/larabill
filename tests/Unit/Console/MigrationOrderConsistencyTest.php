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

/** Normalize a migration file for structural comparison (ignore strict_types + blank lines + trailing ws). */
function larabillNormalizeMigration(string $path): string
{
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $kept  = [];
    foreach ($lines as $line) {
        $trimmed = rtrim($line);
        if (trim($trimmed) === '') {
            continue;
        }
        if (str_contains($trimmed, 'declare(strict_types')) {
            continue;
        }
        $kept[] = $trimmed;
    }

    return implode("\n", $kept);
}

// Stubs that intentionally modify the CONSUMER's users table: they have a `.php.stub`
// but no timestamped `.php`, and are NOT part of $migrationOrder.
const LARABILL_CONSUMER_ONLY_STUBS = [
    'add_user_relationships_to_users_table',
    'rename_user_id_to_owner_user_id_in_user_tax_profiles',
];

// Tables whose `.php` (dev) and `.php.stub` (prod) are KNOWN to diverge structurally.
// This is pre-existing systemic debt scheduled for reconciliation in a dedicated ADR.
// DO NOT add new entries here to silence drift — fix the migration instead.
// Removing an entry (after reconciling its schemas) is the desired direction.
const LARABILL_KNOWN_SCHEMA_DIVERGENCES = [
    'create_invoices_table',
    'create_invoice_items_table',
    'create_tax_rates_table',
    'create_article_prices_table',
    'create_company_fiscal_configs_table',
    'create_company_template_settings_table',
];

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

it('keeps each table .php and .php.stub structurally in sync (no NEW drift)', function () {
    $newDrift = [];

    foreach (larabillMigrationOrder() as $name) {
        $stub = larabillMigrationsDir()."/{$name}.php.stub";
        $php  = larabillTimestampedPhp($name);

        if ($php === null || ! file_exists($stub)) {
            continue; // existence is covered by the other tests
        }

        $diverges = larabillNormalizeMigration($php) !== larabillNormalizeMigration($stub);
        $known    = in_array($name, LARABILL_KNOWN_SCHEMA_DIVERGENCES, true);

        if ($diverges && ! $known) {
            $newDrift[] = $name;
        }
    }

    expect($newDrift)->toBe(
        [],
        'New .php vs .php.stub schema drift detected (dev tests would validate a schema the installer does not ship): '.implode(', ', $newDrift)
    );
});
