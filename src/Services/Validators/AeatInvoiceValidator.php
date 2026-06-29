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

        // 5. Billable user validation (ADR-003: User replaces Customer)
        if (! $invoice->billableUser) {
            $errors[] = 'Billable user is required for AEAT';
        } else {
            $this->validateBillableUser($invoice, $errors, $warnings);
        }

        // 6. Amounts validation
        $this->validateAmounts($invoice, $errors, $warnings);

        // 7. Serie validation
        if (empty($invoice->serie)) {
            $errors[] = 'Serie is required for AEAT';
        }

        // 8. ROI/Reverse charge validation
        if ($invoice->is_roi_taxed && $invoice->total_tax_amount->unscaledValue() > 0) {
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
     *
     * @param  array<string>  $errors
     * @param  array<string>  $warnings
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
     * Validate billable user data (ADR-003: User replaces Customer).
     *
     * @param  array<string>  $errors
     * @param  array<string>  $warnings
     */
    private function validateBillableUser(Invoice $invoice, array &$errors, array &$warnings): void
    {
        $billableUser = $invoice->billableUser;

        if (empty($billableUser->display_name) && empty($billableUser->name)) {
            $errors[] = 'Billable user must have a display_name or name';
        }

        if (empty($billableUser->legal_entity_type_code)) {
            $warnings[] = 'Billable user legal_entity_type_code is recommended for AEAT';
        }
    }

    /**
     * Validate invoice amounts.
     *
     * @param  array<string>  $errors
     * @param  array<string>  $warnings
     */
    private function validateAmounts(Invoice $invoice, array &$errors, array &$warnings): void
    {
        // Work in raw base-100 cents (integers); the amounts are FixedDecimal.
        $taxable = $invoice->taxable_amount->unscaledValue();
        $tax     = $invoice->total_tax_amount->unscaledValue();
        $total   = $invoice->total_amount->unscaledValue();

        if ($total <= 0) {
            $errors[] = 'total_amount must be greater than zero';
        }

        if ($taxable < 0) {
            $errors[] = 'taxable_amount cannot be negative';
        }

        if ($tax < 0) {
            $errors[] = 'total_tax_amount cannot be negative';
        }

        // Verify calculation: total = taxable + tax
        $expectedTotal = $taxable + $tax;
        if ($total !== $expectedTotal) {
            $errors[] = sprintf(
                'total_amount (%d) must equal taxable_amount (%d) + total_tax_amount (%d) = %d',
                $total,
                $taxable,
                $tax,
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
