<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\AgreementGuaranteeOperation;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\GuaranteeService;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-953 slice 2 (ADR-015 §3.4 / §6, gate round 14 P3) — end-to-end fork
 * proof for the guarantee settlement invariant.
 *
 * `tests/Unit/Services/GuaranteeServiceTest` covers the two-phase
 * collision-and-adoption deterministically (winner simulated by a
 * pre-stamped rectificative). This is the empirical confirmation under REAL
 * concurrency: N OS processes call settle() over the SAME pending registry
 * row at one wall-clock instant, and the invariant must hold — exactly ONE
 * rectificative is issued for the claim (the UNIQUE
 * `invoices.settlement_operation_key` index is the serialisation point;
 * gate round 13 P1), every loser adopts by key or completes as an idempotent
 * no-op, and no child ever escapes an UNCONTROLLED error.
 *
 * The §3.4 boundary this pins: Phase 1 (issuance) commits in an INDEPENDENT
 * transaction with NO registry lock held across it — on the default
 * connection a nested independent transaction is a savepoint, so a lock held
 * "across" issuance could not survive the commit (gate round 12 P1).
 *
 * Scenario "Phase-2 failure after a committed Phase 1 is adopted on retry"
 * is deterministic, not forked: the winner's issuance is planted
 * (SettlementService::rectify() with the claim's key), the registry row is
 * left pending with no link — exactly the committed state a crashed Phase 2
 * leaves — and a retry must adopt instead of issuing a second correction.
 *
 * Same harness rules as the other fork tests (AID-390/AID-700/AID-836): no
 * RefreshDatabase (children must read parent-committed rows on their own
 * connections), bound to MysqlIntegrationTestCase (tests/Pest.php), released
 * on an absolute-time barrier (STD-004). CI runs it against every engine in
 * the db-integration matrix. Run on demand:
 *   RUN_CONCURRENCY_IT=1 LARABILL_TEST_MYSQL_*=… vendor/bin/pest tests/Concurrency
 */
beforeEach(function () {
    if (getenv('RUN_CONCURRENCY_IT') !== '1') {
        test()->markTestSkipped('concurrency IT disabled (set RUN_CONCURRENCY_IT=1)');
    }

    // Anti-skip guard (AID-264/AID-258): the gate is ARMED — from here, every
    // precondition that used to skip must FAIL loud, because a gated run that
    // reports skips proved nothing.
    if (! function_exists('pcntl_fork')) {
        test()->fail('RUN_CONCURRENCY_IT=1 armed but the pcntl extension is unavailable — the concurrency gate would silently prove nothing.');
    }

    if (! MysqlIntegrationTestCase::mysqlEnvAvailable()) {
        test()->fail('RUN_CONCURRENCY_IT=1 armed but the LARABILL_TEST_MYSQL_* env is not configured — the concurrency gate would silently prove nothing.');
    }

    // Schema committed via artisan migrate (no RefreshDatabase wraps this).
    $this->bootstrap();
});

/**
 * Number of concurrent settlers. Ceiling: the emission boundary serializes
 * the children behind numbering + the unique-key collision (sub-second each)
 * against innodb_lock_wait_timeout (50 s); the constraint is wall-clock
 * cost. 8 children give real contention without inflating every matrix run;
 * overridable for local stress runs.
 */
function guaranteeForkCount(): int
{
    return (int) (getenv('LARABILL_CONCURRENCY_FORKS') ?: 8);
}

/**
 * The full fixture: guarantee-carrying product, active agreement, paid
 * source invoice, and the PENDING registry row. Returns
 * [agreement, operation].
 *
 * Built on the raw models (no ArticleServiceStatusFactory: its definition
 * resolves the configured user model's factory, and this harness's
 * consumer-shaped MysqlUser has none — the customer id is inserted directly).
 *
 * @return array{0: ArticleServiceStatus, 1: AgreementGuaranteeOperation}
 */
function guaranteeSettlementFixture(): array
{
    $owner    = test()->seedUser();
    $billable = test()->seedUser();

    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    // Tax-free article (no tax group): the rectificative line carries zero
    // tax, so the settlement path needs no fixture catalogue.
    $article = Article::factory()
        ->withGuarantee(14)
        ->create();

    $agreement = ArticleServiceStatus::query()->create([
        'customer_id'      => $billable,
        'article_id'       => $article->id,
        'started_at'       => now()->toDateString(),
        'status'           => AgreementStatus::ACTIVE,
        'modality'         => 'recurring',
        'renewal'          => 'auto',
        'billing_unit'     => 'month',
        'billing_quantity' => 1,
        'effective_price'  => FixedDecimal::ofUnscaled(10000, 2),
        'guarantee_days'   => 14,
        'metadata'         => [],
    ]);

    $original = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $billable,
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [
            [
                'article_id'  => $article->id,
                'quantity'    => 100,
                'base_price'  => 10000,
                'description' => 'Fork fixture service period',
            ],
        ],
    ], ['make_immutable' => true]);

    $operation = app(GuaranteeService::class)->openOperation($agreement, $original, 5000);

    return [$agreement, $operation];
}

