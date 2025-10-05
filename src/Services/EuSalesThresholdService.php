<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\Invoice;
use Illuminate\Support\Facades\Log;

/**
 * EU Sales Threshold Service
 *
 * Handles the logic for tracking EU sales and managing the 10,000€ threshold
 * for companies that need to register in OSS (One Stop Shop).
 */
class EuSalesThresholdService
{
    private CompanyConfigService $companyConfigService;

    /**
     * Constructor.
     */
    public function __construct(?CompanyConfigService $companyConfigService = null)
    {
        $this->companyConfigService = $companyConfigService ?? app(CompanyConfigService::class);
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
        $config = $this->companyConfigService->getCurrentConfig();

        // If company is already registered in OSS, no need to track threshold
        if ($config->is_oss) {
            Log::info('Company is already OSS registered, skipping threshold update', [
                'invoice_number' => $invoice->number,
            ]);

            return;
        }

        // Add invoice amount to EU sales counter
        $invoiceAmount = $invoice->subtotal; // Use subtotal (base amount without tax)
        $this->companyConfigService->updateEuSalesAmount($invoiceAmount);

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
        $config = $this->companyConfigService->getCurrentConfig();

        // If company is already registered in OSS, no need to track threshold
        if ($config->is_oss) {
            return;
        }

        // Subtract invoice amount from EU sales counter (refund)
        $invoiceAmount = $invoice->subtotal;
        $this->companyConfigService->updateEuSalesAmount(-$invoiceAmount);

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
    public function shouldSendNotification(): bool
    {
        $config = $this->companyConfigService->getCurrentConfig();

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
    public function sendThresholdNotification(): void
    {
        if (! $this->shouldSendNotification()) {
            return;
        }

        $config = $this->companyConfigService->getCurrentConfig();

        // Here you would implement your notification logic
        // For example: send email, create notification record, etc.

        Log::warning('EU Sales Threshold Exceeded - OSS Registration Required', [
            'current_amount' => $config->current_eu_sales_amount,
            'threshold'      => $config->eu_sales_threshold,
            'percentage'     => $config->getThresholdPercentage(),
        ]);

        // Mark notification as sent
        $this->companyConfigService->markNotificationSent();
    }

    /**
     * Reset EU sales for new fiscal year.
     */
    public function resetForNewFiscalYear(int $newYear): void
    {
        $this->companyConfigService->resetEuSalesForNewYear($newYear);

        Log::info('EU sales threshold reset for new fiscal year', [
            'new_year' => $newYear,
        ]);
    }

    /**
     * Get current threshold status.
     */
    public function getThresholdStatus(): array
    {
        $config = $this->companyConfigService->getCurrentConfig();

        return [
            'current_amount'     => $config->current_eu_sales_amount,
            'threshold'          => $config->eu_sales_threshold,
            'percentage'         => $config->getThresholdPercentage(),
            'exceeded'           => $config->threshold_exceeded,
            'needs_notification' => $this->shouldSendNotification(),
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
    public function updateEuSalesFromDatabase(int $fiscalYear): void
    {
        $calculatedTotal = $this->recalculateEuSales($fiscalYear);

        $config = $this->companyConfigService->getCurrentConfig();

        // Only update if fiscal year matches
        if ($config->fiscal_year === $fiscalYear) {
            $config->update(['current_eu_sales_amount' => $calculatedTotal]);
            $config->checkThreshold();

            Log::info('EU sales updated from database calculation', [
                'fiscal_year'        => $fiscalYear,
                'calculated_total'   => $calculatedTotal,
                'threshold_exceeded' => $config->threshold_exceeded,
            ]);
        }
    }
}
