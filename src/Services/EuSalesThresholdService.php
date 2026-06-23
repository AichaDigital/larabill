<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\EuSalesThreshold;
use AichaDigital\Larabill\Models\Invoice;
use Illuminate\Support\Facades\Log;

/**
 * EU Sales Threshold Service
 *
 * Handles the logic for tracking EU sales and managing the 10,000€ threshold
 * for companies that need to register in OSS (One Stop Shop).
 *
 * @note Refactored in ADR-001 to use CompanyFiscalConfig instead of FiscalSettings
 */
class EuSalesThresholdService
{
    /**
     * Process an invoice and update EU sales counter if applicable.
     */
    public function processInvoice(Invoice $invoice): void
    {
        if ($invoice->is_roi_taxed) {
            Log::info('Invoice is ROI taxed, skipping EU sales threshold update', [
                'invoice_number' => $invoice->fiscal_number,
            ]);

            return;
        }

        if (! $this->isEuSale($invoice)) {
            Log::info('Invoice is not EU sale, skipping threshold update', [
                'invoice_number' => $invoice->fiscal_number,
            ]);

            return;
        }

        $config = CompanyFiscalConfig::getActive();

        if ($config?->is_oss) {
            Log::info('Company is already OSS registered, skipping threshold update', [
                'invoice_number' => $invoice->fiscal_number,
            ]);

            return;
        }

        $userId     = (string) ($invoice->user_id ?? config('larabill.company.id', '1'));
        $fiscalYear = (int) ($invoice->fiscal_year ?? date('Y'));

        $threshold = EuSalesThreshold::getOrCreateForUser($userId, $fiscalYear);
        $threshold->addAmount($invoice->taxable_amount);

        Log::info('EU sales threshold updated', [
            'invoice_number' => $invoice->fiscal_number,
            'amount'         => $invoice->taxable_amount->unscaledValue(),
        ]);
    }

    /**
     * Process an invoice refund/credit and decrease EU sales counter if applicable.
     */
    public function processInvoiceRefund(Invoice $invoice): void
    {
        if ($invoice->is_roi_taxed) {
            return;
        }

        if (! $this->isEuSale($invoice)) {
            return;
        }

        $config = CompanyFiscalConfig::getActive();

        if ($config?->is_oss) {
            return;
        }

        $userId     = (string) ($invoice->user_id ?? config('larabill.company.id', '1'));
        $fiscalYear = (int) ($invoice->fiscal_year ?? date('Y'));

        $threshold = EuSalesThreshold::getOrCreateForUser($userId, $fiscalYear);
        $threshold->addAmount($invoice->taxable_amount->negated());

        Log::info('EU sales threshold updated (refund)', [
            'invoice_number' => $invoice->fiscal_number,
            'amount'         => -$invoice->taxable_amount->unscaledValue(),
        ]);
    }

    /**
     * Check if an invoice represents a sale to EU customer.
     */
    private function isEuSale(Invoice $invoice): bool
    {
        $userTaxInfo = $invoice->user_tax_info_encrypted;
        if (! $userTaxInfo) {
            return false;
        }

        $taxInfo = $userTaxInfo;

        if (is_string($taxInfo)) {
            $taxInfo = json_decode($taxInfo, true);
        }

        $countryCode = $taxInfo['country_code'] ?? null;

        if (! $countryCode) {
            return false;
        }

        $euCountries = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];

        $euCountries = array_diff($euCountries, ['ES']);

        return in_array(strtoupper($countryCode), $euCountries);
    }

    /**
     * Check if company should receive threshold notification.
     */
    public function shouldSendNotification(string $userId, int $fiscalYear): bool
    {
        $config = CompanyFiscalConfig::getActive();

        if ($config?->is_oss) {
            return false;
        }

        $threshold = EuSalesThreshold::findByUserAndYear($userId, $fiscalYear);

        if (! $threshold) {
            return false;
        }

        if ($threshold->notification_sent) {
            return false;
        }

        return $threshold->threshold_exceeded;
    }

    /**
     * Send threshold notification.
     */
    public function sendThresholdNotification(string $userId, int $fiscalYear): void
    {
        if (! $this->shouldSendNotification($userId, $fiscalYear)) {
            return;
        }

        $threshold = EuSalesThreshold::findByUserAndYear($userId, $fiscalYear);

        if (! $threshold) {
            return;
        }

        Log::warning('EU Sales Threshold Exceeded - OSS Registration Required', [
            'user_id'        => $userId,
            'fiscal_year'    => $fiscalYear,
            'current_amount' => $threshold->total_amount,
        ]);

        $threshold->update(['notification_sent' => true]);
    }

    /**
     * Reset EU sales for new fiscal year.
     */
    public function resetForNewFiscalYear(string $userId, int $oldYear, int $newYear): void
    {
        EuSalesThreshold::getOrCreateForUser($userId, $newYear);

        Log::info('EU sales threshold reset for new fiscal year', [
            'user_id'  => $userId,
            'old_year' => $oldYear,
            'new_year' => $newYear,
        ]);
    }

    /**
     * Get current threshold status.
     *
     * @return array<string, mixed>
     */
    public function getThresholdStatus(string $userId, int $fiscalYear): array
    {
        $threshold = EuSalesThreshold::findByUserAndYear($userId, $fiscalYear);
        $config    = CompanyFiscalConfig::getActive();

        $defaultThreshold = (int) config('larabill.eu_sales_threshold', 1000000); // €10,000 in base-100

        return [
            'current_amount'     => $threshold ? $threshold->total_amount : FixedDecimal::zero(2),
            'threshold'          => FixedDecimal::ofUnscaled($defaultThreshold, 2),
            'exceeded'           => $threshold ? $threshold->threshold_exceeded : false,
            'needs_notification' => $this->shouldSendNotification($userId, $fiscalYear),
            'is_oss_registered'  => $config ? $config->is_oss : false,
            'fiscal_year'        => $fiscalYear,
        ];
    }

    /**
     * Calculate EU sales from existing invoices (for daily/weekly tasks).
     */
    public function recalculateEuSales(int $fiscalYear): FixedDecimal
    {
        // taxable_amount is an integer base-100 column, so sum() returns minor units.
        $totalEuSales = (int) Invoice::where('is_roi_taxed', false)
            ->whereYear('created_at', $fiscalYear)
            ->sum('taxable_amount');

        Log::info('EU sales recalculated from invoices', [
            'fiscal_year' => $fiscalYear,
            'total_sales' => $totalEuSales,
        ]);

        return FixedDecimal::ofUnscaled($totalEuSales, 2);
    }
}
