<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IssuerTaxProfile Model
 *
 * Historical fiscal identity of the sole issuer (AichaDigital)
 * Allows tracking changes in legal name, tax ID, address, etc.
 *
 * @property int $id
 * @property string $legal_name
 * @property string|null $commercial_name
 * @property string $tax_id NIF/CIF
 * @property string $legal_entity_type_code FK to legal_entity_types
 * @property string $address
 * @property string|null $address_line_2
 * @property string $city
 * @property string|null $state
 * @property string $postal_code
 * @property string $country_code
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $website
 * @property string|null $vat_number
 * @property bool $is_roi_registered
 * @property bool $roi_enabled Alias for is_roi_registered
 * @property bool $is_oss_registered
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property bool $is_current
 * @property string|null $change_reason
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class IssuerTaxProfile extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'issuer_tax_profiles';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'legal_name',
        'commercial_name',
        'tax_id',
        'legal_entity_type_code',
        'address',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'phone',
        'email',
        'website',
        'vat_number',
        'is_roi_registered',
        'is_oss_registered',
        'valid_from',
        'valid_until',
        'is_current',
        'change_reason',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_roi_registered' => 'boolean',
            'is_oss_registered' => 'boolean',
            'valid_from'        => 'date',
            'valid_until'       => 'date',
            'is_current'        => 'boolean',
            'metadata'          => 'array',
        ];
    }

    /**
     * Get the legal entity type.
     */
    public function legalEntityType(): BelongsTo
    {
        return $this->belongsTo(LegalEntityType::class, 'legal_entity_type_code', 'code');
    }

    /**
     * Make this profile the current one (for issuer identity change).
     */
    public function makeCurrent(): void
    {
        // Mark all other profiles as not current
        static::where('id', '!=', $this->id)
            ->update(['is_current' => false, 'valid_until' => now()->subDay()]);

        // Mark this one as current
        $this->update(['is_current' => true, 'valid_until' => null]);

        // Update IssuerConfig to point to this profile
        IssuerConfig::current()->update([
            'current_tax_profile_id' => $this->id,
        ]);
    }

    /**
     * Get the display name (legal or commercial).
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->commercial_name ?? $this->legal_name;
    }

    /**
     * Get full address as single string.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address,
            $this->address_line_2,
            $this->postal_code.' '.$this->city,
            $this->state,
            $this->country_code,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Check if this profile is valid for a given date.
     */
    public function isValidForDate(\DateTimeInterface $date): bool
    {
        $carbonDate = \Carbon\Carbon::parse($date);

        $afterStart = $carbonDate->greaterThanOrEqualTo($this->valid_from);
        $beforeEnd  = $this->valid_until === null || $carbonDate->lessThanOrEqualTo($this->valid_until);

        return $afterStart && $beforeEnd;
    }

    /**
     * Scope current profile.
     */
    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    /**
     * Scope valid for date.
     */
    public function scopeValidForDate($query, \DateTimeInterface $date)
    {
        $carbonDate = \Carbon\Carbon::parse($date);

        return $query->where('valid_from', '<=', $carbonDate)
            ->where(function ($q) use ($carbonDate) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $carbonDate);
            });
    }
}
