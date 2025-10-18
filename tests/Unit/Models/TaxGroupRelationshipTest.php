<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Unit\Models;

use AichaDigital\Larabill\Models\{TaxGroup, TaxRate};
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TaxGroupRelationshipTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_tax_group_can_have_many_tax_rates(): void
    {
        $taxGroup = TaxGroup::factory()->create();
        $taxRate1 = TaxRate::factory()->create(['rate' => 1000]);
        $taxRate2 = TaxRate::factory()->create(['rate' => 2100]);

        $taxGroup->taxRates()->attach([$taxRate1->id, $taxRate2->id]);

        $this->assertCount(2, $taxGroup->taxRates);
        $this->assertEquals(1000, $taxGroup->taxRates->first()->rate);
    }

    /** @test */
    public function a_tax_rate_can_belong_to_many_tax_groups(): void
    {
        $taxRate   = TaxRate::factory()->create();
        $taxGroup1 = TaxGroup::factory()->create(['name' => 'Group 1']);
        $taxGroup2 = TaxGroup::factory()->create(['name' => 'Group 2']);

        $taxRate->taxGroups()->attach([$taxGroup1->id, $taxGroup2->id]);

        $this->assertCount(2, $taxRate->taxGroups);
        $this->assertEquals('Group 1', $taxRate->taxGroups->first()->name);
    }
}