it('exactly one rectificative survives N settlers racing the same pending registry row', function () {
    [, $operation] = guaranteeSettlementFixture();
    $key           = app(GuaranteeService::class)->operationKey($operation);

    $childCount = guaranteeForkCount();
    $resultDir  = sys_get_temp_dir().'/larabill_guarantee_'.Str::random(8);
    mkdir($resultDir, 0755, true);

    // STD-004: absolute-time barrier — every child released at one instant.
    $startAt = microtime(true) + 1.0;

    $pids = [];
    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // Never share the parent's PDO across the fork.
            DB::purge('testing');
            time_sleep_until($startAt);

            try {
                $rectificative = app(GuaranteeService::class)->settle($operation);
                file_put_contents(
                    $resultDir.'/'.getmypid().'.invoice',
                    (string) ($rectificative?->id ?? '')
                );
                exit(0); // issued (winner) or adopted (loser) — both controlled
            } catch (Throwable $e) {
                // Record the real cause: a mute exit(1) turns every failure
                // into "N children did not converge", which is undiagnosable.
                file_put_contents(
                    $resultDir.'/'.getmypid().'.err',
                    get_class($e).': '.$e->getMessage()
                );
                exit(1); // UNCONTROLLED — the invariant failed to converge
            }
        }

        $pids[] = $pid;
    }

    $settled = $uncontrolled = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        $code === 0 ? $settled++ : $uncontrolled++;
    }

    // Every child converged under control (issued, adopted or no-op).
    if ($uncontrolled > 0) {
        $errors = glob($resultDir.'/*.err') ?: [];
        $detail = implode("\n", array_map(fn (string $f): string => basename($f).': '.(string) file_get_contents($f), $errors));
        test()->fail("$uncontrolled of $childCount children died uncontrolled:\n$detail");
    }
    expect($settled)->toBe($childCount);

    // Re-read committed state on a fresh connection.
    DB::purge('testing');

    // THE INVARIANT: exactly ONE fiscal correction for the claim.
    $corrections = Invoice::query()->where('settlement_operation_key', $key)->get();
    expect($corrections)->toHaveCount(1)
        ->and($corrections->first()->serie)->toBe(InvoiceSerieType::RECTIFICATIVE)
        ->and($corrections->first()->rectifies_invoice_id)->toBe($operation->original_invoice_id);

    // Every child reported the SAME artifact (winner's id or adopted id).
    $reported = array_values(array_filter(array_map(
        fn (string $f): string => trim((string) file_get_contents($f)),
        glob($resultDir.'/*.invoice') ?: []
    )));
    expect($reported)->each->toBe($corrections->first()->id);

    // Phase 2 completed the registry; the refund is NOT confirmed yet.
    $operation->refresh();
    expect($operation->rectifies_invoice_id)->toBe($corrections->first()->id)
        ->and($operation->status)->toBe(AgreementGuaranteeOperation::STATUS_PENDING)
        ->and($operation->pending_slot)->toBe(AgreementGuaranteeOperation::PENDING_SLOT);
})->group('concurrency');

it('adopts a committed Phase-1 issuance on retry after a Phase-2 failure', function () {
    [$agreement, $operation] = guaranteeSettlementFixture();
    $original                = $operation->originalInvoice;
    $key                     = app(GuaranteeService::class)->operationKey($operation);

    // The winner: Phase 1 ISSUED and committed, the process died before
    // Phase 2 — the registry row is still pending with no link.
    $winner = app(SettlementService::class)->rectify($original, -5000, $key);

    expect($winner->settlement_operation_key)->toBe($key)
        ->and($operation->fresh()->rectifies_invoice_id)->toBeNull()
        ->and($agreement->fresh()->status)->toBe(AgreementStatus::GUARANTEE);

    // Retry: the loser's own issuance collides on the UNIQUE key and adopts
    // the winner's artifact — never a second fiscal correction.
    $adopted = app(GuaranteeService::class)->settle($operation);

    expect($adopted?->id)->toBe($winner->id)
        ->and(Invoice::query()->where('settlement_operation_key', $key)->count())->toBe(1)
        ->and($operation->fresh()->rectifies_invoice_id)->toBe($winner->id)
        ->and($operation->fresh()->status)->toBe(AgreementGuaranteeOperation::STATUS_PENDING);

    // A further settle on the completed registry is an idempotent no-op.
    $again = app(GuaranteeService::class)->settle($operation);

    expect($again?->id)->toBe($winner->id)
        ->and(Invoice::query()->where('settlement_operation_key', $key)->count())->toBe(1);
})->group('concurrency');

it('resumes a committed Phase-1 durable DRAFT on retry: the registry never links an unnumbered artifact (gate round 2)', function () {
    [$agreement, $operation] = guaranteeSettlementFixture();
    $original                = $operation->originalInvoice;
    $key                     = app(GuaranteeService::class)->operationKey($operation);

    // The winner: Phase 1 committed ONLY the durable unnumbered DRAFT (the
    // issuance phase failed) and the process died before Phase 2.
    $winnerDraft = app(InvoiceService::class)->createInvoice([
        'billable_user_id'         => (string) $original->billable_user_id,
        'type'                     => 'rectificative',
        'rectifies_invoice_id'     => $original->id,
        'settlement_operation_key' => $key,
        'status'                   => 'draft',
    ]);

    expect($winnerDraft->invoice_document_status)->toBe(InvoiceDocumentStatus::DRAFT)
        ->and($winnerDraft->fiscal_number)->toBeNull();

    // The loser's settle(): rectify()'s keyed resume branch issues the DRAFT
    // (never a second artifact) and the registry links an ISSUED document.
    $adopted = app(GuaranteeService::class)->settle($operation);

    expect($adopted?->id)->toBe($winnerDraft->id)
        ->and($adopted?->refresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($adopted?->refresh()->fiscal_number)->not->toBeNull()
        ->and(Invoice::query()->where('settlement_operation_key', $key)->count())->toBe(1)
        ->and($operation->fresh()->rectifies_invoice_id)->toBe($winnerDraft->id);
})->group('concurrency');
