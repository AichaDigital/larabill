<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Concerns\HasUserRelation;
use AichaDigital\Larabill\Concerns\HasUuid;
use AichaDigital\Larabill\Database\Factories\InvoiceFactory;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Services\FiscalIntegrityChecker;
use AichaDigital\Larabill\Services\ModelMappingService;
use AichaDigital\Larabill\Services\PDF\PDFService;
use AichaDigital\LaraPrivacyCore\Contracts\LegallyRetainable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Invoice Model
 *
 * Represents an invoice with fiscal compliance, immutability and encryption features.
 * Uses UUID v7 string (36 chars) for primary key.
 * All monetary amounts are stored as base-100 integers (e.g., €12.34 => 1234).
 *
 * Fiscal data architecture (ADR-001 + ADR-003):
 * - CompanyFiscalConfig: Issuer data with temporal validity
 * - UserTaxProfile: Customer/User fiscal data with temporal validity
 *
 * v1.0: Enhanced for fiscal compliance (CEE/EU):
 * - Correlative numbering with serie separation
 * - Chronological validation with issued_at
 * - Support for proforma→invoice and rectificative invoices
 *
 * @property string $id UUID (string or binary based on config)
 * @property string $fiscal_number Complete display number (FAC-2025-000047)
 * @property string $prefix User customizable prefix
 * @property InvoiceSerieType $serie Invoice series type enum
 * @property int $series_number Pure correlative number
 * @property int $fiscal_year Calculated fiscal year
 * @property Carbon $invoice_date Legal date on PDF
 * @property Carbon $issued_at Technical chronological timestamp
 * @property Carbon|null $service_date Service/delivery date
 * @property Carbon|null $due_date Payment due date
 * @property Carbon|null $paid_at Actual payment timestamp
 * @property InvoiceStatus $status Invoice status enum
 * @property int|string $user_id
 * @property string|null $billable_user_id UUID FK to users - User being billed (ADR-003)
 * @property int|null $company_fiscal_config_id FK to company_fiscal_configs (ADR-001)
 * @property int|null $user_tax_profile_id FK to user_tax_profiles (ADR-003)
 * @property string|null $proforma_id UUID if converted from proforma
 * @property string|null $rectifies_invoice_id UUID if rectificative
 * @property string|null $issuer_snapshot Encrypted issuer data (v0.4.0)
 * @property string|null $customer_snapshot Encrypted customer data (v0.4.0)
 * @property string|null $fiscal_snapshot Encrypted fiscal context (v0.4.0)
 * @property string|null $fiscal_verification_id Verifactu/TicketBAI ID (v0.4.0)
 * @property string|null $fiscal_verification_qr QR code (v0.4.0)
 * @property string|null $fiscal_verification_hash Hash (v0.4.0)
 * @property Carbon|null $fiscal_verified_at (v0.4.0)
 * @property array<string, mixed>|null $fiscal_verification_metadata (v0.4.0)
 * @property bool $is_roi_taxed ROI reverse charge
 * @property string $type invoice|proforma|rectificative
 * @property FixedDecimal $taxable_amount Base amount before tax
 * @property float $tax_amount Calculated tax
 * @property FixedDecimal $total_amount Total with tax
 * @property float|null $total Total amount (alias)
 * @property float|null $subtotal Subtotal (alias for taxable_amount)
 * @property FixedDecimal $total_tax_amount Total tax
 * @property string|null $converted_invoice_id UUID of final invoice (if proforma converted)
 * @property bool $is_immutable
 * @property Carbon|null $immutable_at
 * @property string|null $notes
 * @property string|null $payment_terms
 * @property string|null $template_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Invoice extends Model implements LegallyRetainable
{
    use HasFactory, HasUserRelation, HasUuid;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }

    /**
     * Boot the model (ADR-001).
     *
     * Auto-load fiscal configs on invoice creation for immutability.
     * Validates fiscal integrity before allowing invoice creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Al crear factura, verificar integridad fiscal y snapshot automático
        static::creating(function (Invoice $invoice) {
            // 1. Verificar integridad fiscal antes de crear
            // CRÍTICO: Bloquea facturación si hay configs duplicadas
            $integrityChecker = app(FiscalIntegrityChecker::class);

            // Check global (CompanyFiscalConfig)
            $integrityChecker->assertCanInvoice();

            // Check específico del usuario (UserTaxProfile) si hay billable_user_id
            if ($invoice->billable_user_id) {
                $integrityChecker->assertCanInvoiceUser($invoice->billable_user_id);
            }

            // 2. Solo si no es proforma Y no tiene configs ya asignadas
            if ($invoice->serie !== InvoiceSerieType::PROFORMA && ! $invoice->company_fiscal_config_id) {
                $invoice->snapshotFiscalConfigs();
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'fiscal_number',
        'prefix',
        'serie',
        'series_number',
        'fiscal_year',
        'invoice_date',
        'issued_at',
        'service_date',
        'due_date',
        'paid_at',
        'status',
        'user_id',
        'billable_user_id',
        'company_fiscal_config_id',
        'user_tax_profile_id',
        'proforma_id',
        'rectifies_invoice_id',
        'issuer_snapshot',
        'customer_snapshot',
        'fiscal_snapshot',
        'fiscal_verification_id',
        'fiscal_verification_qr',
        'fiscal_verification_hash',
        'fiscal_verified_at',
        'fiscal_verification_metadata',
        'is_roi_taxed',
        'taxable_amount',
        'total_tax_amount',
        'total_amount',
        'converted_invoice_id',
        'converted_at',
        'is_immutable',
        'immutable_at',
        'notes',
        'payment_terms',
        'template_name',
    ];

    /**
     * Casts for attributes.
     *
     * Uses Base100 cast from lara100 package for monetary values
     * Automatically handles conversion between decimals and base-100 integers
     * Example: €12.34 ↔ 1234 (stored as integer, accessed as decimal)
     *
     * Note: UUID fields (id, proforma_id, rectifies_invoice_id) are handled by HasUuid trait
     */
    public function casts(): array
    {
        return [
            'serie'                        => InvoiceSerieType::class, // PHP Enum
            'status'                       => InvoiceStatus::class,    // PHP Enum
            'fiscal_year'                  => 'integer',
            'invoice_date'                 => 'date',
            'issued_at'                    => 'datetime',
            'service_date'                 => 'date',
            'due_date'                     => 'date',
            'paid_at'                      => 'datetime',
            'fiscal_verified_at'           => 'datetime',
            'is_immutable'                 => 'boolean',
            'immutable_at'                 => 'datetime',
            'taxable_amount'               => FixedDecimalCast::class.':2', // €12.34 ↔ 1234
            'total_tax_amount'             => FixedDecimalCast::class.':2',
            'total_amount'                 => FixedDecimalCast::class.':2', // €12.34 ↔ 1234
            'fiscal_verification_metadata' => 'array',
            'is_roi_taxed'                 => 'boolean',
        ];
    }

    /**
     * The instant until which this invoice must be retained under the ordinary
     * commercial-accounting retention hold (lara-privacy `LegallyRetainable`).
     *
     * Fiscal invoices (INVOICE, SIMPLIFIED, RECTIFICATIVE) are anchored at the
     * end of the fiscal year of their legal date and kept for the configured
     * number of years (larabill.retention.fiscal_years, default 6 — Código de
     * Comercio art. 30). A proforma is not a fiscal document, and a fiscal
     * invoice without a legal date yet has no valid anchor, so both yield null.
     *
     * larabill owns this obligation; lara-privacy only reads it to block or
     * restrict the record while the hold is active — it never decides erasure.
     */
    public function retainedUntil(): ?DateTimeInterface
    {
        if (! $this->serie->isFiscal()) {
            return null;
        }

        if ($this->invoice_date === null) {
            return null;
        }

        $years = (int) config('larabill.retention.fiscal_years', 6);

        return $this->invoice_date->copy()->endOfYear()->addYears($years);
    }

    /**
     * Make the invoice immutable.
     */
    public function makeImmutable(): void
    {
        $this->is_immutable = true;
        $this->immutable_at = now();
        $this->save();
    }

    /**
     * Override update to prevent modifications of immutable invoices.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        // Allow updating conversion-related fields even on immutable invoices (for proforma conversion)
        $conversionFields          = ['is_immutable', 'converted_invoice_id', 'converted_at', 'status'];
        $fiscalVerificationFields  = [
            'fiscal_verification_id',
            'fiscal_verification_qr',
            'fiscal_verification_hash',
            'fiscal_verified_at',
            'fiscal_verification_metadata',
        ];
        $attributeKeys              = array_keys($attributes);
        $isConversionUpdate         = isset($attributes['converted_invoice_id']) || isset($attributes['converted_at']);
        $isFiscalVerificationUpdate = array_intersect($attributeKeys, $fiscalVerificationFields) !== [];
        $isOnlyConversionFields     = empty(array_diff($attributeKeys, $conversionFields));
        $isOnlyFiscalFields         = empty(array_diff($attributeKeys, $fiscalVerificationFields));

        if ($this->is_immutable
            && ! (
                ($isConversionUpdate && $isOnlyConversionFields)
                || ($isFiscalVerificationUpdate && $isOnlyFiscalFields)
            )) {
            throw new \Exception('Cannot update an immutable invoice');
        }

        return parent::update($attributes, $options);
    }

    // ========================================
    // RELATIONSHIPS
    // ========================================

    /**
     * Get the invoice items.
     *
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        $invoiceItemModel = ModelMappingService::getModelClass('invoice_item');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->hasMany($invoiceItemModel);
    }

    /**
     * Get the user that owns the invoice.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        $userModel = ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->belongsTo($userModel);
    }

    /**
     * Get the billable user (user being billed) for this invoice (ADR-003).
     *
     * @return BelongsTo<User, $this>
     */
    public function billableUser(): BelongsTo
    {
        $userModel = ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->belongsTo($userModel, 'billable_user_id');
    }

    /**
     * Get the proforma invoice (if this was converted from proforma)
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function proforma(): BelongsTo
    {
        return $this->belongsTo(self::class, 'proforma_id');
    }

    /**
     * Get the original invoice (if this is a rectificative)
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function rectifiesInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rectifies_invoice_id');
    }

    /**
     * Get rectificative invoices for this invoice
     *
     * @return HasMany<Invoice, $this>
     */
    public function rectificatives(): HasMany
    {
        return $this->hasMany(self::class, 'rectifies_invoice_id');
    }

    /**
     * Get invoices converted from this proforma
     *
     * @return HasMany<Invoice, $this>
     */
    public function convertedInvoices(): HasMany
    {
        return $this->hasMany(self::class, 'proforma_id');
    }

    /**
     * Get the company fiscal config snapshot for this invoice (ADR-001).
     *
     * This maintains a reference to the company's fiscal identity at invoice creation time.
     * IMMUTABLE: Once invoice is created, this relationship NEVER changes.
     *
     * Uses withTrashed(): a soft-deleted config is fiscal-history closure, not erasure.
     * The immutable invoice must keep access to its historical snapshot (AID-222).
     *
     * @return BelongsTo<CompanyFiscalConfig, $this>
     */
    public function companyFiscalConfig(): BelongsTo
    {
        return $this->belongsTo(CompanyFiscalConfig::class, 'company_fiscal_config_id')->withTrashed();
    }

    /**
     * Get the user tax profile snapshot for this invoice (ADR-003).
     *
     * This maintains a reference to the user's fiscal identity at invoice creation time.
     * IMMUTABLE: Once invoice is created, this relationship NEVER changes.
     *
     * Uses withTrashed(): a soft-deleted profile is fiscal-history closure, not erasure.
     * Without it, validateForVerifactu() wrongly fails on a valid issued invoice (AID-222).
     *
     * @return BelongsTo<UserTaxProfile, $this>
     */
    public function userTaxProfile(): BelongsTo
    {
        return $this->belongsTo(UserTaxProfile::class, 'user_tax_profile_id')->withTrashed();
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Check if this invoice should include QR code
     *
     * @return bool True if QR should be included
     */
    public function shouldIncludeQR(): bool
    {
        // Proforma invoices never include QR
        if ($this->serie === InvoiceSerieType::PROFORMA) {
            return false;
        }

        // Only fiscal invoices include QR
        return $this->serie === InvoiceSerieType::INVOICE || $this->serie === InvoiceSerieType::RECTIFICATIVE;
    }

    /**
     * Check if this invoice has been fiscally verified (v0.4.0).
     */
    public function isFiscallyVerified(): bool
    {
        return $this->fiscal_verification_id !== null && $this->fiscal_verified_at !== null;
    }

    /**
     * Check if this invoice is still a draft.
     */
    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::DRAFT;
    }

    /**
     * Get decrypted issuer snapshot (v0.4.0).
     *
     * @return array<string, mixed>|null
     */
    public function getIssuerSnapshotData(): ?array
    {
        if (! $this->issuer_snapshot) {
            return null;
        }

        return json_decode(Crypt::decryptString($this->issuer_snapshot), true);
    }

    /**
     * Get decrypted customer snapshot (v0.4.0).
     *
     * @return array<string, mixed>|null
     */
    public function getCustomerSnapshotData(): ?array
    {
        if (! $this->customer_snapshot) {
            return null;
        }

        return json_decode(Crypt::decryptString($this->customer_snapshot), true);
    }

    /**
     * Get decrypted fiscal snapshot (v0.4.0).
     *
     * @return array<string, mixed>|null
     */
    public function getFiscalSnapshotData(): ?array
    {
        if (! $this->fiscal_snapshot) {
            return null;
        }

        return json_decode(Crypt::decryptString($this->fiscal_snapshot), true);
    }

    /**
     * Get the invoice type for PDF generation
     *
     * @return string Invoice type
     */
    public function getInvoiceType(): string
    {
        return $this->serie->label();
    }

    /**
     * Generate PDF for this invoice
     *
     * @return array<string, mixed> PDF generation result
     */
    public function generatePDF(): array
    {
        $pdfService = app(PDFService::class);

        return $pdfService->generatePDF($this);
    }

    /**
     * Get PDF path for this invoice
     *
     * @return string|null PDF file path
     */
    public function getPDFPath(): ?string
    {
        $filename = 'invoice_'.$this->id.'_'.$this->getInvoiceType().'.pdf';
        $pdfPath  = storage_path('app/invoices/'.$filename);

        return file_exists($pdfPath) ? $pdfPath : null;
    }

    /**
     * Get PDF URL for this invoice
     *
     * @return string|null PDF URL
     */
    public function getPDFUrl(): ?string
    {
        $path = $this->getPDFPath();
        if (! $path) {
            return null;
        }

        $filename = 'invoice_'.$this->id.'_'.$this->getInvoiceType().'.pdf';

        return url('storage/invoices/'.$filename);
    }

    // ========================================
    // SCOPES
    // ========================================

    /**
     * Scope for specific serie
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSerie(Builder $query, InvoiceSerieType $serie): Builder
    {
        return $query->where('serie', $serie->value);
    }

    /**
     * Scope for specific fiscal year
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFiscalYear(Builder $query, int $year): Builder
    {
        return $query->where('fiscal_year', $year);
    }

    /**
     * Scope for specific status
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStatus(Builder $query, InvoiceStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope for proforma invoices
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeProformas(Builder $query): Builder
    {
        return $query->where('serie', InvoiceSerieType::PROFORMA->value);
    }

    /**
     * Scope for final invoices (not proformas or rectificatives)
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFinal(Builder $query): Builder
    {
        return $query->where('serie', InvoiceSerieType::INVOICE->value);
    }

    /**
     * Scope for rectificative invoices
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRectificatives(Builder $query): Builder
    {
        return $query->where('serie', InvoiceSerieType::RECTIFICATIVE->value);
    }

    // ========================================
    // TAX CALCULATION METHODS
    // ========================================

    /**
     * Check if this invoice requires VAT to be applied.
     *
     * An invoice requires VAT when it's NOT using reverse charge mechanism.
     * Reverse charge (ROI) is used for B2B intra-community transactions where
     * the customer (not the issuer) is responsible for paying VAT.
     *
     * @return bool True if VAT should be applied by the issuer
     */
    public function requiresVAT(): bool
    {
        return ! $this->is_roi_taxed;
    }

    /**
     * Snapshot fiscal configs at invoice creation (ADR-001).
     *
     * CRITICAL: This method loads and stores the fiscal identities
     * of both company (issuer) and customer (recipient) at the moment
     * of invoice creation, ensuring IMMUTABILITY.
     *
     * Includes:
     * - FK references to CompanyFiscalConfig and UserTaxProfile
     * - Encrypted snapshots for complete audit trail
     *
     * Called automatically on invoice creation (boot::creating).
     *
     * @param  bool  $generateEncrypted  Whether to generate encrypted snapshots (default: true)
     */
    public function snapshotFiscalConfigs(bool $generateEncrypted = true): void
    {
        $invoiceDate = $this->invoice_date ?? now();

        // 1. Company fiscal config (emisor)
        $companyConfig = CompanyFiscalConfig::getValidAt($invoiceDate);
        if ($companyConfig) {
            $this->company_fiscal_config_id = $companyConfig->id;

            // Generate encrypted issuer snapshot (ADR-001)
            if ($generateEncrypted && ! $this->issuer_snapshot) {
                $this->issuer_snapshot = $this->generateIssuerSnapshot($companyConfig);
            }
        }

        // 2. User tax profile (receptor - billable user, ADR-003)
        $userTaxProfile = null;
        if ($this->billable_user_id) {
            $userTaxProfile = UserTaxProfile::getValidForOwnerAt($this->billable_user_id, $invoiceDate);
            if ($userTaxProfile) {
                $this->user_tax_profile_id = $userTaxProfile->id;
            }

            // Generate encrypted customer snapshot (ADR-001)
            if ($generateEncrypted && ! $this->customer_snapshot) {
                $billableUser = $this->billableUser;
                if ($billableUser) {
                    $this->customer_snapshot = $this->generateBillableUserSnapshot($billableUser, $userTaxProfile);
                }
            }
        }

        // 3. Generate fiscal context snapshot (ADR-001)
        if ($generateEncrypted && ! $this->fiscal_snapshot && $companyConfig) {
            $this->fiscal_snapshot = $this->generateFiscalContextSnapshot($companyConfig, $userTaxProfile);
        }
    }

    /**
     * Generate encrypted issuer snapshot from CompanyFiscalConfig (ADR-001).
     *
     * @param  CompanyFiscalConfig  $config  Active company config at invoice date
     * @return string Encrypted JSON snapshot
     */
    protected function generateIssuerSnapshot(CompanyFiscalConfig $config): string
    {
        $data = [
            'config_id'         => $config->id,
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
            'valid_from'        => $config->valid_from->toIso8601String(),
            'valid_until'       => $config->valid_until?->toIso8601String(),
            'snapshot_at'       => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data));
    }

    /**
     * Generate encrypted billable user snapshot (ADR-001 + ADR-003).
     *
     * @param  Model  $billableUser  The user being billed
     * @param  UserTaxProfile|null  $taxProfile  User's tax profile at invoice date
     * @return string Encrypted JSON snapshot
     */
    protected function generateBillableUserSnapshot(Model $billableUser, ?UserTaxProfile $taxProfile): string
    {
        $data = [
            'billable_user_id'     => $billableUser->id,
            'user_name'            => $billableUser->display_name              ?? $billableUser->name ?? null,
            'user_email'           => $billableUser->email                     ?? null,
            'relationship_type'    => $billableUser->relationship_type?->value ?? 0,
            'parent_user_id'       => $billableUser->parent_user_id            ?? null,
            'legal_entity_code'    => $billableUser->legal_entity_type_code    ?? null,
            'tax_profile_id'       => $taxProfile?->id,
            'fiscal_name'          => $taxProfile?->fiscal_name ?? $billableUser->display_name ?? $billableUser->name ?? null,
            'tax_id'               => $taxProfile?->tax_id,
            'legal_entity_type'    => $taxProfile?->legal_entity_type_code ?? $billableUser->legal_entity_type_code ?? null,
            'address'              => $taxProfile?->address,
            'city'                 => $taxProfile?->city,
            'state'                => $taxProfile?->state,
            'zip_code'             => $taxProfile?->zip_code,
            'country_code'         => $taxProfile?->country_code,
            'is_company'           => $taxProfile?->is_company           ?? false,
            'is_eu_vat_registered' => $taxProfile?->is_eu_vat_registered ?? false,
            'is_exempt_vat'        => $taxProfile?->is_exempt_vat        ?? false,
            'profile_valid_from'   => $taxProfile?->valid_from?->toIso8601String(),
            'profile_valid_until'  => $taxProfile?->valid_until?->toIso8601String(),
            'snapshot_at'          => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data));
    }

    /**
     * Generate encrypted fiscal context snapshot (ADR-001).
     *
     * Contains fiscal rules and context at time of invoice creation.
     *
     * @param  CompanyFiscalConfig  $companyConfig  Active company config
     * @param  UserTaxProfile|null  $customerProfile  Customer's tax profile
     * @return string Encrypted JSON snapshot
     */
    protected function generateFiscalContextSnapshot(CompanyFiscalConfig $companyConfig, ?UserTaxProfile $customerProfile): string
    {
        $data = [
            'fiscal_year'              => $this->fiscal_year   ?? now()->year,
            'serie'                    => $this->serie?->value ?? $this->serie,
            'series_number'            => $this->series_number,
            'fiscal_number'            => $this->fiscal_number,
            'invoice_date'             => ($this->invoice_date ?? now())->toIso8601String(),
            'issuer_country'           => $companyConfig->country_code,
            'customer_country'         => $customerProfile?->country_code,
            'is_intra_community'       => $this->isIntraCommunityTransaction($companyConfig, $customerProfile),
            'is_roi_applied'           => $this->is_roi_taxed ?? false,
            'issuer_is_oss'            => $companyConfig->is_oss,
            'customer_is_vat_reg'      => $customerProfile?->is_eu_vat_registered ?? false,
            'currency'                 => $companyConfig->currency                ?? 'EUR',
            'exchange_rate'            => 1.0, // TODO: Implement multi-currency
            'company_fiscal_config_id' => $companyConfig->id,
            'user_tax_profile_id'      => $customerProfile?->id,
            'snapshot_at'              => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data));
    }

    /**
     * Check if transaction is intra-community (EU cross-border B2B).
     *
     * @param  CompanyFiscalConfig  $issuer  Issuer's fiscal config
     * @param  UserTaxProfile|null  $customer  Customer's tax profile
     * @return bool True if intra-community transaction
     */
    protected function isIntraCommunityTransaction(CompanyFiscalConfig $issuer, ?UserTaxProfile $customer): bool
    {
        if (! $customer) {
            return false;
        }

        // Both must be in EU
        $euCountries = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

        $issuerInEU   = in_array($issuer->country_code, $euCountries, true);
        $customerInEU = in_array($customer->country_code, $euCountries, true);

        // Different countries, both in EU, customer is VAT registered
        return $issuerInEU
            && $customerInEU
            && $issuer->country_code !== $customer->country_code
            && $customer->is_eu_vat_registered;
    }

    /**
     * Get full company fiscal identity at invoice creation.
     *
     * Returns the CompanyFiscalConfig snapshot.
     * IMMUTABLE: Always returns the config valid at invoice_date.
     */
    public function getCompanyFiscalSnapshot(): ?CompanyFiscalConfig
    {
        return $this->companyFiscalConfig;
    }

    /**
     * Get full user fiscal identity at invoice creation.
     *
     * Returns the UserTaxProfile snapshot.
     * IMMUTABLE: Always returns the data valid at invoice_date.
     */
    public function getUserTaxProfileSnapshot(): ?UserTaxProfile
    {
        return $this->userTaxProfile;
    }

    /**
     * Check if invoice has fiscal reference FKs loaded.
     *
     * @param  bool  $includeEncrypted  Also check encrypted snapshots (default: false)
     */
    public function hasFiscalSnapshots(bool $includeEncrypted = false): bool
    {
        $hasReferences = $this->company_fiscal_config_id !== null && $this->user_tax_profile_id !== null;

        if (! $includeEncrypted) {
            return $hasReferences;
        }

        return $hasReferences && $this->hasEncryptedSnapshots();
    }

    /**
     * Check if invoice has all encrypted snapshots (ADR-001).
     *
     * All three snapshots are required for full fiscal audit trail:
     * - issuer_snapshot: Company fiscal identity
     * - customer_snapshot: Billable user identity
     * - fiscal_snapshot: Fiscal context and rules
     */
    public function hasEncryptedSnapshots(): bool
    {
        return $this->issuer_snapshot   !== null
            && $this->customer_snapshot !== null
            && $this->fiscal_snapshot   !== null;
    }

    /**
     * Regenerate encrypted snapshots (ADR-001).
     *
     * Forces regeneration of all encrypted snapshots.
     * Use with caution - only for draft/pending invoices.
     *
     * @throws \Exception If invoice is immutable
     */
    public function regenerateEncryptedSnapshots(): void
    {
        if ($this->is_immutable) {
            throw new \Exception('Cannot regenerate snapshots on immutable invoice');
        }

        // Clear existing snapshots
        $this->issuer_snapshot   = null;
        $this->customer_snapshot = null;
        $this->fiscal_snapshot   = null;

        // Regenerate
        $this->snapshotFiscalConfigs(true);
    }

    /**
     * Check if this invoice uses reverse charge mechanism (ROI).
     *
     * Reverse charge applies to:
     * - B2B intra-community transactions (EU)
     * - When both issuer and customer are VAT registered
     * - Customer is responsible for paying VAT in their country
     *
     * @return bool True if reverse charge applies
     */
    public function isReverseCharge(): bool
    {
        return $this->is_roi_taxed;
    }

    /**
     * Calculate totals from invoice items.
     *
     * Recalculates taxable_amount, total_tax_amount, and total_amount
     * based on the sum of all invoice items. Updates the model but does not save.
     */
    public function calculateTotals(): self
    {
        // Items persist integer cents, so the SQL SUM is exact (sum of per-line
        // rounded amounts — the EU/Spain aggregation rule). Wrap back into a
        // scale-2 FixedDecimal for the cast.
        $this->taxable_amount   = FixedDecimal::ofUnscaled((int) $this->items()->sum('taxable_amount'), 2);
        $this->total_tax_amount = FixedDecimal::ofUnscaled((int) $this->items()->sum('total_tax_amount'), 2);
        $this->total_amount     = FixedDecimal::ofUnscaled((int) $this->items()->sum('total_amount'), 2);

        return $this;
    }
}
