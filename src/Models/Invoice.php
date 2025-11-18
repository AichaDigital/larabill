<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\Base100;
use AichaDigital\Larabill\Enums\{InvoiceSerieType, InvoiceStatus};
use Dyrynda\Database\Support\{BindsOnUuid, GeneratesUuid};
use Dyrynda\Database\Support\Casts\EfficientUuid;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Invoice Model
 *
 * Represents an invoice with fiscal compliance, immutability and encryption features.
 * Uses ordered UUID v4 for efficient binary storage and indexing.
 * All monetary amounts are stored as base-100 integers (e.g., €12.34 => 1234).
 *
 * v0.3.3: Enhanced for fiscal compliance (CEE/EU):
 * - Correlative numbering with serie separation
 * - Chronological validation with issued_at
 * - Support for proforma→invoice and rectificative invoices
 *
 * @property string $id UUID stored as binary(16)
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
 * @property int|null $customer_id FK to customers (v0.4.0)
 * @property int|null $tax_profile_id
 * @property string|null $proforma_id UUID if converted from proforma
 * @property string|null $rectifies_invoice_id UUID if rectificative
 * @property string|null $user_tax_info_encrypted
 * @property string|null $issuer_snapshot Encrypted issuer data (v0.4.0)
 * @property string|null $customer_snapshot Encrypted customer data (v0.4.0)
 * @property string|null $fiscal_snapshot Encrypted fiscal context (v0.4.0)
 * @property string|null $fiscal_verification_id Verifactu/TicketBAI ID (v0.4.0)
 * @property string|null $fiscal_verification_qr QR code (v0.4.0)
 * @property string|null $fiscal_verification_hash Hash (v0.4.0)
 * @property \Illuminate\Support\Carbon|null $fiscal_verified_at (v0.4.0)
 * @property array<string, mixed>|null $fiscal_verification_metadata (v0.4.0)
 * @property array<string, mixed>|null $customer_data
 * @property array<string, mixed>|null $fiscal_data
 * @property array<string, mixed>|null $vat_verification
 * @property bool $is_roi_taxed ROI reverse charge
 * @property string $type invoice|proforma|rectificative
 * @property float $taxable_amount Base amount before tax
 * @property float $tax_amount Calculated tax
 * @property float $total_amount Total with tax
 * @property float|null $total Total amount (alias)
 * @property float|null $subtotal Subtotal (alias for taxable_amount)
 * @property float|null $total_tax_amount Total tax (alias)
 * @property string|null $converted_invoice_id UUID of final invoice (if proforma converted)
 * @property bool $is_immutable
 * @property Carbon|null $immutable_at
 * @property string|null $notes
 * @property string|null $payment_terms
 * @property string|null $template_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Invoice extends Model
{
    use BindsOnUuid, GeneratesUuid, HasFactory;

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the auto-incrementing ID.
     */
    protected $keyType = 'string';

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
        'customer_id',
        'tax_profile_id',
        'proforma_id',
        'rectifies_invoice_id',
        'user_tax_info_encrypted',
        'issuer_snapshot',
        'customer_snapshot',
        'fiscal_snapshot',
        'fiscal_verification_id',
        'fiscal_verification_qr',
        'fiscal_verification_hash',
        'fiscal_verified_at',
        'fiscal_verification_metadata',
        'customer_data',
        'fiscal_data',
        'vat_verification',
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
     * Specify the column name for UUID generation.
     * Dyrynda package defaults to 'uuid', but we use 'id'.
     */
    public function uuidColumn(): string
    {
        return 'id';
    }

    /**
     * UUID version to use for invoice IDs.
     * Uses 'ordered' for better MySQL index performance.
     */
    public function uuidVersion(): string
    {
        return 'ordered'; // Laravel's ordered UUID v4 for better performance
    }

    /**
     * Casts for attributes.
     *
     * Uses Base100 cast from lara100 package for monetary values
     * Uses EfficientUuid cast for binary UUID storage (16 bytes vs 36 bytes)
     * Automatically handles conversion between decimals and base-100 integers
     * Example: €12.34 ↔ 1234 (stored as integer, accessed as decimal)
     */
    public function casts(): array
    {
        return [
            'id'                            => EfficientUuid::class, // Binary UUID storage (16 bytes)
            'serie'                         => InvoiceSerieType::class, // PHP Enum
            'status'                        => InvoiceStatus::class,    // PHP Enum
            'fiscal_year'                   => 'integer',
            'invoice_date'                  => 'date',
            'issued_at'                     => 'datetime',
            'service_date'                  => 'date',
            'due_date'                      => 'date',
            'paid_at'                       => 'datetime',
            'fiscal_verified_at'            => 'datetime',
            'is_immutable'                  => 'boolean',
            'immutable_at'                  => 'datetime',
            'taxable_amount'                => Base100::class, // €12.34 ↔ 1234
            'total_tax_amount'              => Base100::class,
            'total_amount'                  => Base100::class, // €12.34 ↔ 1234
            'fiscal_data'                   => 'array',
            'fiscal_verification_metadata'  => 'array',
            'vat_verification'              => 'array',
            'customer_data'                 => 'array',
            'is_roi_taxed'                  => 'boolean',
            'proforma_id'                   => EfficientUuid::class, // Binary UUID
            'rectifies_invoice_id'          => EfficientUuid::class, // Binary UUID
        ];
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
        $conversionFields    = ['is_immutable', 'converted_invoice_id', 'converted_at', 'status'];
        $isConversionUpdate  = isset($attributes['converted_invoice_id']) || isset($attributes['converted_at']);
        $isOnlyAllowedFields = empty(array_diff(array_keys($attributes), $conversionFields));

        if ($this->is_immutable && ! ($isConversionUpdate && $isOnlyAllowedFields)) {
            throw new \Exception('Cannot update an immutable invoice');
        }

        return parent::update($attributes, $options);
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * Uses custom BinaryUuidBuilder to handle UUID binary conversions in relationships.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \AichaDigital\Larabill\Database\Query\BinaryUuidBuilder
     */
    public function newEloquentBuilder($query)
    {
        return new \AichaDigital\Larabill\Database\Query\BinaryUuidBuilder($query);
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
        $invoiceItemModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('invoice_item');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->hasMany($invoiceItemModel);
    }

    /**
     * Get the user that owns the invoice.
     *
     * @return BelongsTo<\Illuminate\Foundation\Auth\User, $this>
     */
    public function user(): BelongsTo
    {
        $userModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->belongsTo($userModel);
    }

    /**
     * Get the customer (billable entity) for this invoice (v0.4.0).
     *
     * @return BelongsTo<\AichaDigital\Larabill\Models\Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(\AichaDigital\Larabill\Models\Customer::class);
    }

    /**
     * Get the tax profile snapshot for this invoice.
     * This maintains a reference to the user's tax information at invoice creation time.
     *
     * @return BelongsTo<UserTaxProfile, $this>
     */
    public function taxProfile(): BelongsTo
    {
        return $this->belongsTo(UserTaxProfile::class, 'tax_profile_id');
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
        $pdfService = app(\AichaDigital\Larabill\Services\PDF\PDFService::class);

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
}
