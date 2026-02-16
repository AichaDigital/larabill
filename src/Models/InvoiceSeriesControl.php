<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Concerns\HasUserRelation;
use AichaDigital\Larabill\Database\Factories\InvoiceSeriesControlFactory;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invoice Series Control Model
 *
 * Controls correlative numbering for invoice series.
 * Ensures fiscal compliance with atomic operations.
 *
 * @property int $id
 * @property string $prefix User customizable prefix
 * @property InvoiceSerieType $serie
 * @property int $fiscal_year
 * @property \Carbon\Carbon $fiscal_year_start
 * @property \Carbon\Carbon $fiscal_year_end
 * @property int $last_number Last issued number
 * @property int $start_number Starting number (default 1)
 * @property bool $reset_annually Reset on fiscal year change
 * @property string $number_format Mustache template
 * @property bool $is_active
 * @property string|null $description
 * @property array|null $validation_rules
 * @property \Carbon\Carbon|null $last_used_at
 * @property int|null $user_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class InvoiceSeriesControl extends Model
{
    use HasFactory, HasUserRelation;

    protected $table = 'invoice_series_control';

    protected $fillable = [
        'prefix',
        'serie',
        'fiscal_year',
        'fiscal_year_start',
        'fiscal_year_end',
        'last_number',
        'start_number',
        'reset_annually',
        'number_format',
        'is_active',
        'description',
        'validation_rules',
        'last_used_at',
        'user_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'serie'             => InvoiceSerieType::class,
            'fiscal_year'       => 'integer',
            'fiscal_year_start' => 'date',
            'fiscal_year_end'   => 'date',
            'last_number'       => 'integer',
            'start_number'      => 'integer',
            'reset_annually'    => 'boolean',
            'is_active'         => 'boolean',
            'validation_rules'  => 'array',
            'last_used_at'      => 'datetime',
        ];
    }

    /**
     * Get the user that owns this series control
     */
    public function user(): BelongsTo
    {
        $userModel = config('larabill.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel);
    }

    /**
     * Scope for active series only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for specific fiscal year
     */
    public function scopeFiscalYear($query, int $year)
    {
        return $query->where('fiscal_year', $year);
    }

    /**
     * Scope for specific serie type
     */
    public function scopeSerie($query, InvoiceSerieType $serie)
    {
        return $query->where('serie', $serie->value);
    }

    /**
     * Scope for specific prefix
     */
    public function scopePrefix($query, string $prefix)
    {
        return $query->where('prefix', $prefix);
    }

    /**
     * Get next available number
     * IMPORTANT: This method should NOT be used directly.
     * Use InvoiceNumberingService::generateNumber() with DB locks.
     */
    public function getNextNumber(): int
    {
        return $this->last_number + 1;
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): InvoiceSeriesControlFactory
    {
        return InvoiceSeriesControlFactory::new();
    }
}
