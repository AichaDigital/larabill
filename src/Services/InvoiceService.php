<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Contracts\Services\FiscalVerificationContract;
use AichaDigital\Larabill\Enums\{InvoiceSerieType, InvoiceStatus};
use AichaDigital\Larabill\Models\{Customer, Invoice, InvoiceItem, IssuerConfig};
use Illuminate\Support\Facades\Crypt;

/**
 * Invoice Service (v0.4.0)
 *
 * Modern service with encrypted snapshots and fiscal verification integration.
 */
class InvoiceService
{
    public function __construct(
        protected TaxCalculationService $taxCalculationService,
        protected ?FiscalVerificationContract $fiscalVerification = null
    ) {}

    /**
     * Create a new invoice with encrypted snapshots.
     *
     * @param  array{
     *     customer_id: int,
     *     items: array<array{article_id: int, quantity: int, base_price: float}>,
     *     type?: string,
     *     status?: string,
     *     due_date?: string|null,
     *     payment_terms?: int|null,
     *     template_name?: string|null
     * }  $invoiceData
     * @param  array{
     *     make_immutable?: bool,
     *     verify_fiscally?: bool
     * }  $options
     */
    public function createInvoice(array $invoiceData, array $options = []): Invoice
    {
        $customer = Customer::with('currentTaxProfile')->findOrFail($invoiceData['customer_id']);
        $issuer   = IssuerConfig::with('currentTaxProfile')->firstOrFail();

        // Determine invoice type and serie
        $invoiceType = $invoiceData['type'] ?? 'invoice';
        $serie       = $invoiceType === 'proforma' ? InvoiceSerieType::PROFORMA->value : InvoiceSerieType::INVOICE->value;
        $status      = isset($invoiceData['status']) ? $this->mapStatusToEnum($invoiceData['status']) : InvoiceStatus::DRAFT->value;

        // Create invoice record
        $invoice = Invoice::create([
            'fiscal_number'    => $this->generateInvoiceNumber($invoiceType),
            'prefix'           => $invoiceType === 'proforma' ? 'PRO' : 'FAC',
            'serie'            => $serie,
            'series_number'    => $this->getTempSeriesNumber((string) $serie, now()->year),
            'fiscal_year'      => now()->year,
            'invoice_date'     => now()->toDateString(),
            'issued_at'        => now(),
            'status'           => $status,
            'customer_id'      => $customer->id,
            'is_immutable'     => false,
            'due_date'         => $invoiceData['due_date']      ?? null,
            'payment_terms'    => $invoiceData['payment_terms'] ?? null,
            'template_name'    => $invoiceData['template_name'] ?? null,
        ]);

        // Create invoice items
        $items = $invoiceData['items'] ?? [];
        foreach ($items as $itemData) {
            $this->createInvoiceItem($invoice, $itemData);
        }

        // Calculate totals
        $invoice->taxable_amount   = $invoice->items->sum('taxable_amount');
        $invoice->total_tax_amount = $invoice->items->sum('total_tax_amount');
        $invoice->total_amount     = $invoice->items->sum('total_amount');

        // Generate encrypted snapshots
        $invoice->issuer_snapshot   = $this->generateIssuerSnapshot($issuer);
        $invoice->customer_snapshot = $this->generateCustomerSnapshot($customer);
        $invoice->fiscal_snapshot   = $this->generateFiscalSnapshot($invoice, $customer, $issuer);

        $invoice->save();

        // Make immutable if requested
        if ($options['make_immutable'] ?? false) {
            $invoice->makeImmutable();
        }

        // Fiscal verification if requested
        if ($options['verify_fiscally'] ?? false) {
            $this->verifyInvoiceFiscally($invoice);
        }

        return $invoice->fresh();
    }

