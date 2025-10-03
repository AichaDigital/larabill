<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserTaxInfo Model
 *
 * Represents fiscal information for a user.
 */
class UserTaxInfo extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'is_current',
        'tax_id',
        'company_name',
        'address',
        'city',
        'postal_code',
        'country',
        'state',
        'phone',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_current' => 'boolean',
    ];

    /**
     * Make this tax info the current one for the user.
     */
    public function makeCurrent(): void
    {
        // Set all other tax info for this user as not current
        static::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_current' => false]);

        // Set this one as current
        $this->update(['is_current' => true]);
    }

    /**
     * Scope to get only current tax info.
     */
    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    /**
     * Get the user that owns the tax info.
     */
    public function user(): BelongsTo
    {
        $userModel = config('larabill.models.user');
        return $this->belongsTo($userModel);
    }
}
