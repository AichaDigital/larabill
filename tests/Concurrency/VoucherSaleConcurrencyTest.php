<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\VoucherSaleOutcome;
use AichaDigital\Larabill\Enums\VoucherSaleStage;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Exceptions\InvoiceNotAnnullableException;
use AichaDigital\Larabill\Exceptions\InvoiceNotCancellableException;
use AichaDigital\Larabill\Exceptions\SaleClaimedException;
use AichaDigital\Larabill\Exceptions\SaleIntegrityException;
use AichaDigital\Larabill\Exceptions\SaleStageException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Models\VoucherSaleClaim;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Services\VoucherSaleService;
use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use AichaDigital\Larabill\ValueObjects\VoucherConsumptionRequest;
use AichaDigital\Larabill\ValueObjects\VoucherSaleRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-1825 minimal scope §5 test 16 — fork proofs of the voucher sale on a
 * real engine (MySQL 8.4 / MariaDB 11.4): two connections, one proforma, the
 * serialization point being the proforma row X lock of S0+S1 and the
 * payment → proforma → invoice lock order of S3a/S3b.
 *
 * Every case releases its two children through the J8 rendezvous (both
 * in-flight markers exist before either calls) and asserts the overlap, so a
 * serial execution fails loud. Outcomes are classified: a SOLD result, a
 * named refusal result, or a typed controlled exception are controlled;
 * anything else (a QueryException that is not a retried deadlock, a
 * LogicException, a half-written row) fails the case.
 *
 * SENSITIVITY (documented, run by hand — never in CI): remove the
 * `lockForUpdate()` of the proforma in `VoucherSaleService::claim()` and case
 * «two keys on one proforma» produces two payments and a second claim that
 * dies on the `voucher_sale_claims.proforma_id` UNIQUE as an uncontrolled
 * QueryException; remove the `assertTuple()` re-read of S3b and the
 * consumption race can leave a negative remainder undetected.
 *
 * Run on demand:
 *   RUN_CONCURRENCY_IT=1 LARABILL_TEST_MYSQL_*=… vendor/bin/pest tests/Concurrency/VoucherSaleConcurrencyTest.php
 */
beforeEach(function () {
    if (getenv('RUN_CONCURRENCY_IT') !== '1') {
        test()->markTestSkipped('concurrency IT disabled (set RUN_CONCURRENCY_IT=1)');
    }

    if (! function_exists('pcntl_fork')) {
        test()->fail('RUN_CONCURRENCY_IT=1 armed but the pcntl extension is unavailable — the concurrency gate would silently prove nothing.');
    }

    if (! MysqlIntegrationTestCase::mysqlEnvAvailable()) {
        test()->fail('RUN_CONCURRENCY_IT=1 armed but the LARABILL_TEST_MYSQL_* env is not configured — the concurrency gate would silently prove nothing.');
    }

    $this->bootstrap();

    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = $this->seedUser();
});

/** An accepted (SENT) top-up proforma of the customer. */
function aid1825kProforma(string $customerId, int $basePriceCents = 10000): Invoice
{
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $customerId,
        'items'            => [['description' => 'Service voucher', 'quantity' => 100, 'base_price' => $basePriceCents]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);

    return $proforma->fresh();
}

function aid1825kRequest(Invoice $proforma, string $customerId, string $key): VoucherSaleRequest
{
    return new VoucherSaleRequest((string) $proforma->id, $customerId, $proforma->total_amount, 'EUR', now()->subMinute()->startOfSecond(), PaymentMethod::Card, 'pi_'.$key, null, $key);
}

/** The controlled outcome a domain exception stands for, or null (uncontrolled). */
function aid1825kClassify(Throwable $e): ?string
{
    return match (true) {
        $e instanceof SaleClaimedException           => 'claimed',
        $e instanceof SaleStageException             => 'stage',
        $e instanceof SaleIntegrityException         => 'integrity:'.$e->reason->value,
        $e instanceof IdempotencyConflictException   => 'key-conflict',
        $e instanceof InsufficientCreditException    => 'insufficient',
        $e instanceof InvoiceNotCancellableException => 'not-cancellable',
        $e instanceof InvoiceNotAnnullableException  => 'not-annullable',
        default                                      => null,
    };
}

