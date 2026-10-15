<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\InvoiceNumberingService;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Support\Contract\ServiceContractSerializer;

/*
 * Service-signature contract snapshot (golden master) — ADR-016 §12, round 1
 * finding V5: the service-signature surface of InvoiceService and
 * InvoiceNumberingService is an explicit deliverable of PR-2, and the existing
 * machinery only snapshotted the seven contract MODELS.
 *
 * Consumers call these services directly (@api): any change to a public
 * method, its parameter types or its return types is a semver event the
 * consumer must be able to see. This test fails when that surface changes
 * without touching the committed snapshots — the same gate the models have
 * (AID-412), extended to the two services the ADR-016 lifecycle touches.
 *
 * Legitimate contract change? The ONLY sanctioned path is
 *
 *   bin/sync-contract-snapshots
 *
 * which regenerates BOTH the model and the service snapshots and refuses to
 * proceed while the CHANGELOG `## [Unreleased]` section is empty.
 */

function larabillContractServices(): array
{
    return [
        InvoiceService::class,
        InvoiceNumberingService::class,
    ];
}

function larabillServiceContractSnapshotDir(): string
{
    return dirname(__DIR__, 2).'/Contract/snapshots';
}

function larabillServiceContractRegenRequested(): bool
{
    return getenv('LARABILL_REGENERATE_CONTRACT') === '1';
}

it('never regenerates service contract snapshots in CI', function () {
    $regenInCi = larabillServiceContractRegenRequested() && getenv('CI') !== false;

    expect($regenInCi)->toBeFalse(
        'Service contract snapshot regeneration (LARABILL_REGENERATE_CONTRACT=1) is forbidden in CI. '
        .'Snapshots are regenerated locally via bin/sync-contract-snapshots, reviewed in the PR diff, '
        .'and documented in the CHANGELOG — never "fixed" by the pipeline.'
    );
});

it('matches the committed service-signature snapshot for every contract service', function () {
    $serializer = new ServiceContractSerializer;
    $dir        = larabillServiceContractSnapshotDir();

    if (larabillServiceContractRegenRequested() && getenv('CI') === false) {
        foreach (larabillContractServices() as $service) {
            file_put_contents($dir.'/'.class_basename($service).'.json', $serializer->toJson($service));
        }
    }

    $missing = [];
    $drift   = [];

    foreach (larabillContractServices() as $service) {
        $name = class_basename($service);
        $file = "{$dir}/{$name}.json";

        if (! file_exists($file)) {
            $missing[] = $name;

            continue;
        }

        if (file_get_contents($file) !== $serializer->toJson($service)) {
            $drift[] = $name;
        }
    }

    expect($missing)->toBe(
        [],
        'These contract services have no committed signature snapshot in tests/Contract/snapshots/ — '
        .'run `bin/sync-contract-snapshots` and commit the result: '.implode(', ', $missing)
    );

    expect($drift)->toBe(
        [],
        'The public signature surface of these services changed (methods/parameter types/return types): '
        .implode(', ', $drift).'. If the change is intentional, run `bin/sync-contract-snapshots`, '
        .'review `git diff tests/Contract/snapshots/`, add a CHANGELOG entry under [Unreleased] '
        .'(additions → minor at least; removals or signature changes → MAJOR), and commit the '
        .'snapshots together with the code. Silent contract drift is exactly what this gate forbids.'
    );
});
