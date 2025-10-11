<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\Base100;
use Dyrynda\Database\Casts\EfficientUuid;
use Dyrynda\Database\Support\{BindsOnUuid, GeneratesUuid};
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Carbon;

/**
 * Invoice Model
 *
 * Represents an invoice with immutability and encryption features.
 * Uses ordered UUID v4 for efficient binary storage and indexing.
 * All monetary amounts are stored as base-100 integers (e.g., €12.34 => 1234).
 *
 * @property string $id UUID stored as binary(16)
 * @property string $number
 * @property string $type
 * @property string $status
 * @property int|string $user_id
 * @property int|null $tax_profile_id
 * @property string|null $user_tax_info_encrypted
 * @property array<string, mixed>|null $customer_data
 * @property bool $is_immutable
 * @property Carbon|null $immutable_at
 * @property int $subtotal Base-100 integer (e.g., 1234 => €12.34)
 * @property int $tax_amount Base-100 integer (e.g., 1234 => €12.34)
 * @property int $total Base-100 integer (e.g., 1234 => €12.34)
 * @property array<string, mixed>|null $fiscal_data
 * @property array<string, mixed>|null $vat_verification
 * @property bool $is_roi_taxed
 * @property Carbon|null $due_date
 * @property Carbon|null $paid_at
 * @property string|null $notes
 * @property string|null $payment_terms
 * @property string|null $template_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Invoice extends Model
{
    use HasFactory, GeneratesUuid, BindsOnUuid;

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
        'number',
        'type',
        'status',
        'user_id',
        'tax_profile_id',
        'user_tax_info_encrypted',
        'customer_data',
        'is_immutable',
        'immutable_at',
        'subtotal',
        'tax_amount',
        'total',
        'fiscal_data',
        'vat_verification',
        'is_roi_taxed',
        'due_date',
        'paid_at',
        'notes',
        'payment_terms',
        'template_name',
    ];

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
            'id'               => EfficientUuid::class, // Binary UUID storage
            'is_immutable'     => 'boolean',
            'immutable_at'     => 'datetime',
            'paid_at'          => 'datetime',
            'due_date'         => 'date',
            'subtotal'         => Base100::class, // €12.34 ↔ 1234
            'tax_amount'       => Base100::class, // €12.34 ↔ 1234
            'total'            => Base100::class, // €12.34 ↔ 1234
            'fiscal_data'      => 'array',
            'vat_verification' => 'array',
            'customer_data'    => 'array',
            'is_roi_taxed'     => 'boolean',
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
        if ($this->is_immutable) {
            throw new \Exception('Cannot update an immutable invoice');
        }

        return parent::update($attributes, $options);
    }

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
     * Check if this invoice should include QR code
     *
     * @return bool True if QR should be included
     */
    public function shouldIncludeQR(): bool
    {
        // Proforma invoices never include QR
        if ($this->type === 'proforma') {
            return false;
        }

        // Only fiscal invoices include QR
        return $this->type === 'invoice' || $this->type === 'fiscal';
    }

    /**
     * Get the invoice type for PDF generation
     *
     * @return string Invoice type
     */
    public function getInvoiceType(): string
    {
        return $this->type ?? 'invoice';
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
}
