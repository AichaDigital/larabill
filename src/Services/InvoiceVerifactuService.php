<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\Adapters\VerifactuAdapter;
use AichaDigital\LaraVerifactu\Models\Invoice as VerifactuInvoice;
use AichaDigital\LaraVerifactu\Models\InvoiceBreakdown;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Invoice Verifactu Service
 *
 * Service to integrate Larabill invoices with Lara-Verifactu (AEAT/TicketBAI).
 * Handles conversion from Larabill base100 format to Verifactu decimal format.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class InvoiceVerifactuService
{
    /**
     * Register an invoice with Verifactu system.
     *
     * @param  Invoice  $invoice  The Larabill invoice to register
     * @param  bool  $withBreakdowns  Whether to create invoice breakdowns (line items)
     * @return VerifactuInvoice The created Verifactu invoice
     *
     * @throws \Exception If invoice cannot be registered
     */
    public function registerInvoice(Invoice $invoice, bool $withBreakdowns = true): VerifactuInvoice
    {
        // Build the full payload (invoice + breakdowns) BEFORE persisting anything.
        // The adapter guards (intra-EU N2 predicate, OSS, anti-VAT-deletion, goods,
        // incomplete reverse charge) throw here, so no orphan VerifactuInvoice row is
        // created — a half-written row would make isRegistered() return true and block
        // the retry (AID-136). The DB::transaction is a second safety net.
        $verifactuData = VerifactuAdapter::toVerifactuInvoice($invoice);

        $breakdownData = ($withBreakdowns && $invoice->items()->exists())
            ? VerifactuAdapter::toVerifactuBreakdowns($invoice)
            : [];

        Log::info('Registering Larabill invoice with Verifactu', [
            'larabill_invoice_id' => $invoice->id,
            'fiscal_number'       => $invoice->fiscal_number,
            'total_amount'        => $verifactuData['total_amount'],
        ]);

        $verifactuInvoice = DB::transaction(function () use ($verifactuData, $breakdownData) {
            $verifactuInvoice = VerifactuInvoice::create($verifactuData);

            foreach ($breakdownData as $breakdown) {
                InvoiceBreakdown::create(array_merge($breakdown, [
                    'invoice_id' => $verifactuInvoice->id,
                ]));
            }

            return $verifactuInvoice;
        });

        Log::info('Verifactu invoice created successfully', [
            'verifactu_invoice_id' => $verifactuInvoice->id,
            'larabill_invoice_id'  => $invoice->id,
            'breakdowns_count'     => count($breakdownData),
        ]);

        return $verifactuInvoice;
    }

    /**
     * Check if an invoice is already registered with Verifactu.
     *
     * @param  Invoice  $invoice  The Larabill invoice
     * @return bool True if already registered
     */
    public function isRegistered(Invoice $invoice): bool
    {
        // @phpstan-ignore argument.type (Eloquent JSON path operator, not a model column)
        return VerifactuInvoice::where('metadata->larabill_invoice_id', $invoice->id)->exists();
    }

    /**
     * Get the Verifactu invoice for a Larabill invoice.
     *
     * @param  Invoice  $invoice  The Larabill invoice
     * @return VerifactuInvoice|null The Verifactu invoice or null if not registered
     */
    public function getVerifactuInvoice(Invoice $invoice): ?VerifactuInvoice
    {
        // @phpstan-ignore argument.type (Eloquent JSON path operator, not a model column)
        return VerifactuInvoice::where('metadata->larabill_invoice_id', $invoice->id)->first();
    }

    /**
     * Validate if an invoice can be registered with Verifactu.
     *
     * @param  Invoice  $invoice  The Larabill invoice
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateForVerifactu(Invoice $invoice): array
    {
        $errors = [];

        // Check if invoice is immutable (required for fiscal registration)
        if (! $invoice->is_immutable) {
            $errors[] = 'Invoice must be immutable before Verifactu registration';
        }

        // Check required fields
        if (empty($invoice->fiscal_number)) {
            $errors[] = 'Invoice must have a fiscal number';
        }

        if (empty($invoice->invoice_date)) {
            $errors[] = 'Invoice must have an invoice date';
        }

        // Check tax profile exists
        if (! $invoice->userTaxProfile) {
            $errors[] = 'Invoice must have a tax profile';
        }

        // Check billable user exists (ADR-003: User replaces Customer)
        if (! $invoice->billableUser) {
            $errors[] = 'Invoice must have a billable user';
        }

        // Check totals are valid
        if ($invoice->total_amount->unscaledValue() <= 0) {
            $errors[] = 'Invoice total amount must be greater than zero';
        }

        // Check if already registered
        if ($this->isRegistered($invoice)) {
            $errors[] = 'Invoice is already registered with Verifactu';
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }
}
