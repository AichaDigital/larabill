<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Database\Factories\TaxGroupFactory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * TaxGroup Model - Configuration Layer (Mutable)
 *
 * Represents a group of tax rates that are applied together to an invoice item.
 * This is a "living" table that can be updated when tax rules change.
 *
 * Examples:
 * - "Servicios Digitales UE": Groups multiple EU digital service taxes
 * - "Venta Estándar en Boston": Groups MA state tax + Boston city surcharge
 * - "IVA General España": Groups standard Spanish VAT
 *
 * The application consuming Larabill should associate their products with a tax_group_id.
 * When creating an InvoiceItem, the tax_group_id is passed and the TaxCalculationService
 * calculates all taxes in real-time, storing an immutable snapshot in the invoice_item.
 *
 * @property int $id
 * @property string $name Tax group name (e.g., "Servicios Digitales UE")
 * @property string|null $description Optional description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TaxGroup extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'description',
    ];

    // ========================================
    // RELATIONSHIPS
    // ========================================

    /**
     * The tax rates that belong to this tax group.
     * Many-to-many relationship through tax_group_tax_rate pivot.
     *
     * @return BelongsToMany<TaxRate>
     */
    public function taxRates(): BelongsToMany
    {
        return $this->belongsToMany(TaxRate::class, 'tax_group_tax_rate')
            ->withPivot('priority')
            ->orderBy('priority');
    }

    // ========================================
    // FACTORY
    // ========================================

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TaxGroupFactory
    {
        return TaxGroupFactory::new();
    }
}
