<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CustomerTaxProfile Model
 *
 * Historical fiscal identity of a customer
 * Allows tracking changes in tax ID, address, legal entity type, etc.
 *
 * @property int $id
 * @property int $customer_id FK to customers
 * @property string $legal_name
 * @property string|null $commercial_name
 * @property string $tax_code NIF/CIF/NIE
 * @property string $tax_id Alias for tax_code
 * @property string $legal_entity_type_code FK to legal_entity_types
 * @property string $address
 * @property string|null $address_line_2
 * @property string $city
 * @property string|null $state
 * @property string $postal_code
 * @property string $country_code
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $vat_number
 * @property bool $vat_number_verified
 * @property \Illuminate\Support\Carbon|null $vat_verified_at
 * @property array|null $vat_verification_data
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property bool $is_current
 * @property string|null $change_reason
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CustomerTaxProfile extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'customer_tax_profiles';

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): \AichaDigital\Larabill\Database\Factories\CustomerTaxProfileFactory
    {
        return \AichaDigital\Larabill\Database\Factories\CustomerTaxProfileFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'customer_id',
        'legal_name',
        'commercial_name',
        'tax_code',
        'legal_entity_type_code',
        'address',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'phone',
        'email',
        'vat_number',
        'vat_number_verified',
        'vat_verified_at',
        'vat_verification_data',
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
            'vat_number_verified'   => 'boolean',
            'vat_verified_at'       => 'datetime',
            'vat_verification_data' => 'array',
            'valid_from'            => 'date',
            'valid_until'           => 'date',
            'is_current'            => 'boolean',
            'metadata'              => 'array',
        ];
    }

    /**
     * Accessor for tax_id (alias for tax_code).
     */
    public function getTaxIdAttribute(): ?string
    {
        return $this->tax_code;
    }

    /**
     * Get the customer that owns this profile.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the legal entity type.
     */
    public function legalEntityType(): BelongsTo
    {
        return $this->belongsTo(LegalEntityType::class, 'legal_entity_type_code', 'code');
    }

    /**
     * Make this profile the current one for the customer.
     */
    public function makeCurrent(): void
    {
        // Mark all other profiles for this customer as not current
        static::where('customer_id', $this->customer_id)
            ->where('id', '!=', $this->id)
            ->update(['is_current' => false, 'valid_until' => now()->subDay()]);

        // Mark this one as current
        $this->update(['is_current' => true, 'valid_until' => null]);

        // Update customer's current_tax_profile_id
        $this->customer()->update([
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
     * Check if VAT is EU intra-community.
     */
    public function isEuVat(): bool
    {
        if (! $this->vat_number) {
            return false;
        }

        $euCountries = config('larabill.eu_countries', []);

        return in_array($this->country_code, $euCountries);
    }

    /**
     * Mark VAT as verified.
     */
    public function markVatVerified(array $verificationData = []): void
    {
        $this->vat_number_verified   = true;
        $this->vat_verified_at       = now();
        $this->vat_verification_data = $verificationData;
        $this->save();
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
     * Scope current profiles.
     */
    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    /**
     * Scope by customer.
     */
    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
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

    /**
     * Scope by country.
     */
    public function scopeCountry($query, string $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Scope EU countries only.
     */
    public function scopeEu($query)
    {
        $euCountries = config('larabill.eu_countries', []);

        return $query->whereIn('country_code', $euCountries);
    }
}
