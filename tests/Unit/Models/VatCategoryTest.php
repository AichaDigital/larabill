<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\VatCategory;

it('can create a VAT category', function () {
    $category = VatCategory::create([
        'name'                => 'Standard Goods',
        'description'         => 'Standard VAT rate for general goods',
        'country_code'        => 'ES',
        'vat_rate'            => pct(21.00),
        'category_type'       => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'           => true,
        'applies_to_products' => true,
        'applies_to_services' => true,
    ]);

    expect($category)->toBeInstanceOf(VatCategory::class);
    expect($category->name)->toBe('Standard Goods');
    expect($category->country_code)->toBe('ES');
    expect($category->vat_rate->unscaledValue())->toBe(2100); // 21% in base 100
    expect($category->is_active)->toBeTrue();
});

it('can find VAT category by country and name', function () {
    VatCategory::create([
        'name'          => 'Standard Goods',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    $found = VatCategory::findByCountryAndName('ES', 'Standard Goods');

    expect($found)->not->toBeNull();
    expect($found->country_code)->toBe('ES');
    expect($found->name)->toBe('Standard Goods');
});

it('can find VAT categories by country', function () {
    VatCategory::create([
        'name'          => 'Standard Goods',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    VatCategory::create([
        'name'          => 'Reduced Goods',
        'country_code'  => 'ES',
        'vat_rate'      => pct(10.00),
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
    ]);

    VatCategory::create([
        'name'          => 'Standard Goods',
        'country_code'  => 'FR',
        'vat_rate'      => pct(20.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    $esCategories = VatCategory::findByCountry('ES')->get();
    $frCategories = VatCategory::findByCountry('FR')->get();

    expect($esCategories)->toHaveCount(2);
    expect($frCategories)->toHaveCount(1);
});

it('can find VAT categories by type', function () {
    VatCategory::create([
        'name'          => 'Standard Goods',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    VatCategory::create([
        'name'          => 'Reduced Goods',
        'country_code'  => 'ES',
        'vat_rate'      => pct(10.00),
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
    ]);

    VatCategory::create([
        'name'          => 'Exempt Goods',
        'country_code'  => 'ES',
        'vat_rate'      => pct(0.00),
        'category_type' => VatCategory::CATEGORY_TYPE_EXEMPT,
    ]);

    $standardCategories = VatCategory::findByType(VatCategory::CATEGORY_TYPE_STANDARD)->get();
    $reducedCategories  = VatCategory::findByType(VatCategory::CATEGORY_TYPE_REDUCED)->get();
    $exemptCategories   = VatCategory::findByType(VatCategory::CATEGORY_TYPE_EXEMPT)->get();

    expect($standardCategories)->toHaveCount(1);
    expect($reducedCategories)->toHaveCount(1);
    expect($exemptCategories)->toHaveCount(1);
});

it('can find active VAT categories', function () {
    VatCategory::create([
        'name'          => 'Active Category',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => true,
    ]);

    VatCategory::create([
        'name'          => 'Inactive Category',
        'country_code'  => 'ES',
        'vat_rate'      => pct(10.00),
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
        'is_active'     => false,
    ]);

    $activeCategories   = VatCategory::active()->get();
    $inactiveCategories = VatCategory::inactive()->get();

    expect($activeCategories)->toHaveCount(1);
    expect($inactiveCategories)->toHaveCount(1);
    expect($activeCategories->first()->name)->toBe('Active Category');
});

it('can find VAT categories for products and services', function () {
    VatCategory::create([
        'name'                => 'Products Only',
        'country_code'        => 'ES',
        'vat_rate'            => pct(21.00),
        'category_type'       => VatCategory::CATEGORY_TYPE_STANDARD,
        'applies_to_products' => true,
        'applies_to_services' => false,
    ]);

    VatCategory::create([
        'name'                => 'Services Only',
        'country_code'        => 'ES',
        'vat_rate'            => pct(21.00),
        'category_type'       => VatCategory::CATEGORY_TYPE_STANDARD,
        'applies_to_products' => false,
        'applies_to_services' => true,
    ]);

    VatCategory::create([
        'name'                => 'Both Products and Services',
        'country_code'        => 'ES',
        'vat_rate'            => pct(21.00),
        'category_type'       => VatCategory::CATEGORY_TYPE_STANDARD,
        'applies_to_products' => true,
        'applies_to_services' => true,
    ]);

    $productCategories = VatCategory::forProducts()->get();
    $serviceCategories = VatCategory::forServices()->get();
    $bothCategories    = VatCategory::forBoth()->get();

    expect($productCategories)->toHaveCount(2);
    expect($serviceCategories)->toHaveCount(2);
    expect($bothCategories)->toHaveCount(1);
});

it('can find VAT category by rate', function () {
    VatCategory::create([
        'name'          => 'Standard 21%',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    VatCategory::create([
        'name'          => 'Reduced 10%',
        'country_code'  => 'ES',
        'vat_rate'      => pct(10.00),
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
    ]);

    $standard21  = VatCategory::findByRate('ES', 21.00);
    $reduced10   = VatCategory::findByRate('ES', 10.00);
    $nonexistent = VatCategory::findByRate('ES', 15.00);

    expect($standard21)->not->toBeNull();
    expect($standard21->name)->toBe('Standard 21%');
    expect($reduced10)->not->toBeNull();
    expect($reduced10->name)->toBe('Reduced 10%');
    expect($nonexistent)->toBeNull();
});

it('can use scopes correctly', function () {
    // Clean up any existing data
    VatCategory::query()->delete();

    VatCategory::create([
        'name'                => 'Standard ES',
        'country_code'        => 'ES',
        'vat_rate'            => pct(21.00),
        'category_type'       => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'           => true,
        'applies_to_products' => true,
    ]);

    VatCategory::create([
        'name'                => 'Reduced FR',
        'country_code'        => 'FR',
        'vat_rate'            => pct(5.50),
        'category_type'       => VatCategory::CATEGORY_TYPE_REDUCED,
        'is_active'           => false,
        'applies_to_products' => false,
        'applies_to_services' => false,
    ]);

    // Test by country scope
    $esCategories = VatCategory::byCountry('ES')->get();
    expect($esCategories)->toHaveCount(1);

    // Test by type scope
    $standardCategories = VatCategory::byType(VatCategory::CATEGORY_TYPE_STANDARD)->get();
    expect($standardCategories)->toHaveCount(1);

    // Test active scope
    $activeCategories = VatCategory::active()->get();
    expect($activeCategories)->toHaveCount(1);

    // Test for products scope
    $productCategories = VatCategory::forProducts()->get();
    expect($productCategories)->toHaveCount(1);

    // Test for services scope
    $serviceCategories = VatCategory::forServices()->get();
    expect($serviceCategories)->toHaveCount(1);
});

it('can get VAT rate for category', function () {
    $category = VatCategory::create([
        'name'          => 'Standard Goods',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    expect($category->getRate()->unscaledValue())->toBe(2100); // 21% in base 100
});

it('can check if category applies to products', function () {
    $productCategory = VatCategory::create([
        'name'                => 'Product Category',
        'country_code'        => 'ES',
        'vat_rate'            => pct(21.00),
        'category_type'       => VatCategory::CATEGORY_TYPE_STANDARD,
        'applies_to_products' => true,
        'applies_to_services' => false,
    ]);

    $serviceCategory = VatCategory::create([
        'name'                => 'Service Category',
        'country_code'        => 'ES',
        'vat_rate'            => pct(21.00),
        'category_type'       => VatCategory::CATEGORY_TYPE_STANDARD,
        'applies_to_products' => false,
        'applies_to_services' => true,
    ]);

    expect($productCategory->appliesToProducts())->toBeTrue();
    expect($productCategory->appliesToServices())->toBeFalse();
    expect($serviceCategory->appliesToProducts())->toBeFalse();
    expect($serviceCategory->appliesToServices())->toBeTrue();
});

it('can check if category is active', function () {
    $activeCategory = VatCategory::create([
        'name'          => 'Active Category',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => true,
    ]);

    $inactiveCategory = VatCategory::create([
        'name'          => 'Inactive Category',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => false,
    ]);

    expect($activeCategory->isActive())->toBeTrue();
    expect($inactiveCategory->isActive())->toBeFalse();
});

it('can activate and deactivate category', function () {
    $category = VatCategory::create([
        'name'          => 'Test Category',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => false,
    ]);

    $activated = $category->activate();
    expect($activated->is_active)->toBeTrue();

    $deactivated = $category->deactivate();
    expect($deactivated->is_active)->toBeFalse();
});

it('can get special conditions', function () {
    $category = VatCategory::create([
        'name'               => 'Special Category',
        'country_code'       => 'ES',
        'vat_rate'           => pct(21.00),
        'category_type'      => VatCategory::CATEGORY_TYPE_STANDARD,
        'special_conditions' => [
            'min_amount'             => 100,
            'max_amount'             => 1000,
            'requires_documentation' => true,
        ],
    ]);

    $conditions = $category->getSpecialConditions();

    expect($conditions)->toBeArray();
    expect($conditions['min_amount'])->toBe(100);
    expect($conditions['max_amount'])->toBe(1000);
    expect($conditions['requires_documentation'])->toBeTrue();
});

it('can set special conditions', function () {
    $category = VatCategory::create([
        'name'          => 'Test Category',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    $newConditions = [
        'min_amount'             => 50,
        'max_amount'             => 500,
        'requires_documentation' => false,
    ];

    $updated = $category->setSpecialConditions($newConditions);

    expect($updated->special_conditions)->toBe($newConditions);
});

it('can get category type name', function () {
    $standardCategory = VatCategory::create([
        'name'          => 'Standard Category',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    $reducedCategory = VatCategory::create([
        'name'          => 'Reduced Category',
        'country_code'  => 'ES',
        'vat_rate'      => pct(10.00),
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
    ]);

    expect($standardCategory->getCategoryTypeName())->toBe('Standard');
    expect($reducedCategory->getCategoryTypeName())->toBe('Reduced');
});

it('can get available category types', function () {
    $types = VatCategory::getAvailableCategoryTypes();

    expect($types)->toBeArray();
    expect($types)->toContain(VatCategory::CATEGORY_TYPE_STANDARD);
    expect($types)->toContain(VatCategory::CATEGORY_TYPE_REDUCED);
    expect($types)->toContain(VatCategory::CATEGORY_TYPE_EXEMPT);
});

it('automatically sets last_updated on creation and update', function () {
    $beforeCreation = now();

    $category = VatCategory::create([
        'name'          => 'Test Category',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    $afterCreation = now();

    expect($category->last_updated->timestamp)->toBeGreaterThanOrEqualTo($beforeCreation->timestamp);
    expect($category->last_updated->timestamp)->toBeLessThanOrEqualTo($afterCreation->timestamp);

    // Update and check last_updated changes
    $beforeUpdate = now();
    $category->update(['vat_rate' => pct(20.00)]);
    $afterUpdate = now();

    expect($category->fresh()->last_updated->timestamp)->toBeGreaterThanOrEqualTo($beforeUpdate->timestamp);
    expect($category->fresh()->last_updated->timestamp)->toBeLessThanOrEqualTo($afterUpdate->timestamp);
});

it('can get category statistics', function () {
    VatCategory::create([
        'name'          => 'Standard 1',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => true,
    ]);

    VatCategory::create([
        'name'          => 'Standard 2',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => false,
    ]);

    VatCategory::create([
        'name'          => 'Reduced 1',
        'country_code'  => 'FR',
        'vat_rate'      => pct(5.50),
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
        'is_active'     => true,
    ]);

    $stats = VatCategory::getCategoryStatistics();

    expect($stats['total'])->toBe(3);
    expect($stats['active'])->toBe(2);
    expect($stats['inactive'])->toBe(1);
    expect($stats['by_type'][VatCategory::CATEGORY_TYPE_STANDARD])->toBe(2);
    expect($stats['by_type'][VatCategory::CATEGORY_TYPE_REDUCED])->toBe(1);
    expect($stats['by_country']['ES'])->toBe(2);
    expect($stats['by_country']['FR'])->toBe(1);
});

it('exposes vat_rate as a FixedDecimal over the base-100 column', function () {
    $category = VatCategory::create([
        'name'          => 'Test Category',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    expect($category->vat_rate->unscaledValue())->toBe(2100);
    expect($category->vat_rate->toFloat())->toBe(21.00);
    expect($category->vat_rate->toDecimalString())->toBe('21.00');
});

it('updates vat_rate from a FixedDecimal percentage', function () {
    $category = VatCategory::create([
        'name'          => 'Test Category',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    $category->update(['vat_rate' => pct(15.50)]);

    expect($category->fresh()->vat_rate->unscaledValue())->toBe(1550);
    expect($category->fresh()->vat_rate->toFloat())->toBe(15.50);
});

it('accepts direct FixedDecimal vat_rate assignment', function () {
    $category = new VatCategory;
    $category->fill([
        'name'          => 'Test Category',
        'country_code'  => 'ES',
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    $category->vat_rate = cents(2100);

    expect($category->vat_rate->unscaledValue())->toBe(2100);
});

it('reports exempt categories via isZero on the rate', function () {
    $exempt = VatCategory::create([
        'name'          => 'Exempt',
        'country_code'  => 'ES',
        'vat_rate'      => pct(0.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD, // exempt by zero rate, not type
    ]);

    $standard = VatCategory::create([
        'name'          => 'Standard',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    expect($exempt->isExempt())->toBeTrue();
    expect($standard->isExempt())->toBeFalse();
    expect($standard->getFormattedVatRate())->toBe('21.00%');
    expect($exempt->getFormattedVatRate())->toBe('Exempt');
});

it('can get categories by country', function () {
    VatCategory::create([
        'name'          => 'ES Standard',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => true,
        'sort_order'    => 1,
    ]);

    VatCategory::create([
        'name'          => 'ES Reduced',
        'country_code'  => 'ES',
        'vat_rate'      => pct(10.00),
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
        'is_active'     => true,
        'sort_order'    => 2,
    ]);

    VatCategory::create([
        'name'          => 'ES Inactive',
        'country_code'  => 'ES',
        'vat_rate'      => pct(4.00),
        'category_type' => VatCategory::CATEGORY_TYPE_SUPER_REDUCED,
        'is_active'     => false,
        'sort_order'    => 3,
    ]);

    VatCategory::create([
        'name'          => 'FR Standard',
        'country_code'  => 'FR',
        'vat_rate'      => pct(20.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => true,
        'sort_order'    => 1,
    ]);

    $esCategories = VatCategory::getByCountry('ES');

    expect($esCategories)->toHaveCount(2);
    expect($esCategories->first()->name)->toBe('ES Standard');
    expect($esCategories->last()->name)->toBe('ES Reduced');
});

it('can get categories by category type', function () {
    VatCategory::create([
        'name'          => 'ES Standard',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => true,
        'sort_order'    => 1,
    ]);

    VatCategory::create([
        'name'          => 'FR Standard',
        'country_code'  => 'FR',
        'vat_rate'      => pct(20.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => true,
        'sort_order'    => 2,
    ]);

    VatCategory::create([
        'name'          => 'ES Reduced',
        'country_code'  => 'ES',
        'vat_rate'      => pct(10.00),
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
        'is_active'     => true,
        'sort_order'    => 1,
    ]);

    $standardCategories = VatCategory::getByCategoryType(VatCategory::CATEGORY_TYPE_STANDARD);

    expect($standardCategories)->toHaveCount(2);
    expect($standardCategories->pluck('category_type')->unique()->first())->toBe(VatCategory::CATEGORY_TYPE_STANDARD);
});

it('can get categories by country and type', function () {
    VatCategory::create([
        'name'          => 'ES Standard',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => true,
        'sort_order'    => 1,
    ]);

    VatCategory::create([
        'name'          => 'ES Reduced',
        'country_code'  => 'ES',
        'vat_rate'      => pct(10.00),
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
        'is_active'     => true,
        'sort_order'    => 2,
    ]);

    VatCategory::create([
        'name'          => 'FR Standard',
        'country_code'  => 'FR',
        'vat_rate'      => pct(20.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => true,
        'sort_order'    => 1,
    ]);

    $esStandard = VatCategory::getByCountryAndType('ES', VatCategory::CATEGORY_TYPE_STANDARD);

    expect($esStandard)->toHaveCount(1);
    expect($esStandard->first()->name)->toBe('ES Standard');
    expect($esStandard->first()->country_code)->toBe('ES');
    expect($esStandard->first()->category_type)->toBe(VatCategory::CATEGORY_TYPE_STANDARD);
});

it('can get VAT rate by category name and country', function () {
    VatCategory::create([
        'name'          => 'Standard Goods',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
    ]);

    $rate            = VatCategory::getVatRate('Standard Goods', 'ES');
    $nonExistentRate = VatCategory::getVatRate('Nonexistent', 'ES');

    expect($rate->unscaledValue())->toBe(2100);
    expect($nonExistentRate)->toBeNull();
});

it('can get standard rate for a country', function () {
    VatCategory::create([
        'name'          => 'Standard',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => true,
    ]);

    VatCategory::create([
        'name'          => 'Reduced',
        'country_code'  => 'ES',
        'vat_rate'      => pct(10.00),
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
        'is_active'     => true,
    ]);

    $rate            = VatCategory::getStandardRate('ES');
    $nonExistentRate = VatCategory::getStandardRate('FR');

    expect($rate->unscaledValue())->toBe(2100);
    expect($nonExistentRate)->toBeNull();
});

it('can get reduced rate for a country', function () {
    VatCategory::create([
        'name'          => 'Standard',
        'country_code'  => 'ES',
        'vat_rate'      => pct(21.00),
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => true,
    ]);

    VatCategory::create([
        'name'          => 'Reduced',
        'country_code'  => 'ES',
        'vat_rate'      => pct(10.00),
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
        'is_active'     => true,
    ]);

    $rate            = VatCategory::getReducedRate('ES');
    $nonExistentRate = VatCategory::getReducedRate('FR');

    expect($rate->unscaledValue())->toBe(1000);
    expect($nonExistentRate)->toBeNull();
});

it('can get super reduced rate for a country', function () {
    VatCategory::create([
        'name'          => 'Super Reduced',
        'country_code'  => 'ES',
        'vat_rate'      => pct(4.00),
        'category_type' => VatCategory::CATEGORY_TYPE_SUPER_REDUCED,
        'is_active'     => true,
    ]);

    $rate            = VatCategory::getSuperReducedRate('ES');
    $nonExistentRate = VatCategory::getSuperReducedRate('FR');

    expect($rate->unscaledValue())->toBe(400);
    expect($nonExistentRate)->toBeNull();
});
