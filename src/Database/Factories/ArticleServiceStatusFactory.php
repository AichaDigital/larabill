<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\CancellationType;
use AichaDigital\Larabill\Enums\ServiceStatus;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'instance_identifier'       => $this->faker->domainName(),
            'instance_name'             => 'Service for '.$this->faker->domainWord(),
            'started_at'                => now(),
            'next_billing_date'         => now()->addMonth(),
            'expires_at'                => null,
            'status'                    => ServiceStatus::ACTIVE,
            'cancellation_type'         => null,
            'cancellation_requested_at' => null,
            'cancellation_effective_at' => null,
            'refund_unused'             => false,
            'billing_frequency'         => BillingFrequency::MONTHLY,
            'effective_price'           => 2900, // Default price in Base100 (€29.00)
            'current_override_id'       => null,
            'external_reference'        => null,
            'instance_data'             => [],
            'metadata'                  => [],
        ];
    }

    /**
     * Set billing frequency.
     */
    public function frequency(BillingFrequency $frequency): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_frequency' => $frequency,
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
            'status' => ServiceStatus::ACTIVE,
        ]);
    }

    /**
     * Indicate that the service is pending activation.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'            => ServiceStatus::PENDING,
            'next_billing_date' => null,
        ]);
    }

    /**
     * Indicate that the service is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'   => ServiceStatus::SUSPENDED,
            'metadata' => [
                'suspension' => [
                    'suspended_at' => now()->toIso8601String(),
                    'reason'       => 'Payment failed',
                ],
            ],
        ]);
    }

    /**
     * Indicate that the service is cancelled.
     */
    public function cancelled(?CancellationType $type = null): static
    {
        $type = $type ?? CancellationType::IMMEDIATE;

        return $this->state(fn (array $attributes) => [
            'status'                    => ServiceStatus::CANCELLED,
            'cancellation_type'         => $type,
            'cancellation_requested_at' => now()->subDays(5),
            'cancellation_effective_at' => now(),
            'refund_unused'             => $type->requiresRefund(),
            'next_billing_date'         => null,
        ]);
    }

    /**
     * Indicate that the service is expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'            => ServiceStatus::EXPIRED,
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
            'status'            => ServiceStatus::ACTIVE,
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
     * Set for a specific article with frequency.
     */
    public function forArticle(Article $article, ?BillingFrequency $frequency = null): static
    {
        $frequency = $frequency                        ?? BillingFrequency::MONTHLY;
        $price     = $article->getPriceFor($frequency) ?? 2900;

        return $this->state(fn (array $attributes) => [
            'article_id'        => $article->id,
            'billing_frequency' => $frequency,
            'effective_price'   => $price,
        ]);
    }

    /**
     * Set effective price.
     */
    public function price(int $price): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_price' => $price,
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
            'billing_frequency'   => BillingFrequency::YEARLY, // Domains are typically yearly
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
