<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * TaxRate Factory
 *
 * Creates TaxRate instances for testing.
 */
class TaxRateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = TaxRate::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'country_code' => 'ES',
            'country_name' => 'Spain',
            'tax_name' => 'IVA General',
            'tax_type' => 'standard',
            'rate' => TaxRate::percentageToBase100(21.0), // 2100
            'is_active' => true,
            'applies_to' => 'general_goods_services',
            'special_conditions' => null,
        ];
    }

    /**
     * Spanish tax rates.
     */
    public function spanish(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'ES',
            'country_name' => 'Spain',
        ]);
    }

    /**
     * Spanish general rate (21%).
     */
    public function spanishGeneral(): static
    {
        return $this->spanish()->state(fn (array $attributes) => [
            'tax_name' => 'IVA General',
            'tax_type' => 'standard',
            'rate' => TaxRate::percentageToBase100(21.0),
            'applies_to' => 'general_goods_services',
        ]);
    }

    /**
     * Spanish reduced rate (10%).
     */
    public function spanishReduced(): static
    {
        return $this->spanish()->state(fn (array $attributes) => [
            'tax_name' => 'IVA Reducido',
            'tax_type' => 'reduced',
            'rate' => TaxRate::percentageToBase100(10.0),
            'applies_to' => 'reduced_goods_services',
        ]);
    }

    /**
     * Spanish super reduced rate (4%).
     */
    public function spanishSuperReduced(): static
    {
        return $this->spanish()->state(fn (array $attributes) => [
            'tax_name' => 'IVA Superreducido',
            'tax_type' => 'super_reduced',
            'rate' => TaxRate::percentageToBase100(4.0),
            'applies_to' => 'super_reduced_goods_services',
        ]);
    }

    /**
     * German tax rate (19%).
     */
    public function german(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'DE',
            'country_name' => 'Germany',
            'tax_name' => 'MwSt',
            'tax_type' => 'standard',
            'rate' => TaxRate::percentageToBase100(19.0),
            'applies_to' => 'general_goods_services',
        ]);
    }

    /**
     * French tax rate (20%).
     */
    public function french(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'FR',
            'country_name' => 'France',
            'tax_name' => 'TVA',
            'tax_type' => 'standard',
            'rate' => TaxRate::percentageToBase100(20.0),
            'applies_to' => 'general_goods_services',
        ]);
    }

    /**
     * Canary Islands tax rate (7%).
     */
    public function canaryIslands(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'IC',
            'country_name' => 'Canary Islands',
            'tax_name' => 'IGIC',
            'tax_type' => 'standard',
            'rate' => TaxRate::percentageToBase100(7.0),
            'applies_to' => 'general_goods_services',
            'special_conditions' => ['exempt_from_spanish_vat' => true],
        ]);
    }

    /**
     * Ceuta tax rate (0%).
     */
    public function ceuta(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'CE',
            'country_name' => 'Ceuta',
            'tax_name' => 'IPSI',
            'tax_type' => 'special',
            'rate' => TaxRate::percentageToBase100(0.0),
            'applies_to' => 'special_territory',
            'special_conditions' => ['exempt_from_spanish_vat' => true],
        ]);
    }

    /**
     * Melilla tax rate (0%).
     */
    public function melilla(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'ML',
            'country_name' => 'Melilla',
            'tax_name' => 'IPSI',
            'tax_type' => 'special',
            'rate' => TaxRate::percentageToBase100(0.0),
            'applies_to' => 'special_territory',
            'special_conditions' => ['exempt_from_spanish_vat' => true],
        ]);
    }
}