    /**
     * Generate encrypted issuer snapshot.
     */
    protected function generateIssuerSnapshot(IssuerConfig $issuer): string
    {
        $profile = $issuer->currentTaxProfile;

        $data = [
            'legal_name'             => $profile->legal_name,
            'commercial_name'        => $profile->commercial_name,
            'tax_id'                 => $profile->tax_id,
            'legal_entity_type_code' => $profile->legal_entity_type_code,
            'address'                => $profile->address,
            'address_line_2'         => $profile->address_line_2,
            'city'                   => $profile->city,
            'state'                  => $profile->state,
            'postal_code'            => $profile->postal_code,
            'country_code'           => $profile->country_code,
            'phone'                  => $profile->phone,
            'email'                  => $profile->email,
            'vat_number'             => $profile->vat_number,
            'roi_enabled'            => $profile->roi_enabled,
            'snapshot_at'            => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data));
    }

    /**
     * Generate encrypted customer snapshot.
     */
    protected function generateCustomerSnapshot(Customer $customer): string
    {
        $profile = $customer->currentTaxProfile;

        $data = [
            'customer_id'            => $customer->id,
            'customer_name'          => $customer->name,
            'relationship_to_user'   => $customer->relationship_to_user,
            'legal_name'             => $profile->legal_name,
            'commercial_name'        => $profile->commercial_name,
            'tax_id'                 => $profile->tax_id,
            'legal_entity_type_code' => $profile->legal_entity_type_code,
            'address'                => $profile->address,
            'address_line_2'         => $profile->address_line_2,
            'city'                   => $profile->city,
            'state'                  => $profile->state,
            'postal_code'            => $profile->postal_code,
            'country_code'           => $profile->country_code,
            'phone'                  => $profile->phone,
            'email'                  => $profile->email,
            'vat_number'             => $profile->vat_number,
            'snapshot_at'            => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data));
    }

    /**
     * Generate encrypted fiscal snapshot.
     */
    protected function generateFiscalSnapshot(Invoice $invoice, Customer $customer, IssuerConfig $issuer): string
    {
        $data = [
            'fiscal_year'       => $invoice->fiscal_year,
            'serie'             => $invoice->serie,
            'series_number'     => $invoice->series_number,
            'fiscal_number'     => $invoice->fiscal_number,
            'invoice_date'      => $invoice->invoice_date,
            'issuer_country'    => $issuer->currentTaxProfile->country_code,
            'customer_country'  => $customer->currentTaxProfile->country_code,
            'currency'          => 'EUR', // TODO: Make configurable
            'exchange_rate'     => 1.0,   // TODO: Implement multi-currency
            'tax_rules_applied' => [], // TODO: Add tax calculation details
            'snapshot_at'       => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data));
    }

    /**
     * Verify invoice fiscally (dispatch to external package).
     */
    protected function verifyInvoiceFiscally(Invoice $invoice): void
    {
        if (! $this->fiscalVerification) {
            return; // No fiscal verification configured
        }

        // This will dispatch a job in the external package (e.g., lara-verifactu)
        $result = $this->fiscalVerification->verify($invoice);

        if ($result['success']) {
            $invoice->update([
                'fiscal_verification_id'       => $result['verification_id'],
                'fiscal_verification_qr'       => $result['qr_code'] ?? null,
                'fiscal_verification_hash'     => $result['hash']    ?? null,
                'fiscal_verified_at'           => now(),
                'fiscal_verification_metadata' => $result['metadata'] ?? [],
            ]);
        }
    }

    /**
     * Create an invoice item with tax calculation.
     */
    protected function createInvoiceItem(Invoice $invoice, array $itemData): InvoiceItem
    {
        $articleId = $itemData['article_id'];
        $quantity  = $itemData['quantity'];
        $basePrice = $itemData['base_price'] ?? 0;

        // Calculate taxes using TaxCalculationService
        $taxCalculation = $this->taxCalculationService->calculateForInvoiceItem([
            'article_id'  => $articleId,
            'quantity'    => $quantity,
            'base_price'  => $basePrice,
            'customer_id' => $invoice->customer_id,
        ]);

        return InvoiceItem::create([
            'invoice_id'       => $invoice->id,
            'article_id'       => $articleId,
            'description'      => $itemData['description'] ?? '',
            'quantity'         => $quantity,
            'unit_price'       => $basePrice,
            'taxable_amount'   => $taxCalculation['taxable_amount'],
            'tax_group_id'     => $taxCalculation['tax_group_id'] ?? null,
            'total_tax_amount' => $taxCalculation['total_tax_amount'],
            'total_amount'     => $taxCalculation['total_amount'],
        ]);
    }

    /**
     * Create a proforma invoice.
     *
     * @param  array{
     *     customer_id: int,
     *     items: array<array{article_id: int, quantity: int, base_price: float}>,
     *     due_date?: string|null,
     *     payment_terms?: int|null,
     *     template_name?: string|null
     * }  $invoiceData
     * @param  array{make_immutable?: bool}  $options
     */
    public function createProforma(array $invoiceData, array $options = []): Invoice
    {
        $invoiceData['type']   = 'proforma';
        $invoiceData['status'] = 'draft';

        // Proformas are never fiscally verified at creation
        $options['verify_fiscally'] = false;

        return $this->createInvoice($invoiceData, $options);
    }

    /**
     * Convert proforma to final invoice with locking.
     *
     * This method ensures the proforma is locked and a new invoice is created
     * with fiscal verification if required.
     *
     * @param  array{verify_fiscally?: bool}  $options
     */
    public function convertProformaToInvoice(Invoice $proforma, array $options = []): Invoice
    {
        if ($proforma->serie !== InvoiceSerieType::PROFORMA) {
            throw new \InvalidArgumentException('Invoice is not a proforma');
        }

        if ($proforma->converted_invoice_id) {
            throw new \InvalidArgumentException('Proforma already converted to invoice');
        }

        // Create new invoice from proforma data
        $invoiceData = [
            'customer_id' => $proforma->customer_id,
            'items'       => $proforma->items->map(fn ($item) => [
                'article_id'  => $item->article_id,
                'description' => $item->description,
                'quantity'    => $item->quantity,
                'base_price'  => $item->unit_price,
            ])->toArray(),
            'type'          => 'invoice',
            'status'        => 'pending',
            'due_date'      => $proforma->due_date,
            'payment_terms' => $proforma->payment_terms,
            'template_name' => $proforma->template_name,
        ];

        $invoice = $this->createInvoice($invoiceData, [
            'make_immutable'  => true,
            'verify_fiscally' => $options['verify_fiscally'] ?? false,
        ]);

        // Lock the proforma and link to invoice (single update to avoid immutable conflict)
        $proforma->update([
            'is_immutable'         => true,
            'converted_invoice_id' => $invoice->id,
            'converted_at'         => now(),
            'status'               => InvoiceStatus::CONVERTED->value,
        ]);

        return $invoice;
    }

    /**
     * Generate invoice number.
     */
    protected function generateInvoiceNumber(string $type): string
    {
        // TODO: Implement proper numbering service
        return strtoupper($type).'-'.now()->year.'-'.str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get temporary series number.
     */
    protected function getTempSeriesNumber(string $serie, int $year): int
    {
        // TODO: Implement proper series tracking
        return Invoice::where('serie', $serie)
            ->where('fiscal_year', $year)
            ->max('series_number') + 1 ?? 1;
    }

    /**
     * Map status (string or int) to enum value.
     */
    protected function mapStatusToEnum(string|int $status): int
    {
        // If already int, return it
        if (is_int($status)) {
            return $status;
        }

        // Map string to int
        return match (strtolower($status)) {
            'draft'     => InvoiceStatus::DRAFT->value,
            'pending'   => InvoiceStatus::PENDING->value,
            'paid'      => InvoiceStatus::PAID->value,
            'cancelled' => InvoiceStatus::CANCELLED->value,
            default     => InvoiceStatus::DRAFT->value,
        };
    }
}
