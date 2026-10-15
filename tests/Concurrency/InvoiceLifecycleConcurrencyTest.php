<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-016 §13 (PR-2) — the lifecycle concurrency proofs (fork-based, MySQL,
 * RUN_CONCURRENCY_IT=1 + sensitivity per house discipline):
 *
 * - two concurrent issue() on the SAME document: serialized by the invoice
 *   row lock, exactly ONE number (§6.3);
 * - issue() racing cancelPreparedInvoice() on a conversion-born PREPARED
 *   document: the lock order proforma → invoice serialises both — the
 *   document ends ISSUED or CANCELLED, never both, and the proforma mirror
 *   agrees with the outcome.
 *
 * Same harness rules as the other fork tests: no RefreshDatabase, bound to
 * MysqlIntegrationTestCase, triple-gated. Run on demand:
 *   RUN_CONCURRENCY_IT=1 LARABILL_TEST_MYSQL_*=… vendor/bin/pest tests/Concurrency
 */
beforeEach(function () {
    if (getenv('RUN_CONCURRENCY_IT') !== '1') {
        test()->markTestSkipped('concurrency IT disabled (set RUN_CONCURRENCY_IT=1)');
    }

    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl extension not available');
    }

    // Schema committed via artisan migrate (no RefreshDatabase wraps this).
    $this->bootstrap();
});

/**
 * Fork $childCount processes all issuing the SAME document.
 *
 * @return array{0: int, 1: int, 2: list<string>, 3: list<string>}
 */
function aid1627ForkIssue(string $invoiceId, int $childCount): array
{
    $resultDir = sys_get_temp_dir().'/larabill_aid1627_issue_'.Str::random(8);
    mkdir($resultDir, 0755, true);

    // STD-004: release every child at one absolute instant — an unsynchronised
    // loop lets the first child commit before the last exists, and the race
    // never happens.
    $startAt = microtime(true) + 1.0;

    $pids = [];
    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            DB::purge('testing');
            time_sleep_until($startAt);

            try {
                $invoice = Invoice::query()->findOrFail($invoiceId);
                $issued  = app(InvoiceService::class)->issue($invoice);
                file_put_contents($resultDir.'/'.getmypid().'.num', (string) $issued->fiscal_number);
                exit(0);
            } catch (Throwable $e) {
                file_put_contents($resultDir.'/'.getmypid().'.err', $e::class.': '.$e->getMessage());
                exit(1);
            }
        }

        $pids[] = $pid;
    }

    return aid1627CollectForkResults($resultDir, $pids, '.num');
}

/**
 * Fork two processes racing on one conversion-born PREPARED document: one
 * issues it, the other cancels it (with its actor). Lock order proforma →
 * invoice serialises them.
 *
 * @return array{0: int, 1: int, 2: list<string>, 3: list<string>} results tagged issue:<n>|cancel:<id>|error per child
 */
function aid1627ForkIssueVsCancel(string $invoiceId, string $actor): array
{
    $resultDir = sys_get_temp_dir().'/larabill_aid1627_race_'.Str::random(8);
    mkdir($resultDir, 0755, true);

    // STD-004: one absolute release instant for both racers.
    $startAt = microtime(true) + 1.0;

    $jobs = [
        function () use ($invoiceId, $resultDir): void {
            $invoice = Invoice::query()->findOrFail($invoiceId);
            $issued  = app(InvoiceService::class)->issue($invoice);
            file_put_contents($resultDir.'/'.getmypid().'.out', 'issue:'.(string) $issued->fiscal_number);
        },
        function () use ($invoiceId, $actor, $resultDir): void {
            $invoice  = Invoice::query()->findOrFail($invoiceId);
            $canceled = app(InvoiceService::class)->cancelPreparedInvoice($invoice, $actor);
            file_put_contents($resultDir.'/'.getmypid().'.out', 'cancel:'.(string) $canceled->invoice_document_status->value);
        },
    ];

    $pids = [];
    foreach ($jobs as $job) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            DB::purge('testing');
            time_sleep_until($startAt);

            try {
                $job();
                exit(0);
            } catch (Throwable $e) {
                file_put_contents($resultDir.'/'.getmypid().'.out', 'error:'.$e::class);
                exit(0); // the outcome IS the data of this race test
            }
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $outcomes = [];
    foreach (glob($resultDir.'/*.out') ?: [] as $file) {
        $outcomes[] = (string) file_get_contents($file);
        unlink($file);
    }
    rmdir($resultDir);

    return [count($outcomes), 0, $outcomes, []];
}

/**
 * @param  list<int>  $pids
 * @return array{0: int, 1: int, 2: list<string>, 3: list<string>}
 */