/**
 * Fork one child per role, J8 rendezvous + STD-004 absolute-time barrier,
 * classify each exit; return [tally, overlap].
 *
 * @param  array<string, Closure(): string>  $bodies  role → body returning its success tag
 * @return array{0: array<string, int>, 1: bool}
 */
function aid1825kForkRace(array $bodies): array
{
    $dir = sys_get_temp_dir().'/larabill_voucher_'.Str::random(8);
    mkdir($dir, 0755, true);
    $roles   = array_keys($bodies);
    $startAt = microtime(true) + 1.0;

    $pids = [];
    foreach ($bodies as $role => $body) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            DB::purge('testing');

            $run = function () use ($body, $role, $dir): never {
                $tag = $body();
                file_put_contents("{$dir}/{$role}.tag", "{$role}:{$tag}");
                exit(0);
            };

            try {
                file_put_contents("{$dir}/{$role}.inflight", '1');
                $deadline = microtime(true) + 5.0;
                foreach ($roles as $peer) {
                    if ($peer === $role) {
                        continue;
                    }
                    while (! file_exists("{$dir}/{$peer}.inflight")) {
                        if (microtime(true) > $deadline) {
                            file_put_contents("{$dir}/{$role}.err", "{$role}: rendezvous timeout waiting for {$peer}");
                            exit(1);
                        }
                        usleep(1000);
                    }
                }
                time_sleep_until($startAt);

                $run();
            } catch (QueryException $e) {
                // A deadlock victim (1213 / 40001) retries once and must converge.
                if (str_contains($e->getMessage(), '1213') || str_contains($e->getMessage(), '40001') || (int) $e->getCode() === 40001) {
                    try {
                        $tag = $body();
                        file_put_contents("{$dir}/{$role}.tag", "{$role}:retried-{$tag}");
                        exit(0);
                    } catch (Throwable $retried) {
                        if (($class = aid1825kClassify($retried)) !== null) {
                            file_put_contents("{$dir}/{$role}.tag", "{$role}:retried-{$class}");
                            exit(2);
                        }
                        file_put_contents("{$dir}/{$role}.err", "{$role} retry: ".get_class($retried).': '.$retried->getMessage());
                        exit(1);
                    }
                }
                file_put_contents("{$dir}/{$role}.err", "{$role}: ".get_class($e).': '.$e->getMessage());
                exit(1);
            } catch (Throwable $e) {
                if (($class = aid1825kClassify($e)) !== null) {
                    file_put_contents("{$dir}/{$role}.tag", "{$role}:{$class}");
                    exit(2);
                }
                file_put_contents("{$dir}/{$role}.err", "{$role}: ".get_class($e).': '.$e->getMessage());
                exit(1);
            }
        }

        $pids[$role] = $pid;
    }

    $uncontrolled = 0;
    foreach ($pids as $role => $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        if ($code !== 0 && $code !== 2) {
            $uncontrolled++;
        }
    }

    if ($uncontrolled > 0) {
        $errors = glob($dir.'/*.err') ?: [];
        $detail = implode("\n", array_map(fn (string $f): string => basename($f).': '.(string) file_get_contents($f), $errors));
        test()->fail("{$uncontrolled} of ".count($roles)." children died uncontrolled:\n{$detail}");
    }

    $tags = array_map(fn (string $f): string => (string) file_get_contents($f), glob($dir.'/*.tag') ?: []);
    expect($tags)->toHaveCount(count($roles));
    $tally = array_count_values($tags);
    ksort($tally);
    $overlap = count(glob($dir.'/*.inflight') ?: []) === count($roles);

    foreach (glob($dir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);

    DB::purge('testing');

    return [$tally, $overlap];
}

