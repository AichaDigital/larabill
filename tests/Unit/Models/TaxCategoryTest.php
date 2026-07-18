<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\TaxCategory;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->category = TaxCategory::factory()->create([
        'code'         => 'vat_standard',
        'name'         => 'Standard VAT',
        'tax_type'     => 'vat',
        'description'  => 'Standard VAT rate',
        'default_rate' => cents(2100), // 21.00% in base100
        'country_code' => 'ES',
        'region_code'  => null,
        'is_active'    => true,
        'sort_order'   => 1,
    ]);
});

it('can create a tax category', function () {
    expect($this->category->code)->toBe('vat_standard')
        ->and($this->category->name)->toBe('Standard VAT')
        ->and($this->category->tax_type)->toBe('vat')
        ->and($this->category->default_rate->unscaledValue())->toBe(2100) // 21.00% in base100
        ->and($this->category->country_code)->toBe('ES')
        ->and($this->category->is_active)->toBeTrue()
        ->and($this->category->sort_order)->toBe(1);
});

it('can cast default_rate using Base100Int', function () {
    $category = TaxCategory::factory()->create([
        'default_rate' => cents(1950), // 19.50% in base100
    ]);

    expect($category->default_rate->unscaledValue())->toBe(1950);
});

it('can scope active categories only', function () {
    TaxCategory::factory()->create(['is_active' => false]);
    TaxCategory::factory()->create(['is_active' => true]);

    $activeCategories = TaxCategory::active()->get();

    expect($activeCategories)->toHaveCount(2); // Including beforeEach category
});

it('can scope by country', function () {
    TaxCategory::factory()->create(['country_code' => 'FR']);
    TaxCategory::factory()->create(['country_code' => 'DE']);

    $spainCategories = TaxCategory::country('ES')->get();

    expect($spainCategories)->toHaveCount(1)
        ->and($spainCategories->first()->country_code)->toBe('ES');
});

it('can scope by country case insensitive', function () {
    $categories = TaxCategory::country('es')->get();

    expect($categories)->toHaveCount(1)
        ->and($categories->first()->country_code)->toBe('ES');
});

it('can scope by region', function () {
    $category = TaxCategory::factory()->create([
        'country_code' => 'US',
        'region_code'  => 'CA',
    ]);

    $californiaCategories = TaxCategory::region('CA')->get();

    expect($californiaCategories)->toHaveCount(1)
        ->and($californiaCategories->first()->region_code)->toBe('CA');
});

it('can scope by region case insensitive', function () {
    TaxCategory::factory()->create([
        'country_code' => 'US',
        'region_code'  => 'TX',
    ]);

    $categories = TaxCategory::region('tx')->get();

    expect($categories)->toHaveCount(1)
        ->and($categories->first()->region_code)->toBe('TX');
});

it('can scope by tax type', function () {
    TaxCategory::factory()->create(['tax_type' => 'sales_tax']);
    TaxCategory::factory()->create(['tax_type' => 'gst']);

    $vatCategories = TaxCategory::taxType('vat')->get();

    expect($vatCategories)->toHaveCount(1)
        ->and($vatCategories->first()->tax_type)->toBe('vat');
});

it('can scope ordered by sort_order and name', function () {
    TaxCategory::factory()->create(['name' => 'Zulu', 'sort_order' => 2]);
    TaxCategory::factory()->create(['name' => 'Alpha', 'sort_order' => 1]);
    TaxCategory::factory()->create(['name' => 'Beta', 'sort_order' => 1]);

    $categories = TaxCategory::ordered()->get();

    expect($categories->first()->name)->toBe('Alpha')
        ->and($categories->last()->name)->toBe('Zulu');
});

it('can have invoice items relationship', function () {
    $user    = TestUser::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $user->id]);

    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'item_type'  => ItemType::GOOD,
    ]);

    expect($this->category->invoiceItems())->toBeInstanceOf(HasMany::class);
});

it('can store metadata as JSON', function () {
    $metadata = [
        'category_group' => 'food',
        'tax_exemptions' => ['export'],
    ];

    $category = TaxCategory::factory()->create([
        'metadata' => $metadata,
    ]);

    expect($category->metadata)->toBe($metadata)
        ->and($category->metadata['category_group'])->toBe('food');
});

it('can filter active categories by country', function () {
    TaxCategory::factory()->create([
        'country_code' => 'FR',
        'is_active'    => true,
    ]);
    TaxCategory::factory()->create([
        'country_code' => 'ES',
        'is_active'    => false,
    ]);

    $categories = TaxCategory::active()->country('ES')->get();

    expect($categories)->toHaveCount(1);
});

it('can filter by tax type and country', function () {
    TaxCategory::factory()->create([
        'country_code' => 'US',
        'tax_type'     => 'sales_tax',
    ]);

    $categories = TaxCategory::taxType('vat')->country('ES')->get();

    expect($categories)->toHaveCount(1)
        ->and($categories->first()->tax_type)->toBe('vat')
        ->and($categories->first()->country_code)->toBe('ES');
});

it('can create category with region', function () {
    $category = TaxCategory::factory()->create([
        'country_code' => 'US',
        'region_code'  => 'NY',
        'tax_type'     => 'sales_tax',
        'default_rate' => cents(888), // 8.88% in base100
    ]);

    expect($category->country_code)->toBe('US')
        ->and($category->region_code)->toBe('NY')
        ->and($category->default_rate->unscaledValue())->toBe(888); // 8.88% in base100
});

it('can have null region_code', function () {
    $category = TaxCategory::factory()->create([
        'region_code' => null,
    ]);

    expect($category->region_code)->toBeNull();
});

it('enforces unique code constraint', function () {
    TaxCategory::factory()->create([
        'code' => 'unique_code',
    ]);

    expect(function () {
        TaxCategory::factory()->create([
            'code' => 'unique_code',
        ]);
    })->toThrow(QueryException::class);
});

it('can get all tax types', function () {
    TaxCategory::factory()->create(['tax_type' => 'sales_tax']);
    TaxCategory::factory()->create(['tax_type' => 'gst']);
    TaxCategory::factory()->create(['tax_type' => 'vat']);

    $taxTypes = TaxCategory::distinct('tax_type')->pluck('tax_type')->toArray();

    expect($taxTypes)->toContain('vat')
        ->and($taxTypes)->toContain('sales_tax')
        ->and($taxTypes)->toContain('gst');
});

it('can chain multiple scopes', function () {
    TaxCategory::factory()->create([
        'country_code' => 'ES',
        'tax_type'     => 'vat',
        'is_active'    => false,
        'sort_order'   => 2,
    ]);

    $categories = TaxCategory::active()
        ->country('ES')
        ->taxType('vat')
        ->ordered()
        ->get();

    expect($categories)->toHaveCount(1)
        ->and($categories->first()->is_active)->toBeTrue();
});
