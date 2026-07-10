<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\RoundingMode;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\TaxGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Billing Service
 *
 * Handles invoice creation and management with optional immutability.
 *
 * @deprecated AID-390 — superseded by InvoiceService (fiscal snapshots,
 *             ADR-003 billable_user_id) and InvoiceNumberingService. It now
 *             delegates its numbering to the shared series control, but the
 *             whole class is removal-targeted for the AID-307 breaking major.
 */
class BillingService
{
    private TaxCalculationService $taxCalculationService;

    private InvoiceNumberingService $invoiceNumbering;

    /**
     * Constructor.
     */
    public function __construct(
        ?TaxCalculationService $taxCalculationService = null,
        ?InvoiceNumberingService $invoiceNumbering = null
    ) {
        $this->taxCalculationService = $taxCalculationService ?? app(TaxCalculationService::class);
        $this->invoiceNumbering      = $invoiceNumbering      ?? app(InvoiceNumberingService::class);
    }

    /**
     * Create a new invoice with optional ROI verification and immutability.
     *
     * Uses database transaction for atomicity.
     *
     * @param  array<string, mixed>  $invoiceData  Invoice data
     * @param  array<string, mixed>  $options  Additional options (roi_verification, make_immutable)
     * @return Invoice Created invoice model
     */
    public function createInvoice(array $invoiceData, array $options = []): Invoice
    {
        return DB::transaction(function () use ($invoiceData, $options): Invoice {
            $userId = $invoiceData['user_id'];

            // Extract options
            $makeImmutable = $options['make_immutable'] ?? false;

            // Create invoice record first, without totals
            $invoiceType = $invoiceData['type'] ?? 'invoice';
            $serie       = $invoiceType === 'proforma' ? InvoiceSerieType::PROFORMA->value : InvoiceSerieType::INVOICE->value;
            $status      = isset($invoiceData['status']) ? $this->mapStatusToEnum($invoiceData['status']) : InvoiceStatus::DRAFT->value;

            // Atomic correlative numbering from the single owner (AID-390):
            // fiscal_number, prefix, series_number and fiscal_year all derive
            // from the SAME InvoiceNumber, on the global issuer scope.
            $number = $this->invoiceNumbering->generateNumber(
                $invoiceType === 'proforma' ? 'PRO' : 'FAC',
                $serie
            );

            $invoice = Invoice::create([
                'fiscal_number'    => $number->formatted,
                'prefix'           => $number->prefix,
                'serie'            => $serie,
                'series_number'    => $number->seriesNumber,
                'fiscal_year'      => $number->fiscalYear,
                'invoice_date'     => now()->toDateString(),
                'issued_at'        => now(),
                'status'           => $status,
                'user_id'          => $userId,
                'is_immutable'     => false,
                'due_date'         => $invoiceData['due_date']       ?? null,
                'payment_terms'    => $invoiceData['payment_terms']  ?? null,
                'template_name'    => $invoiceData['template_name']  ?? null,
            ]);

            // Create invoice items, which now handle their own tax calculation
            $items = $invoiceData['items'] ?? [];
            foreach ($items as $itemData) {
                $this->createInvoiceItem($invoice, $itemData);
            }

            // Now, calculate invoice totals based on its items
            $invoice->taxable_amount   = $invoice->items->reduce(fn (FixedDecimal $c, InvoiceItem $i) => $c->plus($i->taxable_amount), FixedDecimal::zero(2));
            $invoice->total_tax_amount = $invoice->items->reduce(fn (FixedDecimal $c, InvoiceItem $i) => $c->plus($i->total_tax_amount), FixedDecimal::zero(2));
            $invoice->total_amount     = $invoice->items->reduce(fn (FixedDecimal $c, InvoiceItem $i) => $c->plus($i->total_amount), FixedDecimal::zero(2));
            $invoice->save();

            // Make immutable if requested
            if ($makeImmutable) {
                $invoice->makeImmutable();
            }

            return $invoice;
        });
    }

    /**
     * Create a proforma invoice.
     *
     * @param  array<string, mixed>  $invoiceData  Invoice data
     * @param  array<string, mixed>  $options  Additional options
     * @return Invoice Created proforma invoice model
     */
    public function createProforma(array $invoiceData, array $options = []): Invoice
    {
        $invoiceData['type']   = 'proforma'; // Internally mapped to InvoiceSerieType::PROFORMA
        $invoiceData['status'] = 'draft';    // Internally mapped to InvoiceStatus::DRAFT

        // Proforma invoices are never immutable
        $options['make_immutable'] = false;

        return $this->createInvoice($invoiceData, $options);
    }

