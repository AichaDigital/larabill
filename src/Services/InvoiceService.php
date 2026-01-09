<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Contracts\Services\FiscalVerificationContract;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\FiscalConfigChangedException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\UserTaxProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Invoice Service
 *
 * Modern service with encrypted snapshots and fiscal verification integration.
 * Uses CompanyFiscalConfig for issuer data and UserTaxProfile for customer data (ADR-003).
 */
class InvoiceService
{
    public function __construct(
        protected TaxCalculationService $taxCalculationService,
        protected ?FiscalVerificationContract $fiscalVerification = null,
        protected ?FiscalChangeDetector $fiscalChangeDetector = null
    ) {
        $this->fiscalChangeDetector ??= app(FiscalChangeDetector::class);
    }

    /**
     * Create a new invoice with encrypted snapshots.
     *
     * ADR-003: Uses billable_user_id (User) instead of customer_id.
     *
     * @param  array{
     *     billable_user_id: string,
     *     user_id?: string,
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
        return DB::transaction(function () use ($invoiceData, $options): Invoice {
            // Get billable user (ADR-003)
            $userModel    = ModelMappingService::getModelClass('user');
            $billableUser = $userModel::findOrFail($invoiceData['billable_user_id']);

            $companyConfig = CompanyFiscalConfig::getActive();

            if (! $companyConfig) {
                throw new \RuntimeException('No valid CompanyFiscalConfig found for invoice creation');
            }

            // Get user tax profile for billable user
            $userTaxProfile = UserTaxProfile::getValidForOwnerAt(
                $billableUser->id,
                now()
            );

            // Determine invoice type and serie
            $invoiceType = $invoiceData['type'] ?? 'invoice';
            $serie       = $invoiceType === 'proforma' ? InvoiceSerieType::PROFORMA->value : InvoiceSerieType::INVOICE->value;
            $status      = isset($invoiceData['status']) ? $this->mapStatusToEnum($invoiceData['status']) : InvoiceStatus::DRAFT->value;

            // user_id = owner of invoice (issuer), defaults to billable_user's parent or self
            $userId = $invoiceData['user_id'] ?? $billableUser->parent_user_id ?? $billableUser->id;

            // Create invoice record with atomic series numbering
            $invoice = Invoice::create([
                'fiscal_number'             => $this->generateInvoiceNumber($invoiceType),
                'prefix'                    => $invoiceType === 'proforma' ? 'PRO' : 'FAC',
                'serie'                     => $serie,
                'series_number'             => $this->getTempSeriesNumber((string) $serie, now()->year),
                'fiscal_year'               => now()->year,
                'invoice_date'              => now()->toDateString(),
                'issued_at'                 => now(),
                'status'                    => $status,
                'billable_user_id'          => $billableUser->id,
                'user_id'                   => $userId,
                'company_fiscal_config_id'  => $companyConfig->id,
                'user_tax_profile_id'       => $userTaxProfile?->id,
                'is_immutable'              => false,
                'due_date'                  => $invoiceData['due_date']      ?? null,
                'payment_terms'             => $invoiceData['payment_terms'] ?? null,
                'template_name'             => $invoiceData['template_name'] ?? null,
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
            $invoice->issuer_snapshot   = $this->generateIssuerSnapshot($companyConfig);
            $invoice->customer_snapshot = $this->generateBillableUserSnapshot($billableUser, $userTaxProfile);
            $invoice->fiscal_snapshot   = $this->generateFiscalSnapshot($invoice, $companyConfig, $userTaxProfile);

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
        });
    }

    /**
     * Generate encrypted issuer snapshot from CompanyFiscalConfig.
     */
    protected function generateIssuerSnapshot(CompanyFiscalConfig $config): string
    {
        $data = [
            'business_name'     => $config->business_name,
            'tax_id'            => $config->tax_id,
            'legal_entity_type' => $config->legal_entity_type,
            'address'           => $config->address,
            'city'              => $config->city,
            'state'             => $config->state,
            'zip_code'          => $config->zip_code,
            'country_code'      => $config->country_code,
            'is_oss'            => $config->is_oss,
            'is_roi'            => $config->is_roi,
            'currency'          => $config->currency,
            'snapshot_at'       => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data));
    }

    /**
     * Generate encrypted billable user snapshot from User + UserTaxProfile (ADR-003).
     */
    protected function generateBillableUserSnapshot(Model $billableUser, ?UserTaxProfile $fiscalData): string
    {
        $data = [
            'billable_user_id'     => $billableUser->id,
            'user_name'            => $billableUser->display_name              ?? $billableUser->name,
            'relationship_type'    => $billableUser->relationship_type?->value ?? 0,
            'fiscal_name'          => $fiscalData?->fiscal_name                ?? $billableUser->display_name ?? $billableUser->name,
            'tax_id'               => $fiscalData?->tax_id,
            'legal_entity_type'    => $fiscalData?->legal_entity_type_code     ?? $billableUser->legal_entity_type_code ?? null,
            'address'              => $fiscalData?->address,
            'city'                 => $fiscalData?->city,
            'state'                => $fiscalData?->state,
            'zip_code'             => $fiscalData?->zip_code,
            'country_code'         => $fiscalData?->country_code,
            'is_company'           => $fiscalData?->is_company                 ?? false,
            'is_eu_vat_registered' => $fiscalData?->is_eu_vat_registered       ?? false,
            'is_exempt_vat'        => $fiscalData?->is_exempt_vat              ?? false,
            'snapshot_at'          => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data));
    }

    /**
     * Generate encrypted fiscal snapshot.
     */
    protected function generateFiscalSnapshot(
        Invoice $invoice,
        CompanyFiscalConfig $companyConfig,
        ?UserTaxProfile $customerData
    ): string {
        $data = [
            'fiscal_year'       => $invoice->fiscal_year,
            'serie'             => $invoice->serie,
            'series_number'     => $invoice->series_number,
            'fiscal_number'     => $invoice->fiscal_number,
            'invoice_date'      => $invoice->invoice_date,
            'issuer_country'    => $companyConfig->country_code,
            'customer_country'  => $customerData?->country_code,
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
        // ADR-003: Uses billable_user_id instead of customer_id
        $result = $this->fiscalVerification->verify([
            'id'               => $invoice->id,
            'fiscal_number'    => $invoice->fiscal_number,
            'serie'            => $invoice->serie->value,
            'series_number'    => $invoice->series_number,
            'fiscal_year'      => $invoice->fiscal_year,
            'invoice_date'     => $invoice->invoice_date,
            'billable_user_id' => $invoice->billable_user_id,
            'total_amount'     => $invoice->total_amount,
            'taxable_amount'   => $invoice->taxable_amount,
        ]);

        if ($result['success']) {
            $invoice->update([
                'fiscal_verification_id'       => $result['id']   ?? null,
                'fiscal_verification_qr'       => $result['qr']   ?? null,
                'fiscal_verification_hash'     => $result['hash'] ?? null,
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
        $quantity  = $itemData['quantity']    ?? 1;
        $basePrice = $itemData['base_price']  ?? ($itemData['unit_price'] ?? 0);

        // Calculate taxes using TaxCalculationService (ADR-003: billable_user_id)
        $taxCalculation = $this->taxCalculationService->calculateForInvoiceItem([
            'article_id'       => $itemData['article_id'] ?? 0,
            'quantity'         => $quantity,
            'base_price'       => $basePrice,
            'billable_user_id' => $invoice->billable_user_id,
        ]);

        return InvoiceItem::create([
            'invoice_id'       => $invoice->id,
            'description'      => $itemData['description'] ?? '',
            'quantity'         => $quantity,
            'unit_price'       => $basePrice,
            'taxable_amount'   => $taxCalculation['taxable_amount'],
            'total_tax_amount' => $taxCalculation['total_tax_amount'],
            'total_amount'     => $taxCalculation['total_amount'],
        ]);
    }

    /**
     * Create a proforma invoice.
     *
     * ADR-003: Uses billable_user_id (User) instead of customer_id.
     *
     * @param  array{
     *     billable_user_id: string,
     *     user_id?: string,
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
     * ADR-001: Detects fiscal configuration changes since proforma creation.
     * ADR-003: Uses billable_user_id (User) instead of customer_id.
     *
     * @param  array{
     *     verify_fiscally?: bool,
     *     force?: bool,
     *     on_changes?: 'throw'|'warn'|'ignore'
     * }  $options
     * @return Invoice|array{invoice: Invoice, warnings: array<string>}
     *
     * @throws FiscalConfigChangedException When critical changes detected and force=false
     */
    public function convertProformaToInvoice(Invoice $proforma, array $options = []): Invoice|array
    {
        return DB::transaction(function () use ($proforma, $options): Invoice|array {
            // Refresh proforma within transaction
            $proforma->refresh();

            if ($proforma->serie !== InvoiceSerieType::PROFORMA) {
                throw new \InvalidArgumentException('Invoice is not a proforma');
            }

            // Idempotency check: return existing invoice if already converted
            if ($proforma->converted_invoice_id) {
                $existingInvoice = Invoice::find($proforma->converted_invoice_id);
                if ($existingInvoice) {
                    return $existingInvoice;
                }
                throw new \InvalidArgumentException('Proforma already converted to invoice');
            }

            // ADR-001: Detect fiscal configuration changes
            $force     = $options['force']          ?? false;
            $onChanges = $options['on_changes']     ?? 'throw';
            $warnings  = [];

            $changes = $this->fiscalChangeDetector->detectChanges($proforma);

            if ($changes['has_changes']) {
                $summary = $this->fiscalChangeDetector->getChangeSummary($proforma);

                if ($changes['has_critical_changes'] && ! $force) {
                    // Critical changes - block conversion unless forced
                    throw FiscalConfigChangedException::fromChanges($proforma, $changes);
                }

                // Non-critical changes or forced - handle according to on_changes option
                if ($onChanges === 'throw' && ! $force) {
                    throw FiscalConfigChangedException::fromChanges($proforma, $changes);
                }

                if ($onChanges === 'warn') {
                    $warnings = $summary;
                }
                // 'ignore' - just proceed
            }

            // Create new invoice from proforma data (ADR-003: billable_user_id)
            $invoiceData = [
                'billable_user_id' => $proforma->billable_user_id,
                'user_id'          => $proforma->user_id,
                'items'            => $proforma->items->map(fn ($item) => [
                    'article_id'  => $item->article_id,
                    'description' => $item->description,
                    'quantity'    => $item->quantity,
                    'base_price'  => $item->unit_price,
                ])->toArray(),
                'type'          => 'invoice',
                'status'        => 'pending',
                'due_date'      => $proforma->due_date?->toDateString(),
                'payment_terms' => $proforma->payment_terms ? (int) $proforma->payment_terms : null,
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

            // Return with warnings if any
            if (! empty($warnings)) {
                return [
                    'invoice'  => $invoice,
                    'warnings' => $warnings,
                ];
            }

            return $invoice;
        });
    }

    /**
     * Check if a proforma can be safely converted without fiscal changes.
     */
    public function canConvertProformaSafely(Invoice $proforma): bool
    {
        return $this->fiscalChangeDetector->canConvertSafely($proforma);
    }

    /**
     * Get fiscal changes summary for a proforma.
     *
     * @return array<string, mixed>
     */
    public function getProformaFiscalChanges(Invoice $proforma): array
    {
        return $this->fiscalChangeDetector->detectChanges($proforma);
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
     *
     * Uses pessimistic locking to prevent race conditions.
     */
    protected function getTempSeriesNumber(string $serie, int $year): int
    {
        // TODO: Implement proper series tracking
        // Uses lockForUpdate() within the outer transaction for atomicity
        $maxNumber = Invoice::where('serie', $serie)
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->max('series_number');

        return ($maxNumber ?? 0) + 1;
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
