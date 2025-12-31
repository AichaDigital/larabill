<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Services\TaxCalculation\VatCalculationStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('VatCalculationStrategy', function () {
    it('can be instantiated', function () {
        $strategy = new VatCalculationStrategy;

        expect($strategy)->toBeInstanceOf(VatCalculationStrategy::class);
    });

    it('calculates VAT with single tax rate', function () {
        $taxGroup = TaxGroup::factory()->create(['name' => 'Standard VAT']);
        $taxRate  = TaxRate::factory()->create([
            'name' => 'IVA 21%',
            'rate' => 2100, // 21% in base 100
        ]);
        $taxGroup->taxRates()->attach($taxRate->id, ['priority' => 1]);

        $strategy = new VatCalculationStrategy;
        $result   = $strategy->calculate(10000, $taxGroup);

        expect($result)->toBeArray();
        expect($result)->toHaveKeys(['total_tax_amount', 'taxes_applied']);
        expect($result['total_tax_amount'])->toBe(2100.0);
        expect($result['taxes_applied'])->toHaveCount(1);
    });

    it('calculates VAT with multiple tax rates', function () {
        $taxGroup = TaxGroup::factory()->create(['name' => 'Composite Tax']);
        $taxRate1 = TaxRate::factory()->create([
            'name' => 'IVA 21%',
            'rate' => 2100,
        ]);
        $taxRate2 = TaxRate::factory()->create([
            'name' => 'Recargo 5.2%',
            'rate' => 520,
        ]);

        $taxGroup->taxRates()->attach($taxRate1->id, ['priority' => 1]);
        $taxGroup->taxRates()->attach($taxRate2->id, ['priority' => 2]);

        $strategy = new VatCalculationStrategy;
        $result   = $strategy->calculate(10000, $taxGroup);

        expect($result['total_tax_amount'])->toBe(2620.0);
        expect($result['taxes_applied'])->toHaveCount(2);
    });

    it('calculates zero VAT for zero rate', function () {
        $taxGroup = TaxGroup::factory()->create(['name' => 'Zero Rate']);
        $taxRate  = TaxRate::factory()->create([
            'name' => 'Zero VAT',
            'rate' => 0,
        ]);
        $taxGroup->taxRates()->attach($taxRate->id, ['priority' => 1]);

        $strategy = new VatCalculationStrategy;
        $result   = $strategy->calculate(10000, $taxGroup);

        expect($result['total_tax_amount'])->toBe(0.0);
    });

    it('handles empty tax group', function () {
        $taxGroup = TaxGroup::factory()->create(['name' => 'Empty Group']);

        $strategy = new VatCalculationStrategy;
        $result   = $strategy->calculate(10000, $taxGroup);

        expect($result['total_tax_amount'])->toBe(0);
        expect($result['taxes_applied'])->toBeEmpty();
    });

    it('includes tax rate details in response', function () {
        $taxGroup = TaxGroup::factory()->create(['name' => 'Test Group']);
        $taxRate  = TaxRate::factory()->create([
            'name' => 'IVA 10%',
            'rate' => 1000,
        ]);
        $taxGroup->taxRates()->attach($taxRate->id, ['priority' => 1]);

        $strategy = new VatCalculationStrategy;
        $result   = $strategy->calculate(5000, $taxGroup);

        $appliedTax = $result['taxes_applied'][0];
        expect($appliedTax)->toHaveKeys(['source_rate_id', 'name', 'rate', 'amount']);
        expect($appliedTax['name'])->toBe('IVA 10%');
        expect($appliedTax['rate'])->toBe(1000);
        expect($appliedTax['amount'])->toBe(500.0);
    });

    it('ignores context parameter', function () {
        $taxGroup = TaxGroup::factory()->create(['name' => 'Test Group']);
        $taxRate  = TaxRate::factory()->create([
            'name' => 'IVA 21%',
            'rate' => 2100,
        ]);
        $taxGroup->taxRates()->attach($taxRate->id, ['priority' => 1]);

        $strategy             = new VatCalculationStrategy;
        $resultWithContext    = $strategy->calculate(10000, $taxGroup, ['some' => 'context']);
        $resultWithoutContext = $strategy->calculate(10000, $taxGroup);

        expect($resultWithContext['total_tax_amount'])->toBe($resultWithoutContext['total_tax_amount']);
    });
});
