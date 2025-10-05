<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\{Invoice, InvoiceItem};

/**
 * Billing Service
 *
 * Handles invoice creation and management with ROI verification and optional immutability.
 */
class BillingService
{
    private int $invoiceCounter = 1;

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
     * @param  array  $invoiceData  Invoice data
     * @param  array  $options  Additional options (roi_verification, make_immutable)
     * @return Invoice Created invoice model
     */
    public function createInvoice(array $invoiceData, array $options = []): Invoice
    {
        $items           = $invoiceData['items'] ?? [];
        $userId          = $invoiceData['user_id'];
        $customerCountry = $invoiceData['customer_country'] ?? 'ES';
        $customerType    = $invoiceData['customer_type']    ?? 'individual';

        // Extract options
        $roiVerification = $options['roi_verification']     ?? false;
        $makeImmutable   = $options['make_immutable']       ?? false;
        $vatVerification = $invoiceData['vat_verification'] ?? null;
        $companyId       = $invoiceData['company_id']       ?? null;

        // Calculate taxes using TaxCalculationService
        $subtotal      = $this->calculateSubtotal($items);
        $isB2B         = $customerType === 'business';
        $sellerCountry = 'ES'; // Default seller country

        $taxCalculation = $this->taxCalculationService->calculateTax(
            $subtotal,
            $sellerCountry,
            $customerCountry,
            $isB2B,
            [
                'company_id'       => $companyId,
                'vat_verification' => $vatVerification,
            ]
        );

        // ROI verification if requested
        $roiData = null;
        if ($roiVerification && $vatVerification) {
            $roiData = $this->roiVerificationService->verifyRoiStatus(
                userId: (string) $userId,
                vatNumber: $vatVerification['vat_number']     ?? '',
                countryCode: $vatVerification['country_code'] ?? $customerCountry
            );
        }

        // Create invoice
        $invoiceType = $invoiceData['type'] ?? 'invoice';
        $invoice     = Invoice::create([
            'number'      => $this->generateInvoiceNumber($invoiceType, $options),
            'type'        => $invoiceType,
            'status'      => $invoiceData['status'] ?? 'draft',
            'user_id'     => $userId,
            'subtotal'    => Invoice::amountToBase100($taxCalculation['amount']),
            'tax_amount'  => Invoice::amountToBase100($taxCalculation['tax_amount']),
            'total'       => Invoice::amountToBase100($taxCalculation['total']),
            'fiscal_data' => [
                'tax_rate'           => $taxCalculation['tax_rate'],
                'tax_type'           => $taxCalculation['tax_type'],
                'tax_name'           => $taxCalculation['tax_name'],
                'special_conditions' => $taxCalculation['special_conditions'],
            ],
            'vat_verification' => $vatVerification,
            'is_roi_taxed'     => $roiData['is_roi'] ?? false,
            'is_immutable'     => false,
            'due_date'         => $invoiceData['due_date'] ?? null,
            'notes'            => implode(' ', $taxCalculation['invoice_notes'] ?? []),
            'payment_terms'    => $invoiceData['payment_terms'] ?? null,
            'template_name'    => $invoiceData['template_name'] ?? null,
        ]);

        // Create invoice items
        foreach ($items as $itemData) {
            $this->createInvoiceItem($invoice, $itemData);
        }

        // Make immutable if requested
        if ($makeImmutable) {
            $invoice->makeImmutable();
        }

        return $invoice;
    }

    /**
     * Create a proforma invoice.
     *
     * @param  array  $invoiceData  Invoice data
     * @param  array  $options  Additional options
     * @return Invoice Created proforma invoice model
     */
    public function createProforma(array $invoiceData, array $options = []): Invoice
    {
        $invoiceData['type']   = 'proforma';
        $invoiceData['status'] = 'draft';

        // Proforma invoices are never immutable
        $options['make_immutable'] = false;

        return $this->createInvoice($invoiceData, $options);
    }

    /**
     * Convert a proforma invoice to a regular invoice.
     *
     * @param  Invoice  $proforma  The proforma invoice to convert
     * @param  array  $options  Additional options
     * @return Invoice Created invoice model
     */
    public function convertToInvoice(Invoice $proforma, array $options = []): Invoice
    {
        if ($proforma->type !== 'proforma') {
            throw new \InvalidArgumentException('Only proforma invoices can be converted');
        }

        $invoiceData = [
            'user_id'          => $proforma->user_id,
            'type'             => 'invoice',
            'status'           => 'draft',
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
     * @param  array  $options  Generation options
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
     */
    private function createInvoiceItem(Invoice $invoice, array $itemData): InvoiceItem
    {
        $quantity  = $itemData['quantity']   ?? 1;
        $unitPrice = $itemData['unit_price'] ?? 0;
        $taxRate   = $itemData['tax_rate']   ?? 0;

        $subtotal  = $quantity * $unitPrice;
        $taxAmount = $subtotal * ($taxRate / 100);
        $total     = $subtotal + $taxAmount;

        return InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'description' => $itemData['description'] ?? '',
            'quantity'    => Invoice::amountToBase100($quantity),
            'unit_price'  => Invoice::amountToBase100($unitPrice),
            'subtotal'    => Invoice::amountToBase100($subtotal),
            'tax_rate'    => Invoice::amountToBase100($taxRate),
            'tax_amount'  => Invoice::amountToBase100($taxAmount),
            'total'       => Invoice::amountToBase100($total),
        ]);
    }

    /**
     * Get invoice items data for conversion.
     */
    private function getInvoiceItemsData(Invoice $invoice): array
    {
        return $invoice->items->map(function ($item) {
            return [
                'description' => $item->description,
                'quantity'    => Invoice::base100ToAmount((int) $item->quantity),
                'unit_price'  => Invoice::base100ToAmount((int) $item->unit_price),
                'tax_rate'    => Invoice::base100ToAmount((int) $item->tax_rate),
            ];
        })->toArray();
    }
}
