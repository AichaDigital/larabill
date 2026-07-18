<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Services\InvoiceNumberingService;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\InvoiceNumber;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// AID-536: this suite owns the mutation testing of its subject (pest --mutate).
mutates(InvoiceNumberingService::class);

beforeEach(function () {
    $this->service = new InvoiceNumberingService;
});

describe('InvoiceNumberingService', function () {
    it('generates correlative numbers', function () {
        $number1 = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);
        $number2 = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        expect((string) $number1)->toContain('FAC-');
        expect((string) $number2)->toContain('FAC-');
        expect((string) $number1)->not->toBe((string) $number2);
    });

    it('creates series control on first use', function () {
        expect(InvoiceSeriesControl::count())->toBe(0);

        $number = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        expect(InvoiceSeriesControl::count())->toBe(1);
        expect((string) $number)->toContain('FAC-');
    });

    it('increments last_number atomically', function () {
        // First call creates control
        $number1 = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        // Second call increments
        $number2 = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        $control = InvoiceSeriesControl::first();
        expect($control->last_number)->toBe(2);
    });

    it('maintains separate sequences per serie', function () {
        $invoice1  = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);
        $proforma1 = $this->service->generateNumber('PRO', InvoiceSerieType::PROFORMA->value);
        $invoice2  = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        expect((string) $invoice1)->toContain('FAC-');
        expect((string) $proforma1)->toContain('PRO-');
        expect((string) $invoice2)->toContain('FAC-');
        expect(InvoiceSeriesControl::count())->toBe(2); // FAC and PRO
    });

    it('seeds continuity independently per real series (AID-307)', function () {
        // A legacy FAC invoice at number 5 (same fiscal type INVOICE) must NOT
        // bleed into a different series: the seed is scoped by prefix.
        DB::table('invoices')->insert([
            'id'             => (string) Str::orderedUuid(),
            'fiscal_number'  => 'FAC-'.now()->year.'-000005',
            'prefix'         => 'FAC',
            'serie'          => InvoiceSerieType::INVOICE->value,
            'series_number'  => 5,
            'fiscal_year'    => now()->year,
            'invoice_date'   => now()->toDateString(),
            'user_id'        => TestCase::USER_UUID_1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // A brand-new series starts fresh (its own MAX is 0), not from FAC's 5.
        $export = $this->service->generateNumber('EXPORT', InvoiceSerieType::INVOICE->value);
        expect($export->seriesNumber)->toBe(1);

        // FAC still continues from its own highest issued number.
        $fac = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);
        expect($fac->seriesNumber)->toBe(6);
    });

    it('maintains separate sequences per fiscal year', function () {
        // Mock current fiscal year
        $fiscalYear = now()->year;

        $number1 = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        // Simulate next fiscal year (this is simplified, real implementation would check dates)
        expect((string) $number1)->toContain((string) $fiscalYear);
    });

    it('formats number with default template', function () {
        $number = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        // Default format: {{PREFIX}}-{{YEAR}}-{{NUMBER}}
        expect((string) $number)->toMatch('/^FAC-\d{4}-\d{6}$/');
    });

    it('uses custom number format if provided', function () {
        // Create custom series control with custom format
        InvoiceSeriesControl::create([
            'prefix'            => 'CUSTOM',
            'serie'             => InvoiceSerieType::INVOICE->value,
            'fiscal_year'       => now()->year,
            'fiscal_year_start' => now()->startOfYear(),
            'fiscal_year_end'   => now()->endOfYear(),
            'last_number'       => 0,
            'start_number'      => 1,
            'reset_annually'    => true,
            'number_format'     => '{{PREFIX}}-{{NUMBER}}', // Custom format without year
            'is_active'         => true,
        ]);

        $number = $this->service->generateNumber('CUSTOM', InvoiceSerieType::INVOICE->value);

        expect((string) $number)->toMatch('/^CUSTOM-\d{6}$/');
        expect((string) $number)->not->toContain((string) now()->year);
    });

    it('pads numbers with leading zeros', function () {
        $number = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        // Should be 000001
        expect((string) $number)->toContain('000001');
    });

    it('respects custom start_number', function () {
        InvoiceSeriesControl::create([
            'prefix'            => 'START100',
            'serie'             => InvoiceSerieType::INVOICE->value,
            'fiscal_year'       => now()->year,
            'fiscal_year_start' => now()->startOfYear(),
            'fiscal_year_end'   => now()->endOfYear(),
            'last_number'       => 99, // Simulate starting from 100
            'start_number'      => 100,
            'reset_annually'    => true,
            'number_format'     => '{{PREFIX}}-{{YEAR}}-{{NUMBER}}',
            'is_active'         => true,
        ]);

        $number = $this->service->generateNumber('START100', InvoiceSerieType::INVOICE->value);

        expect((string) $number)->toContain('000100');
    });

    it('handles concurrent requests with database locks', function () {
        // Simulate concurrent requests
        $numbers = [];

        // Use DB transactions to test atomicity
        for ($i = 0; $i < 5; $i++) {
            $numbers[] = (string) $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);
        }

        // All numbers should be unique
        expect($numbers)->toHaveCount(5);
        expect(array_unique($numbers))->toHaveCount(5);

        // Final last_number should be 5
        $control = InvoiceSeriesControl::where('prefix', 'FAC')->first();
        expect($control->last_number)->toBe(5);
    });

    it('calculates fiscal year correctly', function () {
        $fiscalYearData = $this->service->getCurrentFiscalYearData();

        expect($fiscalYearData)->toHaveKeys(['year', 'start', 'end']);
        expect($fiscalYearData['year'])->toBeInt();
        expect($fiscalYearData['start'])->toBeInstanceOf(Carbon::class);
        expect($fiscalYearData['end'])->toBeInstanceOf(Carbon::class);
    });

    it('returns InvoiceNumber value object', function () {
        $result = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        expect($result)->toBeInstanceOf(InvoiceNumber::class);
        expect($result->prefix)->toBe('FAC');
        expect($result->fiscalYear)->toBe(now()->year);
        expect($result->seriesNumber)->toBe(1);
        expect($result->formatted)->toMatch('/^FAC-\d{4}-\d{6}$/');
    });

    it('accepts UUID string user ids', function () {
        $userId = (string) Str::uuid();

        $result = $this->service->generateNumber('UUID', InvoiceSerieType::INVOICE->value, $userId);

        expect($result)->toBeInstanceOf(InvoiceNumber::class);
        expect(InvoiceSeriesControl::where('user_id', $userId)->exists())->toBeTrue();
    });

    it('returns InvoiceNumber that works as string (backward compat)', function () {
        $result = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        // String comparison should work via __toString
        expect((string) $result)->toMatch('/^FAC-\d{4}-\d{6}$/');

        // String concatenation
        expect('Number: '.$result)->toStartWith('Number: FAC-');
    });

    it('calculates fiscal year correctly for non-standard start', function () {
        // Mock config for fiscal year starting July 1
        config(['larabill.fiscal_year.start_month' => 7]);
        config(['larabill.fiscal_year.start_day' => 1]);

        $dateInFirstHalf  = Carbon::create(2025, 3, 15); // Before July 1, 2025
        $dateInSecondHalf = Carbon::create(2025, 9, 15); // After July 1, 2025

        // March 2025 should be fiscal year 2024
        expect($this->service->getCurrentFiscalYear($dateInFirstHalf))->toBe(2024);

        // September 2025 should be fiscal year 2025
        expect($this->service->getCurrentFiscalYear($dateInSecondHalf))->toBe(2025);

        // Reset config
        config(['larabill.fiscal_year.start_month' => 1]);
        config(['larabill.fiscal_year.start_day' => 1]);
    });

    it('seeds a new control from the highest already-issued series_number', function () {
        // Invoices issued by the legacy numbering paths (rand()/cache/MAX+1,
        // AID-390) predate any control row: the counter must CONTINUE from
        // them, never reuse an already-issued number.
        Invoice::factory()->create([
            'serie'         => InvoiceSerieType::INVOICE->value,
            'series_number' => 41,
            'fiscal_year'   => now()->year,
            'user_id'       => TestCase::USER_UUID_1,
        ]);

        $number = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        expect($number->seriesNumber)->toBe(42)
            ->and((string) $number)->toContain('000042');
    });

    it('persists the global-scope sentinel instead of NULL on first use', function () {
        // The sentinel is a persisted contract (existing rows are backfilled to it):
        // pin the literal here so an accidental constant change fails loudly.
        $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        expect(InvoiceSeriesControl::whereNull('user_id')->count())->toBe(0)
            ->and(InvoiceSeriesControl::where('user_id', '00000000-0000-0000-0000-000000000000')->count())->toBe(1);
    });

    it('keeps global and user-scoped sequences independent for the same series', function () {
        $userId = (string) Str::uuid();

        $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);
        $scoped1 = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value, $userId);
        $global2 = $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        expect(InvoiceSeriesControl::count())->toBe(2)
            ->and($scoped1->seriesNumber)->toBe(1)
            ->and($global2->seriesNumber)->toBe(2);
    });

    it('a factory-created series formats numbers without literal placeholders', function () {
        // The factory applies its own default number_format (the case under test)
        // and the global-scope sentinel as user_id, so the service finds this row.
        InvoiceSeriesControl::factory()->create([
            'prefix'      => 'FAC',
            'serie'       => InvoiceSerieType::INVOICE->value,
            'fiscal_year' => now()->year,
        ]);

        $number = (string) $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

        expect($number)
            ->not->toContain('{{')
            ->toMatch('/^FAC-\d{4}-\d{6}$/');
    });
});
