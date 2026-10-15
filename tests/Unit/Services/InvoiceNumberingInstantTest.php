<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Services\InvoiceNumberingService;
use AichaDigital\Larabill\Support\RegionalContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR-016 §6.1 (PR-2, rounds 2/3 B1 and B1-residual) — the issuance instant
 * governs EVERYTHING the numbering service derives: the counter lookup and
 * creation, the STORED fiscal-year range (including first use, the measured
 * hole at the old `:247`), and the `{{TIMESTAMP}}` placeholder. The public
 * `generateNumber()` keeps its own transaction; the internal
 * `generateNumberInTransaction()` assumes the ambient one (round 1, S1).
 */
beforeEach(function () {
    $this->service = new InvoiceNumberingService;
});

it('threads the instant through generateNumber: fiscal_year and {{TIMESTAMP}} derive from it', function () {
    Carbon::setTestNow('2026-03-15 10:00:00');

    // A format that carries the placeholder: the default template does not.
    InvoiceSeriesControl::create([
        'prefix'            => 'STAMP',
        'serie'             => InvoiceSerieType::INVOICE->value,
        'fiscal_year'       => 2025,
        'fiscal_year_start' => '2025-01-01',
        'fiscal_year_end'   => '2025-12-31',
        'last_number'       => 0,
        'start_number'      => 1,
        'reset_annually'    => true,
        'number_format'     => '{{PREFIX}}-{{YEAR}}-{{NUMBER}}-{{TIMESTAMP}}',
        'is_active'         => true,
    ]);

    $instant = Illuminate\Support\Carbon::create(2025, 11, 20, 23, 59, 59);
    $number  = $this->service->generateNumber('STAMP', InvoiceSerieType::INVOICE->value, null, $instant);

    // The number carries the INSTANT's fiscal year and its {{TIMESTAMP}} —
    // never the wall clock's (round 2, B1).
    expect($number->fiscalYear)->toBe(2025)
        ->and((string) $number)->toContain('20251120235959')
        ->and((string) $number)->not->toContain('20260315100000');

    Carbon::setTestNow();
});

it('derives the first-use control range from the instant (B1-residual: the :247 hole)', function () {
    // A non-calendar fiscal year: each fiscal year starts on July 1.
    config()->set('larabill.fiscal_year.start_month', 7);
    config()->set('larabill.fiscal_year.start_day', 1);

    // First use keyed to fiscal year 2026 (instant 2027-06-30: the last day
    // of fiscal 2026 under a July-1 start). The STORED range must be fiscal
    // 2026's — 2026-07-01..2027-06-30 — not the wall clock's.
    $instant = Illuminate\Support\Carbon::create(2027, 6, 30, 23, 59, 59);
    $number  = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value, null, $instant);

    $control = InvoiceSeriesControl::where('prefix', 'FAC')->sole();
    expect($control->fiscal_year)->toBe(2026)
        ->and($control->fiscal_year_start->toDateString())->toBe('2026-07-01')
        ->and($control->fiscal_year_end->toDateString())->toBe('2027-06-30')
        ->and($number->fiscalYear)->toBe(2026);

    config()->set('larabill.fiscal_year.start_month', 1);
    config()->set('larabill.fiscal_year.start_day', 1);
});

it('keeps the counter lookup keyed to the instant across a fiscal-year boundary crossing', function () {
    config()->set('larabill.fiscal_year.start_month', 7);
    config()->set('larabill.fiscal_year.start_day', 1);

    // First use on the LAST day of fiscal 2026...
    $juneInstant = Illuminate\Support\Carbon::create(2027, 6, 30, 23, 59, 59);
    $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value, null, $juneInstant);

    // ...then a crossing issuance a second later, in fiscal 2027: the
    // counter must key the NEW fiscal year (annual reset), and the stored
    // range must come from the NEW instant, never a fresh now().
    Carbon::setTestNow('2027-07-01 00:00:30');

    $crossing = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value, null, Carbon::parse('2027-07-01 00:00:00'));

    $crossingControl = InvoiceSeriesControl::where('prefix', 'FAC')->where('fiscal_year', 2027)->sole();
    expect($crossing->fiscalYear)->toBe(2027)
        ->and($crossing->seriesNumber)->toBe(1)
        ->and($crossingControl->fiscal_year_start->toDateString())->toBe('2027-07-01')
        ->and($crossingControl->fiscal_year_end->toDateString())->toBe('2028-06-30');

    config()->set('larabill.fiscal_year.start_month', 1);
    config()->set('larabill.fiscal_year.start_day', 1);
    Carbon::setTestNow();
});

it('opens no transaction of its own: generateNumberInTransaction asserts the ambient one (S1)', function () {
    // RefreshDatabase keeps the default connection inside its wrapping
    // transaction (level 1) for the whole test — the level-0 refusal is only
    // reachable on a connection with no ambient transaction, so point the
    // default at a fresh one for this check.
    config()->set('database.connections.ambientless', [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ]);
    config()->set('database.default', 'ambientless');
    DB::purge('ambientless');

    expect(fn () => $this->service->generateNumberInTransaction('FAC', InvoiceSerieType::INVOICE->value))
        ->toThrow(LogicException::class, 'AMBIENT');

    // Restore the wrapped connection WITHOUT purging it — the RefreshDatabase
    // transaction and the in-memory tables live on the existing instance.
    config()->set('database.default', 'testing');

    $number = DB::transaction(fn () => $this->service->generateNumberInTransaction('FAC', InvoiceSerieType::INVOICE->value));
    expect($number->seriesNumber)->toBe(1)
        // ...and the wrapper still opens and commits its own for direct callers.
        ->and($this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value)->seriesNumber)->toBe(2);
});

it('keeps the v6 default: no instant passed means now() governs (additive parameter, non-breaking)', function () {
    Carbon::setTestNow('2026-05-05 05:05:05');

    InvoiceSeriesControl::create([
        'prefix'            => 'STAMP',
        'serie'             => InvoiceSerieType::INVOICE->value,
        'fiscal_year'       => 2026,
        'fiscal_year_start' => '2026-01-01',
        'fiscal_year_end'   => '2026-12-31',
        'last_number'       => 0,
        'start_number'      => 1,
        'reset_annually'    => true,
        'number_format'     => '{{PREFIX}}-{{YEAR}}-{{NUMBER}}-{{TIMESTAMP}}',
        'is_active'         => true,
    ]);

    $number = $this->service->generateNumber('STAMP', InvoiceSerieType::INVOICE->value);

    expect($number->fiscalYear)->toBe(2026)
        ->and((string) $number)->toContain('20260505050505');

    Carbon::setTestNow();
});

it('keeps the fiscal-year coherence invariant of the spec: fiscal_year == RegionalContext::getFiscalYear(instant)', function () {
    foreach ([now(), now()->subYear(), now()->addMonth()] as $instant) {
        $number = $this->service->generateNumber('BOUND', InvoiceSerieType::INVOICE->value, null, $instant);

        expect($number->fiscalYear)->toBe(RegionalContext::getFiscalYear($instant))
            // Clean up: each iteration keys a distinct control row by year.
            ->and(InvoiceSeriesControl::where('prefix', 'BOUND')->where('fiscal_year', $number->fiscalYear)->exists())->toBeTrue();
    }
});
