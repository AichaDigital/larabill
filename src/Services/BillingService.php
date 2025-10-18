<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Enums\{InvoiceSerieType, InvoiceStatus};
use AichaDigital\Larabill\Models\{Invoice, InvoiceItem};
use AichaDigital\Larabill\Models\TaxGroup;

/**
 * Billing Service
 *
 * Handles invoice creation and management with ROI verification and optional immutability.
 */
class BillingService
{
    private TaxCalculationService $taxCalculationService;

    private RoiVerificationService $roiVerificationService;

    /**
     * Constructor.
     */
    public function __construct(
        ?TaxCalculationService $taxCalculationService = null,
        ?RoiVerificationService $roiVerificationService = null
    ) {
        $this->taxCalculationService  = $taxCalculationService  ?? app(TaxCalculationService::class);
        $this->roiVerificationService = $roiVerificationService ?? app(RoiVerificationService::class);
    }

    /**
     * Create a new invoice with optional ROI verification and immutability.
     *
     * @param  array<string, mixed>  $invoiceData  Invoice data
     * @param  array<string, mixed>  $options  Additional options (roi_verification, make_immutable)
     * @return Invoice Created invoice model
     */
    public function createInvoice(array $invoiceData, array $options = []): Invoice
    {
        $userId = $invoiceData['user_id'];

        // Extract options
        $makeImmutable = $options['make_immutable'] ?? false;

        // Create invoice record first, without totals
        // TODO v0.3.3: Refactor to use InvoiceNumberingService + new fiscal fields
        $invoiceType = $invoiceData['type'] ?? 'invoice';
        $serie       = $invoiceType === 'proforma' ? InvoiceSerieType::PROFORMA->value : InvoiceSerieType::INVOICE->value;
        $status      = isset($invoiceData['status']) ? $this->mapStatusToEnum($invoiceData['status']) : InvoiceStatus::DRAFT->value;

        $invoice = Invoice::create([
            'fiscal_number' => $this->generateInvoiceNumber($invoiceType, $options),
            'prefix'        => $invoiceType === 'proforma' ? 'PRO' : 'FAC',
            'serie'         => $serie,
            'series_number' => $this->getTempSeriesNumber($serie, now()->year),
            'fiscal_year'   => now()->year,
            'invoice_date'  => now()->toDateString(),
            'issued_at'     => now(),
            'status'        => $status,
            'user_id'       => $userId,
            'is_immutable'  => false,
            'due_date'      => $invoiceData['due_date']      ?? null,
            'payment_terms' => $invoiceData['payment_terms'] ?? null,
            'template_name' => $invoiceData['template_name'] ?? null,
        ]);

        // Create invoice items, which now handle their own tax calculation
        $items = $invoiceData['items'] ?? [];
        foreach ($items as $itemData) {
            $this->createInvoiceItem($invoice, $itemData);
        }

        // Now, calculate invoice totals based on its items
        $invoice->taxable_amount   = $invoice->items->sum('taxable_amount');
        $invoice->total_tax_amount = $invoice->items->sum('total_tax_amount');
        $invoice->total_amount     = $invoice->items->sum('total_amount');
        $invoice->save();

        // Make immutable if requested
        if ($makeImmutable) {
            $invoice->makeImmutable();
        }

        return $invoice;
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
     * @param  Invoice  $proforma  The proforma invoice to convert
     * @param  array<string, mixed>  $options  Additional options
     * @return Invoice Created invoice model
     */
    public function convertToInvoice(Invoice $proforma, array $options = []): Invoice
    {
        // Check if proforma using new serie enum
        if ($proforma->serie !== InvoiceSerieType::PROFORMA) {
            throw new \InvalidArgumentException('Only proforma invoices can be converted');
        }

        $invoiceData = [
            'user_id'          => $proforma->user_id,
            'type'             => 'invoice', // Internally mapped to InvoiceSerieType::INVOICE
            'status'           => 'draft',   // Internally mapped to InvoiceStatus::DRAFT
            'due_date'         => $proforma->due_date,
            'payment_terms'    => $proforma->payment_terms,
            'template_name'    => $proforma->template_name,
            'vat_verification' => $proforma->vat_verification,
            'items'            => $this->getInvoiceItemsData($proforma),
        ];

        return $this->createInvoice($invoiceData, $options);
    }

    /**
     * Generate a sequential invoice number with optional annual reset and configurable format.
     *
     * @param  string  $type  Invoice type (invoice, proforma)
     * @param  array<string, mixed>  $options  Generation options
     * @return string Generated invoice number
     */
    private function generateInvoiceNumber(string $type = 'invoice', array $options = []): string
    {
        $prefix      = $type === 'proforma' ? 'PRO' : 'FAC';
        $format      = $options['number_format'] ?? 'simple'; // 'simple' or 'detailed'
        $annualReset = $options['annual_reset']  ?? false;

        // Get current year for annual reset
        $currentYear = date('Y');

        if ($format === 'detailed') {
            // Format: YYYYMMDDHHMMNN (year, month, day, hour, minute, sequential number)
            $timestamp = date('YmdHi');
            $sequence  = $this->getSequenceNumber($type, $annualReset, $currentYear);
            $number    = sprintf('%s-%s%02d', $prefix, $timestamp, $sequence);
        } else {
            // Simple format: PREFIX-XXXX
            $sequence = $this->getSequenceNumber($type, $annualReset, $currentYear);
            $number   = sprintf('%s-%04d', $prefix, $sequence);
        }

        return $number;
    }

    /**
     * Get sequence number with optional annual reset.
     */
    private function getSequenceNumber(string $type, bool $annualReset, string $currentYear): int
    {
        $cacheKey = "invoice_counter_{$type}_".($annualReset ? $currentYear : 'global');

        // Get current counter from cache or start from 1
        $counter = cache()->get($cacheKey, 1);

        // Increment and store
        $newCounter = $counter + 1;
        cache()->put($cacheKey, $newCounter, now()->addYear());

        return $newCounter;
    }

    /**
     * Calculate subtotal from items.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function calculateSubtotal(array $items): float
    {
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += ($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0);
        }

        return $subtotal;
    }

    /**
     * Create an invoice item.
     * TODO v0.3.3: Update to use item_type, unit_measure_id, tax_category_id, service dates
     *
     * @param  array<string, mixed>  $itemData
     */
    private function createInvoiceItem(Invoice $invoice, array $itemData): InvoiceItem
    {
        $taxGroup = TaxGroup::find($itemData['tax_group_id'] ?? null);

        $quantity      = $itemData['quantity']   ?? 1;
        $unitPrice     = $itemData['unit_price'] ?? 0;
        $taxableAmount = $quantity * $unitPrice;

        $taxCalculation = [
            'total_tax_amount' => 0,
            'taxes_applied'    => [],
        ];

        if ($taxGroup) {
            $taxCalculation = $this->taxCalculationService->calculate($taxableAmount, $taxGroup);
        }

        return InvoiceItem::create([
            'invoice_id'       => $invoice->id,
            'description'      => $itemData['description'] ?? '',
            'quantity'         => $quantity,
            'unit_price'       => $unitPrice,
            'taxable_amount'   => $taxableAmount,
            'total_tax_amount' => $taxCalculation['total_tax_amount'],
            'taxes_applied'    => $taxCalculation['taxes_applied'],
            'total_amount'     => $taxableAmount + $taxCalculation['total_tax_amount'],
        ]);
    }

    /**
     * Get invoice items data for conversion.
     *
     * @return array<int, array{description: string, quantity: float, unit_price: float, tax_group_id: int|null}>
     */
    private function getInvoiceItemsData(Invoice $invoice): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, InvoiceItem> $items */
        $items = $invoice->items;

        return $items->map(function (InvoiceItem $item): array {
            // This is tricky as we don't store the tax_group_id on the item.
            // For conversion, we might need to store it or re-evaluate.
            // For now, let's assume no tax on conversion for simplicity.
            return [
                'description'  => $item->description,
                'quantity'     => $item->quantity,
                'unit_price'   => $item->unit_price,
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

    /**
     * Get temporary unique series number (until InvoiceNumberingService is integrated).
     * TODO v0.3.3: Replace with InvoiceNumberingService::generateNumber()
     *
     * @param  int  $serie  Serie type
     * @param  int  $fiscalYear  Fiscal year
     * @return int Temporary series number
     */
    private function getTempSeriesNumber(int $serie, int $fiscalYear): int
    {
        // Get max series_number for this serie + fiscal_year combination
        $maxNumber = Invoice::where('serie', $serie)
            ->where('fiscal_year', $fiscalYear)
            ->max('series_number');

        return ($maxNumber ?? 0) + 1;
    }
}
