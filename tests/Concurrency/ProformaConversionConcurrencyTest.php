<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-554 (D1) — end-to-end fork proof that a proforma converts EXACTLY once.
 *
 * convertProformaToInvoice() wraps the conversion in DB::transaction() and
 * must re-read the proforma under lockForUpdate() before its idempotency
 * check. Without that lock, N concurrent conversions of the SAME proforma can
 * all observe converted_invoice_id = NULL and each mint a final invoice.
 *
 * Contract under real concurrency: exactly ONE final invoice exists, every
 * process converges on ITS id (the winner creates it, losers receive it via
 * the idempotency branch), and the proforma ends CONVERTED + immutable.
 *
 * Same harness rules as InvoiceNumberingConcurrencyTest (AID-390): no
 * RefreshDatabase (children must read parent-committed rows on their own
 * connections), bound to MysqlIntegrationTestCase, triple-gated. Run on
 * demand:
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
 * Fork $childCount processes all converting the SAME proforma, and return
 * [convergedCount, uncontrolledCount, invoiceIdsReportedByChildren[],
 * childErrors[]].
 *
 * @return array{0: int, 1: int, 2: list<string>, 3: list<string>}
 */
function aid554ForkConvert(string $proformaId, int $childCount): array
{
    $resultDir = sys_get_temp_dir().'/larabill_aid554_'.Str::random(8);
    mkdir($resultDir, 0755, true);

    $pids = [];
    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // Never share the parent's PDO across the fork.
            DB::purge('testing');

            try {
                $proforma = Invoice::query()->findOrFail($proformaId);
                $result   = app(InvoiceService::class)->convertProformaToInvoice($proforma);
                $invoice  = $result instanceof Invoice ? $result : $result['invoice'];
                file_put_contents($resultDir.'/'.getmypid().'.inv', (string) $invoice->id);
                exit(0);
            } catch (Throwable $e) {
                // UNCONTROLLED — surface the reason to the parent for the diff.
                file_put_contents($resultDir.'/'.getmypid().'.err', $e::class.': '.$e->getMessage());
                exit(1);
            }
        }

        $pids[] = $pid;
    }

    $converged = $uncontrolled = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        $code === 0 ? $converged++ : $uncontrolled++;
    }

    $invoiceIds = [];
    foreach (glob($resultDir.'/*.inv') ?: [] as $file) {
        $invoiceIds[] = (string) file_get_contents($file);
        unlink($file);
    }

    $errors = [];
    foreach (glob($resultDir.'/*.err') ?: [] as $file) {
        $errors[] = (string) file_get_contents($file);
        unlink($file);
    }
    rmdir($resultDir);

    return [$converged, $uncontrolled, $invoiceIds, $errors];
}

it('concurrent conversions of the SAME proforma converge on exactly one final invoice', function () {
    $childCount = 6;

    // Committed fixtures on the parent connection.
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $userId   = $this->seedUser();
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $userId,
        'items'            => [],
    ]);

    [$converged, $uncontrolled, $invoiceIds, $errors] = aid554ForkConvert((string) $proforma->id, $childCount);

    // Every child converged — the winner created, losers got the existing one.
    // Comparing the error list against [] surfaces the child failure reasons.
    expect($errors)->toBe([])
        ->and($uncontrolled)->toBe(0)
        ->and($converged)->toBe($childCount);

    // Re-read committed state on a fresh connection.
    DB::purge('testing');

    // The invariant: ONE final invoice, and every process reported ITS id.
    $finals = Invoice::where('serie', InvoiceSerieType::INVOICE->value)->get();
    expect($finals)->toHaveCount(1)
        ->and(array_unique($invoiceIds))->toBe([(string) $finals->first()->id]);

    $frozen = Invoice::findOrFail($proforma->id);
    expect($frozen->status)->toBe(InvoiceStatus::CONVERTED)
        ->and($frozen->is_immutable)->toBeTrue()
        ->and((string) $frozen->converted_invoice_id)->toBe((string) $finals->first()->id);
})->group('concurrency');

it('concurrent conversions on a WARM series control still converge on exactly one final invoice', function () {
    $childCount = 6;

    // Same scenario, steady-state flavour: with the FAC control already
    // committed, the unlocked code path minted N duplicate finals cleanly
    // (no first-use deadlock to crash the losers) — the ticket's literal D1.
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    InvoiceSeriesControl::create([
        'prefix'            => 'FAC',
        'serie'             => InvoiceSerieType::INVOICE->value,
        'fiscal_year'       => now()->year,
        'fiscal_year_start' => now()->startOfYear()->toDateString(),
        'fiscal_year_end'   => now()->endOfYear()->toDateString(),
        'last_number'       => 3,
        'start_number'      => 1,
        'reset_annually'    => true,
        'number_format'     => '{{PREFIX}}-{{YEAR}}-{{NUMBER}}',
        'is_active'         => true,
    ]);

    $userId   = $this->seedUser();
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $userId,
        'items'            => [],
    ]);

    [$converged, $uncontrolled, $invoiceIds, $errors] = aid554ForkConvert((string) $proforma->id, $childCount);

    expect($errors)->toBe([])
        ->and($uncontrolled)->toBe(0)
        ->and($converged)->toBe($childCount);

    DB::purge('testing');

    $finals = Invoice::where('serie', InvoiceSerieType::INVOICE->value)->get();
    expect($finals)->toHaveCount(1)
        ->and(array_unique($invoiceIds))->toBe([(string) $finals->first()->id]);

    $frozen = Invoice::findOrFail($proforma->id);
    expect($frozen->status)->toBe(InvoiceStatus::CONVERTED)
        ->and($frozen->is_immutable)->toBeTrue()
        ->and((string) $frozen->converted_invoice_id)->toBe((string) $finals->first()->id);
})->group('concurrency');