    /**
     * Convert a proforma invoice to a regular invoice.
     *
     * Uses database transaction for atomicity and includes idempotency check.
     *
     * @param  Invoice  $proforma  The proforma invoice to convert
     * @param  array<string, mixed>  $options  Additional options
     * @return Invoice Created invoice model
     */
    public function convertToInvoice(Invoice $proforma, array $options = []): Invoice
    {
        return DB::transaction(function () use ($proforma, $options): Invoice {
            // Refresh proforma within transaction
            $proforma->refresh();

            // Check if proforma using new serie enum
            if ($proforma->serie !== InvoiceSerieType::PROFORMA) {
                throw new \InvalidArgumentException('Only proforma invoices can be converted');
            }

            // Idempotency check: return existing invoice if already converted
            if ($proforma->converted_invoice_id) {
                $existingInvoice = Invoice::find($proforma->converted_invoice_id);
                if ($existingInvoice) {
                    return $existingInvoice;
                }
            }

            $invoiceData = [
                'user_id'          => $proforma->user_id,
                'type'             => 'invoice', // Internally mapped to InvoiceSerieType::INVOICE
                'status'           => 'draft',   // Internally mapped to InvoiceStatus::DRAFT
                'due_date'         => $proforma->due_date,
                'payment_terms'    => $proforma->payment_terms,
                'template_name'    => $proforma->template_name,
                'items'            => $this->getInvoiceItemsData($proforma),
            ];

            $invoice = $this->createInvoice($invoiceData, $options);

            // Link proforma to converted invoice
            $proforma->update([
                'converted_invoice_id' => $invoice->id,
                'converted_at'         => now(),
            ]);

            return $invoice;
        });
    }

    /**
     * Create an invoice item.
     * TODO v0.3.3: Update to use item_type, unit_measure_id, tax_category_id, service dates
     *
     * @param  array<string, mixed>  $itemData
     */
    private function createInvoiceItem(Invoice $invoice, array $itemData): InvoiceItem
    {
        $taxGroupId = isset($itemData['tax_group_id']) ? (int) $itemData['tax_group_id'] : null;
        $taxGroup   = $taxGroupId !== null ? TaxGroup::find($taxGroupId) : null;

        $quantity  = (int) ($itemData['quantity']   ?? 1);
        $unitPrice = (int) ($itemData['unit_price'] ?? 0);

        // Both values are base100 cents. EU/Spain norm: settle quantity × unit_price
        // to the cent with HalfUp (never truncation).
        $taxableAmount = FixedDecimal::ofUnscaled($quantity, 2)
            ->multipliedBy(FixedDecimal::ofUnscaled($unitPrice, 2))
            ->toScale(2, RoundingMode::HalfUp)
            ->unscaledValue();

        $taxCalculation = [
            'total_tax_amount' => 0,
            'taxes_applied'    => [],
        ];

        if ($taxGroup) {
            $taxCalculation = $this->taxCalculationService->calculate($taxableAmount, $taxGroup);
        }

        $totalTaxAmount = (int) $taxCalculation['total_tax_amount'];

        return InvoiceItem::create([
            'invoice_id'       => $invoice->id,
            'description'      => $itemData['description'] ?? '',
            'quantity'         => FixedDecimal::ofUnscaled($quantity, 2),
            'unit_price'       => FixedDecimal::ofUnscaled($unitPrice, 2),
            'taxable_amount'   => FixedDecimal::ofUnscaled($taxableAmount, 2),
            'total_tax_amount' => FixedDecimal::ofUnscaled($totalTaxAmount, 2),
            'taxes_applied'    => $taxCalculation['taxes_applied'],
            'total_amount'     => FixedDecimal::ofUnscaled($taxableAmount + $totalTaxAmount, 2),
        ]);
    }

    /**
     * Get invoice items data for conversion.
     *
     * @return array<int, array{description: string, quantity: float, unit_price: float, tax_group_id: int|null}>
     */
    private function getInvoiceItemsData(Invoice $invoice): array
    {
        /** @var Collection<int, InvoiceItem> $items */
        $items = $invoice->items;

        return $items->map(function (InvoiceItem $item): array {
            // This is tricky as we don't store the tax_group_id on the item.
            // For conversion, we might need to store it or re-evaluate.
            // For now, let's assume no tax on conversion for simplicity.
            return [
                'description'  => $item->description,
                'quantity'     => $item->quantity->unscaledValue(),
                'unit_price'   => $item->unit_price->unscaledValue(),
                'tax_group_id' => null,
            ];
        })->toArray();
    }

    /**
     * Map string status to enum value for backward compatibility.
     * TODO v0.3.3: Remove this helper after refactoring all tests to use enums directly
     *
     * @param  string|int  $status  Status string or int
     * @return int Enum value
     */
    private function mapStatusToEnum($status): int
    {
        if (is_int($status)) {
            return $status; // Already an enum value
        }

        return match ($status) {
            'draft'     => InvoiceStatus::DRAFT->value,
            'sent'      => InvoiceStatus::SENT->value,
            'paid'      => InvoiceStatus::PAID->value,
            'overdue'   => InvoiceStatus::OVERDUE->value,
            'cancelled' => InvoiceStatus::CANCELLED->value,
            default     => InvoiceStatus::DRAFT->value,
        };
    }
}
