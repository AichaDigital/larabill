<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\{FiscalSettings, Invoice};
use Illuminate\Support\Facades\Log;

/**
 * EU Sales Threshold Service
 *
 * Handles the logic for tracking EU sales and managing the 10,000€ threshold
 * for companies that need to register in OSS (One Stop Shop).
 */
class EuSalesThresholdService
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        //
    }

    /**
     * Process an invoice and update EU sales counter if applicable.
     */
    public function processInvoice(Invoice $invoice): void
    {
        // Only process invoices that are not ROI taxed (not reverse charge)
        if ($invoice->is_roi_taxed) {
            Log::info('Invoice is ROI taxed, skipping EU sales threshold update', [
                'invoice_number' => $invoice->number,
            ]);

            return;
        }

        // Check if invoice is to EU customer (not domestic)
        if (! $this->isEuSale($invoice)) {
            Log::info('Invoice is not EU sale, skipping threshold update', [
                'invoice_number' => $invoice->number,
            ]);

            return;
        }

        // Get company configuration
        $companyId  = (string) ($invoice->company_id ?? config('larabill.company.id', '1'));
        $fiscalYear = (int) ($invoice->fiscal_year ?? date('Y'));
        $config     = FiscalSettings::getOrCreateForUser($companyId, $fiscalYear);

        // If company is already registered in OSS, no need to track threshold
        if ($config->is_oss) {
            Log::info('Company is already OSS registered, skipping threshold update', [
                'invoice_number' => $invoice->number,
            ]);

            return;
        }

        // Add invoice amount to EU sales counter
        $invoiceAmount = $invoice->subtotal; // Use subtotal (base amount without tax)
        $config->incrementEuSales((float) $invoiceAmount);

        Log::info('EU sales threshold updated', [
            'invoice_number' => $invoice->number,
            'amount'         => $invoiceAmount,
            'new_total'      => $config->fresh()->current_eu_sales_amount,
        ]);
    }

    /**
     * Process an invoice refund/credit and decrease EU sales counter if applicable.
     */
    public function processInvoiceRefund(Invoice $invoice): void
    {
        // Only process invoices that are not ROI taxed
        if ($invoice->is_roi_taxed) {
            return;
        }

        // Check if invoice is to EU customer
        if (! $this->isEuSale($invoice)) {
            return;
        }

        // Get company configuration
        $companyId  = (string) ($invoice->company_id ?? config('larabill.company.id', '1'));
        $fiscalYear = (int) ($invoice->fiscal_year ?? date('Y'));
        $config     = FiscalSettings::getOrCreateForUser($companyId, $fiscalYear);

        // If company is already registered in OSS, no need to track threshold
        if ($config->is_oss) {
            return;
        }

        // Subtract invoice amount from EU sales counter (refund)
        $invoiceAmount = $invoice->subtotal;
        $config->incrementEuSales((float) -$invoiceAmount);

        Log::info('EU sales threshold updated (refund)', [
            'invoice_number' => $invoice->number,
            'amount'         => -$invoiceAmount,
            'new_total'      => $config->fresh()->current_eu_sales_amount,
        ]);
    }

    /**
     * Check if an invoice represents a sale to EU customer.
     */
    private function isEuSale(Invoice $invoice): bool
    {
        // This would need to be implemented based on your business logic
        // For now, we'll assume it's based on the user's tax info or invoice data

        $userTaxInfo = $invoice->user_tax_info_encrypted;
        if (! $userTaxInfo) {
            return false;
        }

        // Decrypt and check country code
        // This is a simplified version - you'd need to implement proper decryption
        $taxInfo = $userTaxInfo; // Assuming it's already decrypted or JSON

        if (is_string($taxInfo)) {
            $taxInfo = json_decode($taxInfo, true);
        }

        $countryCode = $taxInfo['country_code'] ?? null;

        if (! $countryCode) {
            return false;
        }

        // EU countries (excluding domestic country - you'd need to configure this)
        $euCountries = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];

        // Remove domestic country (assuming Spain for now)
        $euCountries = array_diff($euCountries, ['ES']);

        return in_array(strtoupper($countryCode), $euCountries);
    }

    /**
     * Check if company should receive threshold notification.
     */
    public function shouldSendNotification(string $userId, int $fiscalYear): bool
    {
        $config = FiscalSettings::getOrCreateForUser($userId, $fiscalYear);

        // Don't send notification if company is already OSS registered
        if ($config->is_oss) {
            return false;
        }

        // Don't send notification if already sent
        if ($config->notification_sent) {
            return false;
        }

        // Send notification if threshold exceeded
        return $config->threshold_exceeded;
    }

    /**
     * Send threshold notification.
     */
    public function sendThresholdNotification(string $userId, int $fiscalYear): void
    {
        if (! $this->shouldSendNotification($userId, $fiscalYear)) {
            return;
        }

        $config = FiscalSettings::getOrCreateForUser($userId, $fiscalYear);

        // Here you would implement your notification logic
        // For example: send email, create notification record, etc.

        Log::warning('EU Sales Threshold Exceeded - OSS Registration Required', [
            'user_id'        => $userId,
            'fiscal_year'    => $fiscalYear,
            'current_amount' => $config->current_eu_sales_amount,
            'threshold'      => $config->eu_sales_threshold,
            'percentage'     => $config->getThresholdPercentage(),
        ]);

        // Mark notification as sent
        $config->update(['notification_sent' => true]);
    }

    /**
     * Reset EU sales for new fiscal year.
     */
    public function resetForNewFiscalYear(string $userId, int $oldYear, int $newYear): void
    {
        $oldConfig = FiscalSettings::getOrCreateForUser($userId, $oldYear);
        $newConfig = FiscalSettings::getOrCreateForUser($userId, $newYear);

        // Reset the new year config
        $newConfig->update([
            'current_eu_sales_amount' => 0,
            'threshold_exceeded'      => false,
            'notification_sent'       => false,
        ]);

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
        $config = FiscalSettings::getOrCreateForUser($userId, $fiscalYear);

        return [
            'current_amount'     => $config->current_eu_sales_amount,
            'threshold'          => $config->eu_sales_threshold,
            'percentage'         => $config->getThresholdPercentage(),
            'exceeded'           => $config->threshold_exceeded,
            'needs_notification' => $this->shouldSendNotification($userId, $fiscalYear),
            'is_oss_registered'  => $config->is_oss,
            'fiscal_year'        => $config->fiscal_year,
        ];
    }

    /**
     * Calculate EU sales from existing invoices (for daily/weekly tasks).
     */
    public function recalculateEuSales(int $fiscalYear): float
    {
        $totalEuSales = Invoice::where('is_roi_taxed', false)
            ->whereYear('created_at', $fiscalYear)
            ->whereHas('user', function ($query) {
                // This would need to be implemented based on your user model structure
                // to filter by EU countries
            })
            ->sum('subtotal');

        Log::info('EU sales recalculated from invoices', [
            'fiscal_year' => $fiscalYear,
            'total_sales' => $totalEuSales,
        ]);

        return $totalEuSales;
    }

    /**
     * Update EU sales counter from database calculation.
     */
    public function updateEuSalesFromDatabase(string $userId, int $fiscalYear): void
    {
        $calculatedTotal = $this->recalculateEuSales($fiscalYear);

        $config = FiscalSettings::getOrCreateForUser($userId, $fiscalYear);

        // Only update if fiscal year matches
        if ($config->fiscal_year === $fiscalYear) {
            $config->update(['current_eu_sales_amount' => (int) $calculatedTotal]);
            $config->checkThreshold();

            Log::info('EU sales updated from database calculation', [
                'user_id'            => $userId,
                'fiscal_year'        => $fiscalYear,
                'calculated_total'   => $calculatedTotal,
                'threshold_exceeded' => $config->threshold_exceeded,
            ]);
        }
    }
}