function aid1627CollectForkResults(string $resultDir, array $pids, string $suffix): array
{
    $converged = $uncontrolled = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        $code === 0 ? $converged++ : $uncontrolled++;
    }

    $values = [];
    foreach (glob($resultDir.'/*'.$suffix) ?: [] as $file) {
        $values[] = (string) file_get_contents($file);
        unlink($file);
    }

    $errors = [];
    foreach (glob($resultDir.'/*.err') ?: [] as $file) {
        $errors[] = (string) file_get_contents($file);
        unlink($file);
    }
    rmdir($resultDir);

    return [$converged, $uncontrolled, $values, $errors];
}

it('two concurrent issue() calls on the same document burn exactly ONE number', function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);
    $userId  = $this->seedUser();
    $service = app(InvoiceService::class);

    // A warm series control keeps the first-use deadlock out of THIS scenario
    // (the virgin-series convergence is the AID-570 proof, re-pointed).
    InvoiceSeriesControl::create([
        'prefix'            => 'FAC',
        'serie'             => InvoiceSerieType::INVOICE->value,
        'fiscal_year'       => now()->year,
        'fiscal_year_start' => now()->startOfYear()->toDateString(),
        'fiscal_year_end'   => now()->endOfYear()->toDateString(),
        'last_number'       => 0,
        'start_number'      => 1,
        'reset_annually'    => true,
        'number_format'     => '{{PREFIX}}-{{YEAR}}-{{NUMBER}}',
        'is_active'         => true,
    ]);

    $draft = $service->createInvoice([
        'billable_user_id' => $userId,
        'items'            => [],
    ]);

    [$converged, $uncontrolled, $numbers, $errors] = aid1627ForkIssue((string) $draft->id, 6);

    expect($errors)->toBe([])
        ->and($uncontrolled)->toBe(0)
        ->and($converged)->toBe(6);

    DB::purge('testing');

    // Exactly one correlative: all six children reported the SAME number.
    expect(count(array_unique($numbers)))->toBe(1)
        ->and($numbers[0])->not->toBe('');

    $control = InvoiceSeriesControl::where('prefix', 'FAC')->where('fiscal_year', now()->year)->sole();
    expect($control->last_number)->toBe(1);

    $issued = Invoice::findOrFail($draft->id);
    expect($issued->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($issued->fiscal_number)->toBe($numbers[0]);
})->group('concurrency');

it('issue() racing cancelPreparedInvoice() ends in exactly one terminal state, mirror and proforma agreeing', function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);
    $userId  = $this->seedUser();
    $service = app(InvoiceService::class);

    $proforma = $service->createProforma([
        'billable_user_id' => $userId,
        'items'            => [],
    ]);
    $prepared = $service->convertProformaToInvoice($proforma)->invoice;

    [, , $outcomes] = aid1627ForkIssueVsCancel((string) $prepared->id, 'race-actor');

    // Both children finished and both reported an outcome (no silent death).
    expect(count($outcomes))->toBe(2);

    DB::purge('testing');

    $document = Invoice::findOrFail($prepared->id);
    $proforma = Invoice::findOrFail($proforma->id);

    $wonByIssue  = in_array('issue:'.$document->fiscal_number, $outcomes, true);
    $wonByCancel = in_array('cancel:'.InvoiceDocumentStatus::CANCELLED->value, $outcomes, true);

    // Exactly one of the two operations won — the other observed the
    // winner's terminal state through its idempotent path (issue returns
    // ISSUED unchanged; the race pair is one issuer + one canceller, so the
    // loser surfaces its own error: cancel on ISSUED throws, issue on
    // CANCELLED throws — both are reported as error: outcomes).
    expect($wonByIssue xor $wonByCancel)->toBeTrue();

    if ($wonByIssue) {
        expect($document->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
            ->and($proforma->status)->toBe(InvoiceStatus::CONVERTED)
            ->and((string) $proforma->converted_invoice_id)->toBe((string) $document->id)
            // Exactly one correlative consumed.
            ->and(InvoiceSeriesControl::where('prefix', $document->prefix)->where('fiscal_year', now()->year)->sole()->last_number)->toBe(1);
    } else {
        expect($document->invoice_document_status)->toBe(InvoiceDocumentStatus::CANCELLED)
            ->and($document->fiscal_number)->toBeNull()
            ->and($document->document_cancelled_by)->toBe('race-actor')
            ->and($proforma->status)->toBe(InvoiceStatus::DRAFT)
            ->and($proforma->converted_invoice_id)->toBeNull()
            // No correlative consumed at all.
            ->and(InvoiceSeriesControl::where('prefix', $document->prefix)->count())->toBe(0);
    }
})->group('concurrency');
