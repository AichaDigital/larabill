<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\Validators;

use AichaDigital\Larabill\Models\Invoice;

/**
 * AEAT Validator
 *
 * Validates invoices against AEAT/Verifactu requirements.
 * Ensures invoices meet Spanish tax authority (AEAT) standards before submission.
 */
class AeatInvoiceValidator
{
    /**
     * Validate invoice for AEAT/Verifactu submission.
     *
     * @param  Invoice  $invoice  The invoice to validate
     * @return array{valid: bool, errors: array<string>, warnings: array<string>}
     */
    public function validate(Invoice $invoice): array
    {
        $errors   = [];
        $warnings = [];

        // 1. Invoice status validation
        if (! $invoice->is_immutable) {
            $errors[] = 'Invoice must be immutable before AEAT submission';
        }

        // 2. Required identifiers
        if (empty($invoice->fiscal_number)) {
            $errors[] = 'fiscal_number is required for AEAT';
        }

        if (empty($invoice->series_number)) {
            $errors[] = 'series_number is required for AEAT';
        }

        // 3. Date validation
        if (empty($invoice->invoice_date)) {
            $errors[] = 'invoice_date is required for AEAT';
        } elseif ($invoice->invoice_date->isFuture()) {
            $errors[] = 'invoice_date cannot be in the future';
        }

        // 4. Tax profile validation
        if (! $invoice->taxProfile) {
            $errors[] = 'Tax profile is required for AEAT';
        } else {
            $this->validateTaxProfile($invoice, $errors, $warnings);
        }

        // 5. Customer validation
        if (! $invoice->customer) {
            $errors[] = 'Customer is required for AEAT';
        } else {
            $this->validateCustomer($invoice, $errors, $warnings);
        }

        // 6. Amounts validation
        $this->validateAmounts($invoice, $errors, $warnings);

        // 7. Serie validation
        if (empty($invoice->serie)) {
            $errors[] = 'Serie is required for AEAT';
        }

        // 8. ROI/Reverse charge validation
        if ($invoice->is_roi_taxed && $invoice->total_tax_amount > 0) {
            $warnings[] = 'ROI invoices typically have zero tax amount (reverse charge)';
        }

        // 9. Items validation
        if (! $invoice->items()->exists()) {
            $warnings[] = 'Invoice has no items (line items recommended for AEAT)';
        }

        return [
            'valid'    => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate tax profile data.
     */
    private function validateTaxProfile(Invoice $invoice, array &$errors, array &$warnings): void
    {
        $profile = $invoice->taxProfile;

        if (empty($profile->tax_code)) {
            $errors[] = 'Tax profile must have a tax_code (NIF/VAT)';
        } else {
            // Validate Spanish NIF format (basic)
            if ($profile->country_code === 'ES' && ! preg_match('/^ES[A-Z0-9]{8,9}$/', $profile->tax_code)) {
                $warnings[] = 'tax_code format may not be valid Spanish NIF (expected: ES + 8-9 chars)';
            }
        }

        if (empty($profile->business_name)) {
            $errors[] = 'Tax profile must have a business_name';
        }

        if (empty($profile->address)) {
            $warnings[] = 'Tax profile address is recommended for AEAT';
        }
    }

    /**
     * Validate customer data.
     */
    private function validateCustomer(Invoice $invoice, array &$errors, array &$warnings): void
    {
        $customer = $invoice->customer;

        if (empty($customer->display_name)) {
            $errors[] = 'Customer must have a display_name';
        }

        if (empty($customer->legal_entity_type_code)) {
            $errors[] = 'Customer must have a legal_entity_type_code';
        }
    }

    /**
     * Validate invoice amounts.
     */
    private function validateAmounts(Invoice $invoice, array &$errors, array &$warnings): void
    {
        if ($invoice->total_amount <= 0) {
            $errors[] = 'total_amount must be greater than zero';
        }

        if ($invoice->taxable_amount < 0) {
            $errors[] = 'taxable_amount cannot be negative';
        }

        if ($invoice->total_tax_amount < 0) {
            $errors[] = 'total_tax_amount cannot be negative';
        }

        // Verify calculation: total = taxable + tax
        $expectedTotal = $invoice->taxable_amount + $invoice->total_tax_amount;
        if ($invoice->total_amount !== $expectedTotal) {
            $errors[] = sprintf(
                'total_amount (%d) must equal taxable_amount (%d) + total_tax_amount (%d) = %d',
                $invoice->total_amount,
                $invoice->taxable_amount,
                $invoice->total_tax_amount,
                $expectedTotal
            );
        }
    }

    /**
     * Quick validation check (returns only boolean).
     *
     * @param  Invoice  $invoice  The invoice to validate
     * @return bool True if valid for AEAT
     */
    public function isValid(Invoice $invoice): bool
    {
        $result = $this->validate($invoice);

        return $result['valid'];
    }

    /**
     * Get validation errors only.
     *
     * @param  Invoice  $invoice  The invoice to validate
     * @return array<string> List of validation errors
     */
    public function getErrors(Invoice $invoice): array
    {
        $result = $this->validate($invoice);

        return $result['errors'];
    }

    /**
     * Get validation warnings only.
     *
     * @param  Invoice  $invoice  The invoice to validate
     * @return array<string> List of validation warnings
     */
    public function getWarnings(Invoice $invoice): array
    {
        $result = $this->validate($invoice);

        return $result['warnings'];
    }
}
