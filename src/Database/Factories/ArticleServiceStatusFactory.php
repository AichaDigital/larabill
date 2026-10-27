<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Database\Factories\ArticlePriceFactory as CadenceBridge;
use AichaDigital\Larabill\Enums\AgreementModality;
use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class ArticleServiceStatusFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = ArticleServiceStatus::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $userModel = config('larabill.user_model', 'App\\Models\\User');

        return [
            'customer_id'               => $userModel::factory(),
            'article_id'                => Article::factory()->service(),
            'instance_identifier'       => $this->faker->unique()->domainName(),
            'instance_name'             => 'Service for '.$this->faker->domainWord(),
            'started_at'                => now(),
            'next_billing_date'         => now()->addMonth(),
            'expires_at'                => null,
            // Deterministic defaults on every guard-governing field (AID-508/
            // AID-558): the string state domain, the accounting axis, the
            // renewal attribute (expires_at is NULL here → auto) and the
            // modality (the default cadence below is cadence-bearing →
            // recurring; the oneTime()/nonBillable() states override it).
            'status'                    => AgreementStatus::ACTIVE,
            'account_status'            => 'up_to_date',
            'renewal'                   => 'auto',
            'modality'                  => AgreementModality::RECURRING,
            'close_scheduled_at'        => null,
            'cancellation_requested_at' => null,
            'cancellation_effective_at' => null,
            'refund_unused'             => false,
            // Deterministic default cadence: the legacy default was MONTHLY,
            // mapped through the §4.1 bridge (month/1). No randomElement —
            // the cadence participates in scheduling math.
            ...CadenceBridge::cadenceAttributes(BillingFrequency::MONTHLY),
            // Deterministic default (AID-508/AID-558): the guarantee snapshot
            // is only written at contracting; a fixture declares one explicitly.
            'guarantee_days'            => null,
            'effective_price'           => FixedDecimal::ofUnscaled(2900, 2), // Default €29.00
            'current_override_id'       => null,
            'external_reference'        => null,
            'instance_data'             => [],
            'metadata'                  => [],
        ];
    }

    /**
     * Set billing cadence from a legacy frequency. The `BillingFrequency`
     * parameter is kept until the shim slice (ADR-015 §5): the state emits
     * unit/quantity through the §4.1 bridge, so ONE_TIME becomes the NULL
     * cadence and WEEKLY/BIWEEKLY throw — no fixture can hold a cadence the
     * new domain cannot represent. The modality follows the cadence domain
     * (§4.1): NULL cadence → `one_time`, cadence-bearing → `recurring`.
     */
    public function frequency(BillingFrequency $frequency): static
    {
        $interval = BillingInterval::fromLegacyFrequency($frequency->value);

        return $this->state(fn (array $attributes) => [
            ...CadenceBridge::cadenceAttributes($frequency),
            'modality' => $interval === null ? AgreementModality::ONE_TIME : AgreementModality::RECURRING,
        ]);
    }

    /**
     * A one-time agreement (ADR-015 §3.3): NULL cadence + `one_time`
     * modality, and NO next_billing_date — it emits exactly once at
     * contracting and never again. The healthy shape the emission gate
     * expects; a fixture with a billing date on a one_time agreement is the
     * corrupt state the gate refuses loud.
     */
    public function oneTime(): static
    {
        return $this->state(fn (array $attributes) => [
            ...CadenceBridge::cadenceAttributes(BillingFrequency::ONE_TIME),
            'modality'          => AgreementModality::ONE_TIME,
            'next_billing_date' => null,
        ]);
    }

    /**
     * The import contract (ADR-015 §2.5 Free Account): `non_billable`
     * modality is NEVER derived from legacy data — the consumer/importer
     * sets it explicitly. It never emits, so it carries no next_billing_date
     * and no cadence.
     */
    public function nonBillable(): static
    {
        return $this->state(fn (array $attributes) => [
            ...CadenceBridge::cadenceAttributes(BillingFrequency::ONE_TIME),
            'modality'          => AgreementModality::NON_BILLABLE,
            'next_billing_date' => null,
        ]);
    }

    /**
     * Set monthly billing frequency.
     */
    public function monthly(): static
    {
        return $this->frequency(BillingFrequency::MONTHLY);
    }

    /**
     * Set quarterly billing frequency.
     */
    public function quarterly(): static
    {
        return $this->frequency(BillingFrequency::QUARTERLY);
    }

    /**
     * Set yearly billing frequency.
     */
    public function yearly(): static
    {
        return $this->frequency(BillingFrequency::YEARLY);
    }

    /**
     * Indicate that the service is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgreementStatus::ACTIVE,
        ]);
    }

    /**
     * Indicate that the service is pending activation.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'            => AgreementStatus::PENDING,
            'next_billing_date' => null,
        ]);
    }

    /**
     * Indicate that the service is suspended. Cause NULL — a legacy-shaped
     * fixture (imported suspended rows carry no cause, ADR-015 §2.2); the
     * narrative stays in the suspension metadata.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'   => AgreementStatus::SUSPENDED,
            'metadata' => [
                'suspension' => [
                    'suspended_at' => now()->toIso8601String(),
                    'reason'       => 'Payment failed',
                ],
            ],
        ]);
    }

    /**
     * Indicate that the agreement sits in the transient guarantee window
     * (ADR-015 §3.4, slice 2): the refund claim is open, the agreement is
     * neither billed nor closed until the consumer confirms (or rejects) the
     * refund.
     */
    public function guarantee(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgreementStatus::GUARANTEE,
        ]);
    }

    /**
     * Indicate that the agreement was cancelled under the legacy policy —
     * ALIGNED to the close/suspend semantics (ADR-015 Enmienda 1, third
     * replica site): the agreement is CLOSED with the seeded
     * `legacy_cancellation` cause, and `$refundUnused` rides the legacy
     * refund flag (`NOTICE_PERIOD` required refund under the retired
     * `CancellationType` shim, removed in 9.0).
     */
    public function cancelled(bool $refundUnused = false): static
    {
        return $this->state(fn (array $attributes) => [
            'status'                    => AgreementStatus::CLOSED,
            'closure_cause_id'          => AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'legacy_cancellation')->id,
            'cancellation_requested_at' => now()->subDays(5),
            'cancellation_effective_at' => now(),
            'refund_unused'             => $refundUnused,
            'next_billing_date'         => null,
            'metadata'                  => [
                'cancellation' => [
                    'requested_at' => now()->subDays(5)->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * Indicate that the service is expired — ALIGNED to the §4.2 rewire:
     * expiry CLOSES the agreement with the seeded `fulfilment` cause (the
     * retired EXPIRED state is gone).
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'            => AgreementStatus::CLOSED,
            'closure_cause_id'  => AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'fulfilment')->id,
            'expires_at'        => now()->subDays(1),
            'next_billing_date' => null,
        ]);
    }

    /**
     * Indicate that the service is due for billing.
     */
    public function dueForBilling(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'            => AgreementStatus::ACTIVE,
            'next_billing_date' => now()->subDay(),
        ]);
    }

    /**
     * Set with a price override.
     */
    public function withOverride(?ArticleOverride $override = null): static
    {
        return $this->afterCreating(function (ArticleServiceStatus $service) use ($override) {
            if (! $override) {
                $override = ArticleOverride::factory()
                    ->forCustomer($service->customer_id)
                    ->forArticle($service->article)
                    ->create();
            }

            $service->update([
                'effective_price'     => $override->custom_price,
                'current_override_id' => $override->id,
            ]);
        });
    }

    /**
     * Set for a specific customer.
     */
    public function forCustomer(int|string $customerId): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => $customerId,
        ]);
    }

    /**
     * Set for a specific article with cadence. The `BillingFrequency`
     * parameter is kept (deprecated since 9.0.0, removal in 10.0) and bridges
     * through the §4.1 mapping; the modality follows the cadence domain, as
     * in `frequency()`.
     */
    public function forArticle(Article $article, ?BillingFrequency $frequency = null): static
    {
        $frequency = $frequency ?? BillingFrequency::MONTHLY;
        $interval  = BillingInterval::fromLegacyFrequency($frequency->value);
        $price     = $article->getPriceForInterval($interval) ?? 2900;

        return $this->state(fn (array $attributes) => [
            'article_id'        => $article->id,
            ...CadenceBridge::cadenceAttributes($frequency),
            'modality'          => $interval === null ? AgreementModality::ONE_TIME : AgreementModality::RECURRING,
            'effective_price'   => FixedDecimal::ofUnscaled((int) $price, 2),
        ]);
    }

    /**
     * Set effective price.
     */
    public function price(int $price): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_price' => FixedDecimal::ofUnscaled($price, 2),
        ]);
    }

    /**
     * Set with hosting instance data.
     */
    public function hosting(string $domain): static
    {
        return $this->state(fn (array $attributes) => [
            'instance_identifier' => $domain,
            'instance_name'       => "Hosting for {$domain}",
            'instance_data'       => [
                'hosting' => [
                    'domain'          => $domain,
                    'cpanel_username' => str_replace(['.', '-'], '', $domain),
                    'disk_space_gb'   => 10,
                    'bandwidth_gb'    => 100,
                    'ip'              => $this->faker->ipv4(),
                ],
            ],
        ]);
    }

    /**
     * Set with domain instance data.
     */
    public function domain(string $domainName): static
    {
        return $this->state(fn (array $attributes) => [
            'instance_identifier' => $domainName,
            'instance_name'       => "Domain {$domainName}",
            // Domains are typically yearly (legacy YEARLY → year/1).
            ...CadenceBridge::cadenceAttributes(BillingFrequency::YEARLY),
            'instance_data'       => [
                'domain' => [
                    'name'           => $domainName,
                    'tld'            => substr($domainName, strrpos($domainName, '.') + 1),
                    'registrar'      => $this->faker->company(),
                    'registrar_lock' => true,
                    'auto_renew'     => true,
                ],
            ],
        ]);
    }

    /**
     * Set external reference.
     */
    public function withExternalReference(string $reference): static
    {
        return $this->state(fn (array $attributes) => [
            'external_reference' => $reference,
        ]);
    }
}
