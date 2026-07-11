<?php

declare(strict_types=1);
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Models\UserTaxProfile;

/*
 * Surface taxonomy coverage gate (AID-413, boundary spec §3 / AID-407).
 *
 * Every classlike under src/ (class, interface, trait, enum) MUST declare its
 * band with EXACTLY ONE class-level tag:
 *
 *   - @api      — supported public surface: consumers may depend on it and
 *                 changes follow semver (removals/signature changes = major).
 *   - @internal — implementation detail: may change in any release.
 *
 * `@deprecated` composes with either (public-but-dying keeps its @api).
 * Guiding principle: WHEN IN DOUBT, @internal — promoting @internal to @api
 * later is a harmless minor; demoting @api is a breaking change. Criteria and
 * the per-directory classification live in docs/api-surface.md.
 *
 * Scope note: this gate covers the CLASS-level declaration only. The amber
 * method-level @api tags on the contract models are guarded separately by the
 * contract snapshots (ModelContractSnapshotTest, AID-412).
 */

/** @return array<int, array{file: string, fqcn: string}> */
function larabillSurfaceClasses(): array
{
    $srcDir  = realpath(dirname(__DIR__, 3).'/src');
    $classes = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $srcDir));

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = ltrim(str_replace((string) $srcDir, '', (string) $file->getRealPath()), DIRECTORY_SEPARATOR);
        $fqcn     = 'AichaDigital\\Larabill\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

        $classes[] = ['file' => $relative, 'fqcn' => $fqcn];
    }

    sort($classes);

    return $classes;
}

it('declares every src/ classlike with exactly one of @api or @internal', function () {
    $missing      = [];
    $conflicting  = [];
    $unresolvable = [];

    foreach (larabillSurfaceClasses() as $class) {
        if (! class_exists($class['fqcn']) && ! interface_exists($class['fqcn']) && ! trait_exists($class['fqcn']) && ! enum_exists($class['fqcn'])) {
            $unresolvable[] = $class['file'];

            continue;
        }

        $doc         = (new ReflectionClass($class['fqcn']))->getDocComment() ?: '';
        $hasApi      = (bool) preg_match('/@api\b/', $doc);
        $hasInternal = (bool) preg_match('/@internal\b/', $doc);

        if (! $hasApi && ! $hasInternal) {
            $missing[] = $class['file'];
        } elseif ($hasApi && $hasInternal) {
            $conflicting[] = $class['file'];
        }
    }

    expect($unresolvable)->toBe(
        [],
        'These src/ files do not autoload to a classlike matching their PSR-4 path (one classlike '
        .'per file is the package convention): '.implode(', ', $unresolvable)
    );

    expect($missing)->toBe(
        [],
        'These classes have no class-level @api/@internal tag. Declare the band consciously '
        .'(when in doubt, @internal — promoting later is a minor, demoting is a major; '
        .'see docs/api-surface.md): '.implode(', ', $missing)
    );

    expect($conflicting)->toBe(
        [],
        'These classes carry BOTH @api and @internal — pick exactly one: '.implode(', ', $conflicting)
    );
});

it('keeps the seven amber-band model operations tagged @api at method level (AID-407)', function () {
    $amber = [
        [Invoice::class, 'makeImmutable'],
        [Invoice::class, 'snapshotFiscalConfigs'],
        [CompanyFiscalConfig::class, 'getValidAt'],
        [CompanyFiscalConfig::class, 'createNew'],
        [InvoiceSeriesControl::class, 'getNextNumber'],
        [Article::class, 'getDefaultPrice'],
        [UserTaxProfile::class, 'createForOwner'],
    ];

    $untagged = [];

    foreach ($amber as [$class, $method]) {
        $doc = (new ReflectionMethod($class, $method))->getDocComment() ?: '';

        if (! preg_match('/@api\b/', $doc)) {
            $untagged[] = class_basename($class)."::{$method}()";
        }
    }

    expect($untagged)->toBe(
        [],
        'These amber-band operations lost their method-level @api tag (AID-407 §3): '
        .implode(', ', $untagged)
    );
});
