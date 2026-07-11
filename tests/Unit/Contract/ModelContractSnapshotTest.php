<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticlePrice;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\EuSalesThreshold;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Tests\Support\Contract\ModelContractSerializer;
use Illuminate\Database\Eloquent\Model;

/*
 * Contract snapshot (golden master) for the public surface of the contract
 * models — AID-412, consumer↔package boundary spec §4.2 (AID-407).
 *
 * Consumers read these models' columns, casts, relations and scopes directly
 * (yellow band) and call their fiscal operations behind app-side ports (amber
 * band). Any change to that surface is a semver event the consumer must be
 * able to see. This test fails CI when the surface changes without touching
 * the committed snapshots, turning "semver" from prose into a gate.
 *
 * Legitimate contract change? The ONLY sanctioned path is:
 *
 *   bin/sync-contract-snapshots
 *
 * which regenerates the snapshots and refuses to proceed while the CHANGELOG
 * `## [Unreleased]` section is empty (added surface → at least minor; removed
 * or changed signatures → major). Regeneration is forbidden in CI on purpose.
 *
 * Known limitation (accepted): hand-editing the committed JSON to mirror a
 * code change bypasses the CHANGELOG gate. The golden master protects against
 * ACCIDENTAL drift, not deliberate tampering — the PR diff still exposes it.
 */

/** @return array<int, class-string<Model>> */
function larabillContractModels(): array
{
    return [
        Article::class,
        ArticlePrice::class,
        CompanyFiscalConfig::class,
        EuSalesThreshold::class,
        Invoice::class,
        InvoiceSeriesControl::class,
        UserTaxProfile::class,
    ];
}

function larabillContractSnapshotDir(): string
{
    return dirname(__DIR__, 2).'/Contract/snapshots';
}

function larabillContractRegenRequested(): bool
{
    return getenv('LARABILL_REGENERATE_CONTRACT') === '1';
}

it('never regenerates contract snapshots in CI', function () {
    $regenInCi = larabillContractRegenRequested() && getenv('CI') !== false;

    expect($regenInCi)->toBeFalse(
        'Contract snapshot regeneration (LARABILL_REGENERATE_CONTRACT=1) is forbidden in CI. '
        .'Snapshots are regenerated locally via bin/sync-contract-snapshots, reviewed in the PR diff, '
        .'and documented in the CHANGELOG — never "fixed" by the pipeline.'
    );
});

it('matches the committed contract snapshot for every contract model', function () {
    $serializer = new ModelContractSerializer;
    $dir        = larabillContractSnapshotDir();

    if (larabillContractRegenRequested() && getenv('CI') === false) {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach (larabillContractModels() as $model) {
            file_put_contents($dir.'/'.class_basename($model).'.json', $serializer->toJson($model));
        }
    }

    $missing = [];
    $drift   = [];

    foreach (larabillContractModels() as $model) {
        $name = class_basename($model);
        $file = "{$dir}/{$name}.json";

        if (! file_exists($file)) {
            $missing[] = $name;

            continue;
        }

        if (file_get_contents($file) !== $serializer->toJson($model)) {
            $drift[] = $name;
        }
    }

    expect($missing)->toBe(
        [],
        'These contract models have no committed snapshot in tests/Contract/snapshots/ — '
        .'run `bin/sync-contract-snapshots` and commit the result: '.implode(', ', $missing)
    );

    expect($drift)->toBe(
        [],
        'The public contract surface of these models changed (columns/casts/relations/scopes/methods): '
        .implode(', ', $drift).'. If the change is intentional, run `bin/sync-contract-snapshots`, '
        .'review `git diff tests/Contract/snapshots/`, add a CHANGELOG entry under [Unreleased] '
        .'(additions → minor at least; removals or signature changes → MAJOR), and commit the '
        .'snapshots together with the code. Silent contract drift is exactly what this gate forbids.'
    );
});

it('has no orphan snapshot files for models outside the contract list', function () {
    $known = array_map(
        static fn (string $model): string => class_basename($model).'.json',
        larabillContractModels(),
    );

    $orphans = [];

    foreach (glob(larabillContractSnapshotDir().'/*.json') ?: [] as $file) {
        if (! in_array(basename($file), $known, true)) {
            $orphans[] = basename($file);
        }
    }

    expect($orphans)->toBe(
        [],
        'These snapshot files do not correspond to any contract model (remove them, or add '
        .'the model to larabillContractModels()): '.implode(', ', $orphans)
    );
});
