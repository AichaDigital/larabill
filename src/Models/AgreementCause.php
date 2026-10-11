<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Agreement Cause Model — the ADR-015 §2.2 closure/suspension cause
 * vocabulary.
 *
 * TABLE-BACKED, not an enum: consumers grow new causes by row with no code
 * change. Every cause belongs to exactly one scope (`closed` or `suspended`)
 * and retires via `is_active = false` — never deletion, because closure
 * history may keep pointing at it.
 *
 * APPLICATION-ENFORCED SCOPE INVARIANT (ADR-015 §2.2). The
 * `closure_cause_id` FK proves EXISTENCE, not scope, and Eloquent bulk
 * `query()->update()` bypasses model events — so nothing at the database
 * layer prevents assigning a suspension cause to a closure or vice versa.
 * The invariant is guarded here: `findActive()` is the single resolution
 * gate every assignment path must go through (the slice-B close/suspend API
 * resolves its causes through it). The limit is declared and pinned by
 * `AgreementCauseTest` — a raw bulk write CAN persist a wrong-scope cause;
 * the database deliberately stays permissive because a cross-table CHECK is
 * not expressible.
 *
 * RESERVED WORD NOTE: the `key` column is a MySQL reserved word. All access
 * goes through the query builder / Eloquent, which quote every identifier —
 * raw concatenated SQL against this table must quote it manually.
 *
 * @property int $id
 * @property string $scope 'closed' | 'suspended' (ADR-015 §2.2)
 * @property string $key Stable cause key (MySQL reserved word — quoted access only)
 * @property string $label Human-readable display label
 * @property bool $is_active Inactive causes stay resolvable in history, rejected for new assignments
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class AgreementCause extends Model
{
    public const SCOPE_CLOSED = 'closed';

    public const SCOPE_SUSPENDED = 'suspended';

    /**
     * The table associated with the model.
     */
    protected $table = 'agreement_causes';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'scope',
        'key',
        'label',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Resolve an ACTIVE cause by scope + key — the single gate for cause
     * assignment.
     *
     * Fails loud and typed on all three failure modes; there is no fallback
     * and nothing is invented (same doctrine as AID-589's fiscal series: a
     * business value the caller must own is never guessed):
     *
     * - unknown `key` → `InvalidArgumentException`;
     * - `key` exists under a DIFFERENT scope → `InvalidArgumentException`
     *   naming both scopes (a suspension cause cannot close an agreement,
     *   a closure cause cannot suspend one);
     * - cause retired (`is_active = false`) → `InvalidArgumentException`.
     *
     * History reads that must resolve retired causes read the model directly
     * (`AgreementCause::query()`), never this helper.
     *
     * @param  string  $scope  One of this model's `SCOPE_*` constants.
     * @param  string  $key  The stable cause key.
     *
     * @throws InvalidArgumentException on unknown key, wrong scope or inactive cause.
     */
    public static function findActive(string $scope, string $key): self
    {
        /** @var AgreementCause|null $cause */
        $cause = static::query()->where('key', $key)->first();

        if ($cause === null) {
            throw new InvalidArgumentException(sprintf(
                "Unknown agreement cause key '%s'. The cause vocabulary is table-backed "
                .'(agreement_causes, ADR-015 §2.2) — resolve the key against the seeded '
                .'vocabulary or grow the cause by row; nothing is invented.',
                $key,
            ));
        }

        if ($cause->scope !== $scope) {
            throw new InvalidArgumentException(sprintf(
                "Agreement cause '%s' has scope '%s', not '%s' (ADR-015 §2.2): a suspension "
                .'cause cannot close an agreement and a closure cause cannot suspend one. '
                .'The scope invariant is application-enforced — resolve a cause of the '
                .'correct scope.',
                $key,
                $cause->scope,
                $scope,
            ));
        }

        if (! $cause->is_active) {
            throw new InvalidArgumentException(sprintf(
                "Agreement cause '%s' (%s) is inactive and cannot be assigned to new "
                .'agreements. Retired causes stay resolvable in history but are rejected '
                .'for new assignments (ADR-015 §2.2).',
                $key,
                $scope,
            ));
        }

        return $cause;
    }

    /**
     * Scope the query to closure causes.
     *
     * @param  Builder<AgreementCause>  $query
     */
    public function scopeClosed(Builder $query): void
    {
        $query->where('scope', self::SCOPE_CLOSED);
    }

    /**
     * Scope the query to suspension causes.
     *
     * @param  Builder<AgreementCause>  $query
     */
    public function scopeSuspended(Builder $query): void
    {
        $query->where('scope', self::SCOPE_SUSPENDED);
    }
}
