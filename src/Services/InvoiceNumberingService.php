<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Support\RegionalContext;
use AichaDigital\Larabill\ValueObjects\InvoiceNumber;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Invoice Numbering Service
 *
 * Centralizes invoice number generation with fiscal compliance.
 * Uses database locks for atomic correlative numbering.
 *
 * v0.3.3: Implements CEE/EU correlative numbering requirements:
 * - Atomic operations with pessimistic locking
 * - Per-serie and per-fiscal-year sequences
 * - Customizable number formats (Mustache templates)
 * - Fiscal year calculations based on regional config
 */
class InvoiceNumberingService
{
    /**
     * Generate next fiscal invoice number
     *
     * Uses database transaction with pessimistic lock to ensure atomicity.
     * Creates series control on first use.
     *
     * @param  string  $prefix  User customizable prefix (FAC, PRO, RECT, etc.)
     * @param  int  $serie  InvoiceSerieType enum value (0=proforma, 1=invoice, 2=rectificative)
     * @param  int|string|null  $userId  Optional user ID for multi-tenant
     * @return InvoiceNumber Invoice number value object (string-castable for backward compat)
     */
    public function generateNumber(string $prefix, int $serie, int|string|null $userId = null): InvoiceNumber
    {
        return DB::transaction(function () use ($prefix, $serie, $userId) {
            $fiscalYearData = $this->getCurrentFiscalYearData();
            $fiscalYear     = $fiscalYearData['year'];

            // Lock for update to prevent race conditions
            $control = DB::table('invoice_series_control')
                ->where('prefix', $prefix)
                ->where('serie', $serie)
                ->where('fiscal_year', $fiscalYear)
                ->where(function ($query) use ($userId) {
                    $query->whereNull('user_id');
                    if ($userId !== null) {
                        $query->orWhere('user_id', $userId);
                    }
                })
                ->lockForUpdate() // Acquire pessimistic lock
                ->first();

            // Create control if doesn't exist
            if (! $control) {
                $control = $this->createSeriesControl($prefix, $serie, $fiscalYear, $userId);
            }

            /** @var object{id: int, reset_annually: bool, fiscal_year: int, fiscal_year_start: string, fiscal_year_end: string, last_number: int, start_number: int, number_format: string} $control */

            // Handle annual reset: when the stored fiscal year differs, the counter
            // restarts from start_number. Computed locally — $control is a read-only
            // query object; the reset is persisted by the update below ($fiscalYearData).
            $lastNumber = $control->last_number;
            if ($control->reset_annually && $control->fiscal_year !== $fiscalYear) {
                $lastNumber = $control->start_number - 1;
            }

            // Increment
            $nextNumber = $lastNumber + 1;

            // Update control
            DB::table('invoice_series_control')
                ->where('id', $control->id)
                ->update([
                    'last_number'       => $nextNumber,
                    'fiscal_year'       => $fiscalYear,
                    'fiscal_year_start' => $fiscalYearData['start']->toDateString(),
                    'fiscal_year_end'   => $fiscalYearData['end']->toDateString(),
                    'last_used_at'      => now(),
                    'updated_at'        => now(),
                ]);

            // Format number
            $formatted = $this->formatNumber(
                $control->number_format,
                $prefix,
                $fiscalYear,
                $nextNumber,
                $userId
            );

            return new InvoiceNumber(
                formatted: $formatted,
                prefix: $prefix,
                fiscalYear: $fiscalYear,
                seriesNumber: $nextNumber,
            );
        });
    }

    /**
     * Format invoice number using Mustache-style template
     *
     * Available placeholders:
     * - {{PREFIX}}: User prefix (FAC, PRO, RECT)
     * - {{YEAR}}: Fiscal year (2025)
     * - {{NUMBER}}: Correlative number, padded with zeros (000047)
     * - {{TIMESTAMP}}: Current timestamp (20250110143025)
     * - {{USER_ID}}: User ID (if provided)
     *
     * @param  string  $template  Mustache template
     * @param  string  $prefix  Prefix
     * @param  int  $fiscalYear  Fiscal year
     * @param  int  $number  Correlative number
     * @param  int|string|null  $userId  Optional user ID
     * @return string Formatted number
     */
    protected function formatNumber(
        string $template,
        string $prefix,
        int $fiscalYear,
        int $number,
        int|string|null $userId = null
    ): string {
        $replacements = [
            '{{PREFIX}}'    => $prefix,
            '{{YEAR}}'      => (string) $fiscalYear,
            '{{NUMBER}}'    => str_pad((string) $number, 6, '0', STR_PAD_LEFT),
            '{{TIMESTAMP}}' => now()->format('YmdHis'),
            '{{USER_ID}}'   => $userId !== null ? (string) $userId : '',
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template
        );
    }

    /**
     * Create new series control
     *
     * @param  string  $prefix  Prefix
     * @param  int  $serie  Serie type
     * @param  int  $fiscalYear  Fiscal year
     * @param  int|string|null  $userId  Optional user ID
     * @return object Series control record
     */
    protected function createSeriesControl(
        string $prefix,
        int $serie,
        int $fiscalYear,
        int|string|null $userId = null
    ): object {
        $fiscalYearData = $this->getCurrentFiscalYearData();

        $id = DB::table('invoice_series_control')->insertGetId([
            'prefix'            => $prefix,
            'serie'             => $serie,
            'fiscal_year'       => $fiscalYear,
            'fiscal_year_start' => $fiscalYearData['start']->toDateString(),
            'fiscal_year_end'   => $fiscalYearData['end']->toDateString(),
            'last_number'       => 0,
            'start_number'      => 1,
            'reset_annually'    => true,
            'number_format'     => '{{PREFIX}}-{{YEAR}}-{{NUMBER}}',
            'is_active'         => true,
            'description'       => "Auto-created series control for {$prefix} serie {$serie}",
            'validation_rules'  => null,
            'user_id'           => $userId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $control = DB::table('invoice_series_control')->find($id);

        if (! is_object($control)) {
            throw new \RuntimeException('Failed to read back the just-created invoice series control.');
        }

        return $control;
    }

    /**
     * Get current fiscal year data
     *
     * @return array{year: int, start: Carbon, end: Carbon}
     */
    public function getCurrentFiscalYearData(?Carbon $date = null): array
    {
        $date       = $date ?? now();
        $fiscalYear = $this->getCurrentFiscalYear($date);

        $start = RegionalContext::getFiscalYearStart($fiscalYear);
        $end   = RegionalContext::getFiscalYearEnd($fiscalYear);

        return [
            'year'  => $fiscalYear,
            'start' => $start,
            'end'   => $end,
        ];
    }

    /**
     * Get current fiscal year for a given date
     *
     * @param  Carbon|null  $date  Date to check (default: now)
     * @return int Fiscal year
     */
    public function getCurrentFiscalYear(?Carbon $date = null): int
    {
        return RegionalContext::getFiscalYear($date ?? now());
    }

    /**
     * Check if date is within fiscal year
     *
     * @param  Carbon  $date  Date to check
     * @param  int  $fiscalYear  Fiscal year
     * @return bool True if within fiscal year
     */
    public function isWithinFiscalYear(Carbon $date, int $fiscalYear): bool
    {
        return RegionalContext::isWithinFiscalYear($date, $fiscalYear);
    }
}