/** The S3b tuple of one sale, asserted from the parent's fresh connection. */
function aid1825kAssertOneSale(string $customerId, string $proformaId): void
{
    $claims = VoucherSaleClaim::query()->where('proforma_id', $proformaId)->get();
    expect($claims)->toHaveCount(1)
        ->and($claims->sole()->stage)->toBe(VoucherSaleStage::Backed)
        ->and($claims->sole()->issued_by_sale)->toBeTrue();

    $invoices = Invoice::query()->where('sale_claim_id', $claims->sole()->id)->get();
    expect($invoices)->toHaveCount(1)
        ->and($invoices->sole()->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($invoices->sole()->status)->toBe(InvoiceStatus::PAID)
        ->and($invoices->sole()->amountOutstanding()->unscaledValue())->toBe(0);

    expect(GroupedPayment::query()->where('billable_user_id', $customerId)->count())->toBe(1)
        ->and(PaymentAllocation::query()->where('invoice_id', $invoices->sole()->id)->count())->toBe(1)
        ->and(CreditMovement::query()->where('sale_invoice_id', $invoices->sole()->id)->count())->toBe(1)
        ->and(Invoice::query()->where('serie', InvoiceSerieType::INVOICE->value)->count())->toBe(1);
}

it('two sales with the SAME key: both SOLD, one payment, one allocation, one entry, one fiscal invoice', function () {
    $proforma = aid1825kProforma($this->customer);
    $request  = aid1825kRequest($proforma, $this->customer, 'same-key');

    $sale              = fn (): string => app(VoucherSaleService::class)->sellVoucher($request)->outcome->value;
    [$tally, $overlap] = aid1825kForkRace(['a' => $sale, 'b' => $sale]);

    expect($overlap)->toBeTrue();
    // Both SOLD, no other outcome: the proforma lock serializes S1, the second
    // caller resumes the same claim and replays S3b — never a conflict, never
    // an integrity refusal (gate round 1 §5 test 16 tightened).
    $sold = array_sum(array_filter($tally, fn (string $tag): bool => str_ends_with($tag, VoucherSaleOutcome::Sold->value), ARRAY_FILTER_USE_KEY));
    expect($sold)->toBe(2, 'tally: '.json_encode($tally));

    aid1825kAssertOneSale($this->customer, (string) $proforma->id);
})->group('concurrency');

it('sale vs an edit of the claimed proforma racing S1: the edit never lands on a proforma the sale claimed', function () {
    $proforma = aid1825kProforma($this->customer);
    $request  = aid1825kRequest($proforma, $this->customer, 'vs-edit');
    $total    = $proforma->total_amount->unscaledValue();

    [$tally, $overlap] = aid1825kForkRace([
        'sale' => fn (): string => app(VoucherSaleService::class)->sellVoucher($request)->outcome->value,
        'edit' => function () use ($proforma): string {
            // A staff edit of the header total (fiscal content) — the race the model guard must lose or win cleanly.
            Invoice::query()->whereKey($proforma->id)->firstOrFail()->update(['total_amount' => cents(99900)]);

            // Right after the edit committed: was the sale window still OPEN (claim present, not BACKED)?
            // An edit that lands inside the window is the defect; before the claim or after BACKED is legitimate.
            $stage = VoucherSaleClaim::query()->where('proforma_id', $proforma->id)->value('stage');

            return match (true) {
                $stage === null                     => 'edited-before-claim',
                $stage === VoucherSaleStage::Backed => 'edited-after-backed',
                default                             => 'edited-inside-window',
            };
        },
    ]);

    expect($overlap)->toBeTrue();
    $count = fn (string $tag): int => ($tally["edit:{$tag}"] ?? 0) + ($tally["edit:retried-{$tag}"] ?? 0);

    // The one outcome the guard exists to prevent, whatever the sale did.
    expect($count('edited-inside-window'))->toBe(0, 'tally: '.json_encode($tally));

    if (isset($tally['sale:sold']) || isset($tally['sale:retried-sold'])) {
        // The sale won the proforma: the edit was refused while claimed, or landed only after BACKED (window closed,
        // the proforma already CONVERTED — outside §1a). Either way the sale invoice carries the claimed total.
        expect($count('claimed') + $count('edited-after-backed'))->toBe(1, 'tally: '.json_encode($tally));
        aid1825kAssertOneSale($this->customer, (string) $proforma->id);
        expect(Invoice::query()->whereNotNull('sale_claim_id')->firstOrFail()->total_amount->unscaledValue())->toBe($total);
    } else {
        // The edit landed first: the proforma no longer matches the payment and the sale refused with zero writes.
        expect($count('edited-before-claim'))->toBe(1, 'tally: '.json_encode($tally))
            ->and(($tally['sale:amount_mismatch'] ?? 0) + ($tally['sale:retried-amount_mismatch'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
            ->and(GroupedPayment::query()->count())->toBe(0)
            ->and(VoucherSaleClaim::query()->count())->toBe(0);
    }
})->group('concurrency');

it('resume vs receive() allocating to the ISSUED sale invoice: the ordinary payment is refused, the sale keeps its one allocation', function () {
    $proforma = aid1825kProforma($this->customer);
    $request  = aid1825kRequest($proforma, $this->customer, 'vs-receive');

    // Parent: stop the sale between S3a and S3b (issued, marked, no balance yet).
    VoucherSaleClaim::saving(fn (VoucherSaleClaim $c) => $c->stage === VoucherSaleStage::Backed ? false : null);
    try {
        app(VoucherSaleService::class)->sellVoucher($request);
        test()->fail('the sale should have stopped before BACKED');
    } catch (LogicException) {
    } finally {
        VoucherSaleClaim::flushEventListeners();
        VoucherSaleClaim::clearBootedModels();
    }
    $invoice = Invoice::query()->whereNotNull('sale_claim_id')->firstOrFail();
    expect($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED);
    // The setter the boundary declares unsupported makes the sale invoice look payable to receive().
    $invoice->restoreStateViaGroupedPaymentReversal(InvoiceStatus::PENDING, null);

    [$tally, $overlap] = aid1825kForkRace([
        'resume'  => fn (): string => app(VoucherSaleService::class)->sellVoucher($request)->outcome->value,
        'receive' => function () use ($invoice): string {
            app(PaymentService::class)->receive($this->customer, $invoice->total_amount, 'EUR', now(), PaymentMethod::BankTransfer, null, 'other-key', [new Allocation((string) $invoice->id, $invoice->total_amount)]);

            return 'allocated';
        },
    ]);

    expect($overlap)->toBeTrue()
        ->and(($tally['receive:claimed'] ?? 0) + ($tally['receive:retried-claimed'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
        ->and(PaymentAllocation::query()->where('invoice_id', $invoice->id)->count())->toBeLessThanOrEqual(1);

    // The resume either completed (PENDING is not DRAFT → collectionStateUnexpected) — either way exactly one sale payment and never a foreign allocation.
    expect(PaymentAllocation::query()->where('invoice_id', $invoice->id)->whereNot('grouped_payment_id', VoucherSaleClaim::query()->firstOrFail()->grouped_payment_id)->count())->toBe(0);
})->group('concurrency');

it('two DIFFERENT keys on one proforma: one SOLD, the other a Duplicate with zero writes', function () {
    $proforma = aid1825kProforma($this->customer);
    $a        = aid1825kRequest($proforma, $this->customer, 'key-A');
    $b        = aid1825kRequest($proforma, $this->customer, 'key-B');

    [$tally, $overlap] = aid1825kForkRace([
        'a' => fn (): string => app(VoucherSaleService::class)->sellVoucher($a)->outcome->value,
        'b' => fn (): string => app(VoucherSaleService::class)->sellVoucher($b)->outcome->value,
    ]);

    expect($overlap)->toBeTrue();
    $sold = ($tally['a:sold'] ?? 0)      + ($tally['b:sold'] ?? 0) + ($tally['a:retried-sold'] ?? 0) + ($tally['b:retried-sold'] ?? 0);
    $dup  = ($tally['a:duplicate'] ?? 0) + ($tally['b:duplicate'] ?? 0) + ($tally['a:retried-duplicate'] ?? 0) + ($tally['b:retried-duplicate'] ?? 0);
    expect($sold)->toBe(1, 'tally: '.json_encode($tally))
        ->and($dup)->toBe(1, 'tally: '.json_encode($tally));

    aid1825kAssertOneSale($this->customer, (string) $proforma->id);
    expect(GroupedPayment::query()->whereIn('idempotency_key', ['key-A', 'key-B'])->count())->toBe(1);
})->group('concurrency');

it('sale vs convertAndIssue() on the same proforma: exactly one fiscal invoice, the other side refused or Duplicate', function () {
    $proforma = aid1825kProforma($this->customer);
    $request  = aid1825kRequest($proforma, $this->customer, 'vs-convert');

    [$tally, $overlap] = aid1825kForkRace([
        'sale'    => fn (): string => app(VoucherSaleService::class)->sellVoucher($request)->outcome->value,
        'convert' => function () use ($proforma): string {
            app(InvoiceService::class)->convertAndIssue($proforma);

            return 'issued';
        },
    ]);

    expect($overlap)->toBeTrue();
    expect(Invoice::query()->where('serie', InvoiceSerieType::INVOICE->value)->count())->toBe(1, 'tally: '.json_encode($tally));

    if (isset($tally['sale:sold']) || isset($tally['sale:retried-sold'])) {
        expect(($tally['convert:claimed'] ?? 0) + ($tally['convert:retried-claimed'] ?? 0))->toBe(1, 'tally: '.json_encode($tally));
        aid1825kAssertOneSale($this->customer, (string) $proforma->id);
    } else {
        expect(($tally['convert:issued'] ?? 0) + ($tally['convert:retried-issued'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
            ->and(($tally['sale:duplicate'] ?? 0) + ($tally['sale:retried-duplicate'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
            ->and(GroupedPayment::query()->count())->toBe(0)
            ->and(VoucherSaleClaim::query()->count())->toBe(0);
    }
})->group('concurrency');

it('sale vs cancelProforma(): either SOLD and the cancel refused, or cancelled and the sale ProformaNotInForce with zero writes', function () {
    $proforma = aid1825kProforma($this->customer);
    $request  = aid1825kRequest($proforma, $this->customer, 'vs-cancel');

    [$tally, $overlap] = aid1825kForkRace([
        'sale'   => fn (): string => app(VoucherSaleService::class)->sellVoucher($request)->outcome->value,
        'cancel' => function () use ($proforma): string {
            app(VoucherSaleService::class)->cancelProforma($proforma, 'staff');

            return 'cancelled';
        },
    ]);

    expect($overlap)->toBeTrue();

    if (isset($tally['sale:sold']) || isset($tally['sale:retried-sold'])) {
        expect(($tally['cancel:claimed'] ?? 0) + ($tally['cancel:not-cancellable'] ?? 0) + ($tally['cancel:retried-claimed'] ?? 0) + ($tally['cancel:retried-not-cancellable'] ?? 0))->toBe(1, 'tally: '.json_encode($tally));
        aid1825kAssertOneSale($this->customer, (string) $proforma->id);
    } else {
        expect(($tally['cancel:cancelled'] ?? 0) + ($tally['cancel:retried-cancelled'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
            ->and(($tally['sale:proforma_not_in_force'] ?? 0) + ($tally['sale:retried-proforma_not_in_force'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
            ->and(GroupedPayment::query()->count())->toBe(0)
            ->and($proforma->fresh()->status)->toBe(InvoiceStatus::CANCELLED);
    }
})->group('concurrency');

it('re-call vs reverse() of the funding payment at PENDING: either SOLD and the reversal refused by stage, or reversed and the key spent', function () {
    $proforma = aid1825kProforma($this->customer);
    $request  = aid1825kRequest($proforma, $this->customer, 'vs-reverse');

    // Parent: stop the sale right after S1 (claim PENDING, payment recorded, no entry).
    Invoice::saving(function (Invoice $invoice): void {
        if ($invoice->invoice_document_status === InvoiceDocumentStatus::PREPARED && ! $invoice->exists) {
            throw new RuntimeException('crash after S1');
        }
    });
    try {
        app(VoucherSaleService::class)->sellVoucher($request);
        test()->fail('the sale should have stopped after S1');
    } catch (RuntimeException) {
    } finally {
        Invoice::flushEventListeners();
        Invoice::clearBootedModels();
    }
    $claim   = VoucherSaleClaim::query()->where('proforma_id', $proforma->id)->firstOrFail();
    $payment = GroupedPayment::query()->whereKey($claim->grouped_payment_id)->firstOrFail();
    expect($claim->stage)->toBe(VoucherSaleStage::Pending);

    [$tally, $overlap] = aid1825kForkRace([
        'resume'  => fn (): string => app(VoucherSaleService::class)->sellVoucher($request)->outcome->value,
        'reverse' => function () use ($payment): string {
            app(PaymentService::class)->reverse($payment, 'customer withdrew');

            return 'reversed';
        },
    ]);

    expect($overlap)->toBeTrue();

    if (isset($tally['resume:sold']) || isset($tally['resume:retried-sold'])) {
        expect(($tally['reverse:stage'] ?? 0) + ($tally['reverse:retried-stage'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
            ->and($payment->fresh()->isPosted())->toBeTrue();
        aid1825kAssertOneSale($this->customer, (string) $proforma->id);
    } else {
        expect(($tally['reverse:reversed'] ?? 0) + ($tally['reverse:retried-reversed'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
            ->and(($tally['resume:key-conflict'] ?? 0) + ($tally['resume:retried-key-conflict'] ?? 0) + ($tally['resume:integrity:claim_missing'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
            ->and($payment->fresh()->isReversed())->toBeTrue()
            ->and(VoucherSaleClaim::query()->count())->toBe(0)
            ->and(CreditMovement::query()->count())->toBe(0)
            ->and(Invoice::query()->where('serie', InvoiceSerieType::INVOICE->value)->whereNotNull('fiscal_number')->count())->toBe(0);
    }
})->group('concurrency');

it('two consumptions of one voucher: the balance never goes below zero and the exits sum to what was taken', function () {
    $proforma = aid1825kProforma($this->customer);
    $sold     = app(VoucherSaleService::class)->sellVoucher(aid1825kRequest($proforma, $this->customer, 'for-consume'));
    $total    = $sold->invoice->total_amount->unscaledValue();
    $take     = intdiv($total, 2) + 1; // two of these exceed the voucher: at most one may succeed

    $consume = fn (string $key) => function () use ($key, $take): string {
        app(VoucherSaleService::class)->consumeVoucher(new VoucherConsumptionRequest($this->customer, cents($take), 'EUR', 'svc:'.$key, null, now(), null, $key));

        return 'consumed';
    };
    [$tally, $overlap] = aid1825kForkRace(['c1' => $consume('c1'), 'c2' => $consume('c2')]);

    expect($overlap)->toBeTrue();
    $consumed = ($tally['c1:consumed'] ?? 0) + ($tally['c2:consumed'] ?? 0) + ($tally['c1:retried-consumed'] ?? 0) + ($tally['c2:retried-consumed'] ?? 0);
    expect($consumed)->toBe(1, 'tally: '.json_encode($tally));

    $exits = CreditMovement::query()->where('kind', CreditMovementKind::CONSUMED->value)->get();
    expect((int) -$exits->sum(fn (CreditMovement $m): int => $m->amount->unscaledValue()))->toBe($take)
        ->and($sold->entry->fresh()->remaining()->unscaledValue())->toBe($total - $take)
        ->and($sold->entry->fresh()->remaining()->unscaledValue())->toBeGreaterThanOrEqual(0);
})->group('concurrency');

it('consumption vs annul() of the sale invoice: the annulment is always refused, the consumption converges', function () {
    $proforma = aid1825kProforma($this->customer);
    $sold     = app(VoucherSaleService::class)->sellVoucher(aid1825kRequest($proforma, $this->customer, 'for-annul'));
    $invoice  = $sold->invoice;

    [$tally, $overlap] = aid1825kForkRace([
        'consume' => function () {
            app(VoucherSaleService::class)->consumeVoucher(new VoucherConsumptionRequest($this->customer, cents(100), 'EUR', 'svc:x', null, now(), null, 'annul-race'));

            return 'consumed';
        },
        'annul' => function () use ($invoice): string {
            app(SettlementService::class)->annul($invoice);

            return 'annulled';
        },
    ]);

    expect($overlap)->toBeTrue()
        ->and(($tally['annul:not-annullable'] ?? 0) + ($tally['annul:retried-not-annullable'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
        ->and(($tally['consume:consumed'] ?? 0) + ($tally['consume:retried-consumed'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
})->group('concurrency');

it('resume (ISSUED, not yet backed) vs receive() on the sale invoice: the resume completes the whole tuple and the ordinary payment is refused', function () {
    $proforma = aid1825kProforma($this->customer);
    $request  = aid1825kRequest($proforma, $this->customer, 'vs-receive-clean');

    // Parent: stop the sale after issue(), before BACKED — the real crash window, no setter involved.
    VoucherSaleClaim::saving(fn (VoucherSaleClaim $c) => $c->stage === VoucherSaleStage::Backed ? false : null);
    try {
        app(VoucherSaleService::class)->sellVoucher($request);
        test()->fail('the sale should have stopped before BACKED');
    } catch (LogicException) {
    } finally {
        VoucherSaleClaim::flushEventListeners();
        VoucherSaleClaim::clearBootedModels();
    }
    $invoice = Invoice::query()->whereNotNull('sale_claim_id')->firstOrFail();
    expect($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($invoice->status)->toBe(InvoiceStatus::DRAFT);

    [$tally, $overlap] = aid1825kForkRace([
        'resume'  => fn (): string => app(VoucherSaleService::class)->sellVoucher($request)->outcome->value,
        'receive' => function () use ($invoice): string {
            try {
                app(PaymentService::class)->receive($this->customer, $invoice->total_amount, 'EUR', now(), PaymentMethod::BankTransfer, null, 'other-key-clean', [new Allocation((string) $invoice->id, $invoice->total_amount)]);

                return 'allocated';
            } catch (GroupedPaymentValidationException) {
                // DRAFT before the resume, PAID after it: never payable by an ordinary payment (the 9.1 guard fires before rule 2).
                return 'not-payable';
            }
        },
    ]);

    expect($overlap)->toBeTrue()
        ->and(($tally['resume:sold'] ?? 0) + ($tally['resume:retried-sold'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
        ->and(($tally['receive:allocated'] ?? 0) + ($tally['receive:retried-allocated'] ?? 0))->toBe(0, 'tally: '.json_encode($tally));

    aid1825kAssertOneSale($this->customer, (string) $proforma->id);
})->group('concurrency');

it('edit of the PREPARED sale invoice racing the resume: the edit is refused whatever the instant (claimed while open, immutable once issued)', function () {
    $proforma = aid1825kProforma($this->customer);
    $request  = aid1825kRequest($proforma, $this->customer, 'vs-prepared-edit');
    $total    = $proforma->total_amount->unscaledValue();

    // Parent: stop after S2 — PREPARED invoice, claim PENDING.
    VoucherSaleClaim::saving(fn (VoucherSaleClaim $c) => $c->stage === VoucherSaleStage::Issuing ? false : null);
    try {
        app(VoucherSaleService::class)->sellVoucher($request);
        test()->fail('the sale should have stopped before ISSUING');
    } catch (LogicException) {
    } finally {
        VoucherSaleClaim::flushEventListeners();
        VoucherSaleClaim::clearBootedModels();
    }
    $prepared = Invoice::query()->whereNotNull('sale_claim_id')->firstOrFail();
    expect($prepared->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED);

    [$tally, $overlap] = aid1825kForkRace([
        'resume' => fn (): string => app(VoucherSaleService::class)->sellVoucher($request)->outcome->value,
        'edit'   => function () use ($prepared): string {
            try {
                Invoice::query()->whereKey($prepared->id)->firstOrFail()->update(['total_amount' => cents(99900)]);

                return 'edited';
            } catch (SaleClaimedException) {
                return 'claimed';
            } catch (Exception $e) {
                return str_contains($e->getMessage(), 'immutable') || str_contains($e->getMessage(), 'ISSUED') ? 'immutable' : 'other:'.$e::class;
            }
        },
    ]);

    expect($overlap)->toBeTrue()
        ->and(($tally['edit:edited'] ?? 0) + ($tally['edit:retried-edited'] ?? 0))->toBe(0, 'tally: '.json_encode($tally))
        ->and(($tally['resume:sold'] ?? 0) + ($tally['resume:retried-sold'] ?? 0))->toBe(1, 'tally: '.json_encode($tally));

    aid1825kAssertOneSale($this->customer, (string) $proforma->id);
    expect($prepared->fresh()->total_amount->unscaledValue())->toBe($total);
})->group('concurrency');

it('resume vs cancelPreparedInvoice() by staff on the PREPARED sale invoice: the cancellation is refused whatever the instant, the sale completes', function () {
    $proforma = aid1825kProforma($this->customer);
    $request  = aid1825kRequest($proforma, $this->customer, 'vs-cancel-prepared');

    VoucherSaleClaim::saving(fn (VoucherSaleClaim $c) => $c->stage === VoucherSaleStage::Issuing ? false : null);
    try {
        app(VoucherSaleService::class)->sellVoucher($request);
        test()->fail('the sale should have stopped before ISSUING');
    } catch (LogicException) {
    } finally {
        VoucherSaleClaim::flushEventListeners();
        VoucherSaleClaim::clearBootedModels();
    }
    $prepared = Invoice::query()->whereNotNull('sale_claim_id')->firstOrFail();

    [$tally, $overlap] = aid1825kForkRace([
        'resume' => fn (): string => app(VoucherSaleService::class)->sellVoucher($request)->outcome->value,
        'cancel' => function () use ($prepared): string {
            try {
                app(InvoiceService::class)->cancelPreparedInvoice(Invoice::query()->whereKey($prepared->id)->firstOrFail(), 'staff');

                return 'cancelled';
            } catch (SaleClaimedException) {
                return 'claimed';
            } catch (Throwable $e) {
                return 'refused:'.class_basename($e);
            }
        },
    ]);

    expect($overlap)->toBeTrue()
        ->and(($tally['cancel:cancelled'] ?? 0) + ($tally['cancel:retried-cancelled'] ?? 0))->toBe(0, 'tally: '.json_encode($tally))
        ->and(($tally['resume:sold'] ?? 0) + ($tally['resume:retried-sold'] ?? 0))->toBe(1, 'tally: '.json_encode($tally));

    aid1825kAssertOneSale($this->customer, (string) $proforma->id);
})->group('concurrency');

it('resume (claimed, PENDING) vs convertAndIssue() by staff: the converter is always refused as claimed, the sale completes', function () {
    $proforma = aid1825kProforma($this->customer);
    $request  = aid1825kRequest($proforma, $this->customer, 'vs-convert-claimed');

    // Parent: stop at S2 (a vetoed PREPARED-row save rolls S2 back; S1 is committed) — the claim exists, nothing converted yet:
    // the window a plain race almost never hits.
    Invoice::saving(fn (Invoice $i) => ! $i->exists && $i->invoice_document_status === InvoiceDocumentStatus::PREPARED ? false : null);
    try {
        app(VoucherSaleService::class)->sellVoucher($request);
        test()->fail('the sale should have stopped at S2');
    } catch (LogicException) {
    } finally {
        Invoice::flushEventListeners();
        Invoice::clearBootedModels();
    }
    expect(VoucherSaleClaim::query()->count())->toBe(1)
        ->and($proforma->fresh()->status)->toBe(InvoiceStatus::SENT);

    [$tally, $overlap] = aid1825kForkRace([
        'resume'  => fn (): string => app(VoucherSaleService::class)->sellVoucher($request)->outcome->value,
        'convert' => function () use ($proforma): string {
            app(InvoiceService::class)->convertAndIssue(Invoice::query()->whereKey($proforma->id)->firstOrFail());

            return 'issued';
        },
    ]);

    expect($overlap)->toBeTrue()
        ->and(($tally['convert:issued'] ?? 0) + ($tally['convert:retried-issued'] ?? 0))->toBe(0, 'tally: '.json_encode($tally))
        ->and(($tally['resume:sold'] ?? 0) + ($tally['resume:retried-sold'] ?? 0))->toBe(1, 'tally: '.json_encode($tally))
        ->and(Invoice::query()->where('serie', InvoiceSerieType::INVOICE->value)->count())->toBe(1);

    aid1825kAssertOneSale($this->customer, (string) $proforma->id);
})->group('concurrency');
