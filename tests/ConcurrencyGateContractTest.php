<?php

declare(strict_types=1);

/**
 * STD-004 (umbrella STANDARDS.md) — the concurrency gate contract, enforced.
 *
 * A fork-based test that does not synchronise its children is not a
 * concurrency test: forking in a loop starts each child the instant it is
 * born, so on a fast host the first can commit before the last exists and the
 * race never happens. larabill shipped exactly that for months — the AID-700
 * gap-lock deadlock sat behind a green gate on BOTH engines until the children
 * were released on a barrier and the fork count was measured.
 *
 * Prose did not prevent it, so this is the mechanical half: prose says why,
 * this says no. It runs in the normal suite (tests/Concurrency itself is
 * excluded from the testsuites), so it costs nothing and cannot be skipped.
 *
 * The two debt lists below are the files that predate STD-004. They may only
 * SHRINK: the third test fails if an entry becomes stale, so fixing a file
 * without removing it from its list is itself a failure. Adding a NEW
 * unsynchronised fork test is impossible — that is the point.
 */
function std004ForkTestFiles(): array
{
    $files = [];

    foreach (glob(__DIR__.'/Concurrency/*.php') ?: [] as $path) {
        $source = (string) file_get_contents($path);

        if (str_contains($source, 'pcntl_fork')) {
            $files[basename($path)] = $source;
        }
    }

    return $files;
}

/**
 * Files that do not yet release their children on an absolute-time barrier.
 * Removing an entry requires adding the barrier AND measuring the fork count
 * that actually discriminates (STD-004 condition 2) — see AID-700 for the
 * worked example.
 */
function std004PendingBarrier(): array
{
    return [
        'ArticlePriceConcurrencyTest.php',
        'GroupedPaymentIdempotencyConcurrencyTest.php',
        'InvoiceCreationConcurrencyTest.php',
        'ProformaConversionConcurrencyTest.php',
    ];
}

/**
 * Files whose children still die with a mute `exit(1)`. A bare
 * `catch (Throwable)` cannot record anything: every failure collapses into
 * "N children did not converge", which is undiagnosable — that is what kept
 * AID-700 unexplained for weeks in the consumer.
 */
function std004PendingErrorCapture(): array
{
    return [
        'ArticlePriceConcurrencyTest.php',
        'GroupedPaymentIdempotencyConcurrencyTest.php',
    ];
}

it('releases forked children on a synchronised barrier', function () {
    $offenders = [];

    foreach (std004ForkTestFiles() as $name => $source) {
        if (in_array($name, std004PendingBarrier(), true)) {
            continue;
        }

        if (! str_contains($source, 'time_sleep_until')) {
            $offenders[] = $name;
        }
    }

    expect($offenders)->toBe([], sprintf(
        "STD-004: these fork-based tests start their children unsynchronised, so they do not\n".
        "exercise the race they claim to prove:\n  - %s\n\n".
        "Release every child at one absolute instant (time_sleep_until), or — only for a file\n".
        'that predates STD-004 — declare it in std004PendingBarrier().',
        implode("\n  - ", $offenders)
    ));
});

it('records the real exception of a failing child', function () {
    $offenders = [];

    foreach (std004ForkTestFiles() as $name => $source) {
        if (in_array($name, std004PendingErrorCapture(), true)) {
            continue;
        }

        // A bare `catch (Throwable)` binds no variable, so nothing can be
        // written out. Capturing it is necessary but not sufficient: the child
        // must actually persist it for the parent to read.
        $capturesVariable = str_contains($source, 'catch (Throwable $');
        $persistsIt       = str_contains($source, 'file_put_contents');

        if (! $capturesVariable || ! $persistsIt) {
            $offenders[] = $name;
        }
    }

    expect($offenders)->toBe([], sprintf(
        "STD-004: these fork-based tests let a child die mutely, turning any failure into\n".
        "\"N children did not converge\" with no cause:\n  - %s\n\n".
        'Catch into a variable and write it where the parent can read it.',
        implode("\n  - ", $offenders)
    ));
});

it('keeps the STD-004 debt lists free of stale entries', function () {
    $files = std004ForkTestFiles();
    $stale = [];

    foreach (std004PendingBarrier() as $name) {
        if (! isset($files[$name])) {
            $stale[] = "{$name} (barrier list — file no longer exists)";

            continue;
        }

        if (str_contains($files[$name], 'time_sleep_until')) {
            $stale[] = "{$name} (barrier list — already fixed)";
        }
    }

    foreach (std004PendingErrorCapture() as $name) {
        if (! isset($files[$name])) {
            $stale[] = "{$name} (error-capture list — file no longer exists)";

            continue;
        }

        if (str_contains($files[$name], 'catch (Throwable $')) {
            $stale[] = "{$name} (error-capture list — already fixed)";
        }
    }

    expect($stale)->toBe([], sprintf(
        "STD-004: the debt lists may only shrink, and these entries are now stale:\n  - %s\n\n".
        'Remove them so the gate protects what was just fixed.',
        implode("\n  - ", $stale)
    ));
});
