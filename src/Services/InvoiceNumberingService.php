<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Support\RegionalContext;
use AichaDigital\Larabill\ValueObjects\InvoiceNumber;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
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
 *
 * AID-390 hardening:
 * - First use of a series is race-safe: creation recovers from the unique
 *   constraint when a concurrent transaction wins, and the transaction is
 *   retried on deadlock (gap-lock contention on the empty range).
 * - The issuer scope is exact: a NULL scope is normalized to
 *   InvoiceSeriesControl::GLOBAL_SCOPE so the unique index always applies
 *   (NULL never collides in MySQL/SQLite unique indexes).
 */
class InvoiceNumberingService
{
    /**
     * Generate next fiscal invoice number
     *
     * Uses database transaction with pessimistic lock to ensure atomicity.
     * Creates series control on first use (idempotently under concurrency).
     *
     * @param  string  $prefix  User customizable prefix (FAC, PRO, RECT, etc.)
     * @param  int  $serie  InvoiceSerieType enum value (0=proforma, 1=invoice, 2=rectificative)
     * @param  int|string|null  $userId  ISSUER scope for multi-issuer setups
     *                                   (null = global issuer series). Never
     *                                   the billed customer (see ADR-003:
     *                                   customers are billable_user_id).
     * @return InvoiceNumber Invoice number value object (string-castable for backward compat)
     */
    public function generateNumber(string $prefix, int $serie, int|string|null $userId = null): InvoiceNumber
    {
        $scopeId = $this->normalizeScope($userId);

        return DB::transaction(function () use ($prefix, $serie, $userId, $scopeId) {
            $fiscalYearData = $this->getCurrentFiscalYearData();
            $fiscalYear     = $fiscalYearData['year'];

            $control = $this->getOrCreateControlForUpdate($prefix, $serie, $fiscalYear, $scopeId);

            // Handle annual reset: when the stored fiscal year differs, the counter
            // restarts from start_number. Computed locally; the reset is persisted
            // by the update below ($fiscalYearData).
            $lastNumber = $control->last_number;
            if ($control->reset_annually && $control->fiscal_year !== $fiscalYear) {
                $lastNumber = $control->start_number - 1;
            }

            $nextNumber = $lastNumber + 1;

            $control->update([
                'last_number'       => $nextNumber,
                'fiscal_year'       => $fiscalYear,
                'fiscal_year_start' => $fiscalYearData['start']->toDateString(),
                'fiscal_year_end'   => $fiscalYearData['end']->toDateString(),
                'last_used_at'      => now(),
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
        }, 3);
    }

    /**
     * Normalize the issuer scope: NULL means the global issuer series and is
     * stored as the GLOBAL_SCOPE sentinel so the unique index always bites.
     */
    protected function normalizeScope(int|string|null $userId): string
    {
        return $userId === null
            ? InvoiceSeriesControl::GLOBAL_SCOPE
            : (string) $userId;
    }

    /**
     * Read the series control under a pessimistic lock, creating it on first
     * use. Safe under concurrency: if a concurrent transaction wins the
     * creation race, the unique violation is caught and the winner's row is
     * re-read (the duplicate-key check blocks until the winner commits, so
     * the re-read sees it). Gap-lock deadlocks on the empty range abort the
     * whole transaction, which DB::transaction() retries.
     */
    protected function getOrCreateControlForUpdate(
        string $prefix,
        int $serie,
        int $fiscalYear,
        string $scopeId
    ): InvoiceSeriesControl {
        $control = $this->lockedControlQuery($prefix, $serie, $fiscalYear, $scopeId)->first();

        if ($control !== null) {
            return $control;
        }

        try {
            return $this->createSeriesControl($prefix, $serie, $fiscalYear, $scopeId);
        } catch (UniqueConstraintViolationException) {
            // Lost the creation race to a concurrent transaction.
        }

        $control = $this->lockedControlQuery($prefix, $serie, $fiscalYear, $scopeId)->first();

        if ($control === null) {
            throw new \RuntimeException('Failed to create or read back the invoice series control.');
        }

        return $control;
    }

    /**
     * Locked lookup for one exact series scope. The scope match is exact on
     * purpose: global and user-scoped series are independent sequences.
     *
     * @return Builder<InvoiceSeriesControl>
     */
    protected function lockedControlQuery(
        string $prefix,
        int $serie,
        int $fiscalYear,
        string $scopeId
    ): Builder {
        return InvoiceSeriesControl::query()
            ->where('prefix', $prefix)
            ->where('serie', $serie)
            ->where('fiscal_year', $fiscalYear)
            ->where('user_id', $scopeId)
            ->lockForUpdate();
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
     * @param  int|string|null  $userId  Optional issuer scope (as provided by the caller)
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
     * @param  string  $scopeId  Normalized issuer scope (user UUID or GLOBAL_SCOPE)
     */
    protected function createSeriesControl(
        string $prefix,
        int $serie,
        int $fiscalYear,
        string $scopeId
    ): InvoiceSeriesControl {
        $fiscalYearData = $this->getCurrentFiscalYearData();

        // Continuity seed (AID-390): invoices issued by the legacy numbering
        // paths (rand()/cache/MAX+1) predate any control row. The counter
        // must CONTINUE from the highest issued series_number for this serie
        // and fiscal year — a gap is acceptable, a reused number never is.
        $issuedMax = (int) Invoice::query()
            ->where('serie', $serie)
            ->where('fiscal_year', $fiscalYear)
            ->max('series_number');

        return InvoiceSeriesControl::create([
            'prefix'            => $prefix,
            'serie'             => $serie,
            'fiscal_year'       => $fiscalYear,
            'fiscal_year_start' => $fiscalYearData['start']->toDateString(),
            'fiscal_year_end'   => $fiscalYearData['end']->toDateString(),
            'last_number'       => $issuedMax,
            'start_number'      => 1,
            'reset_annually'    => true,
            'number_format'     => '{{PREFIX}}-{{YEAR}}-{{NUMBER}}',
            'is_active'         => true,
            'description'       => "Auto-created series control for {$prefix} serie {$serie}",
            'validation_rules'  => null,
            'user_id'           => $scopeId,
        ]);
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
