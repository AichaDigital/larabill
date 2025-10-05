<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\ModelMappingService;

it('can get default model class for user', function () {
    $userModel = ModelMappingService::getModelClass('user');
    expect($userModel)->toBe(\AichaDigital\Larabill\Tests\Models\User::class);
});

it('can get configured model class for user', function () {
    // Set custom user model in config
    $this->app['config']->set('larabill.models.user', \AichaDigital\Larabill\Tests\Models\CustomUser::class);

    $userModel = ModelMappingService::getModelClass('user');
    expect($userModel)->toBe(\AichaDigital\Larabill\Tests\Models\CustomUser::class);
});

it('can get default model class for user_tax_info', function () {
    $userTaxInfoModel = ModelMappingService::getModelClass('user_tax_info');
    expect($userTaxInfoModel)->toBe(\AichaDigital\Larabill\Models\UserTaxInfo::class);
});

it('can get configured model class for user_tax_info', function () {
    // Set custom user tax info model in config
    $this->app['config']->set('larabill.models.user_tax_info', \AichaDigital\Larabill\Tests\Models\CustomUserTaxInfo::class);

    $userTaxInfoModel = ModelMappingService::getModelClass('user_tax_info');
    expect($userTaxInfoModel)->toBe(\AichaDigital\Larabill\Tests\Models\CustomUserTaxInfo::class);
});

it('can map fields correctly', function () {
    $this->app['config']->set('larabill.field_mappings.user_tax_info', [
        'user_id'      => 'customer_id',
        'tax_id'       => 'fiscal_id',
        'company_name' => 'business_name',
    ]);

    $data = [
        'customer_id'   => 1,
        'fiscal_id'     => 'ESB12345678',
        'business_name' => 'Test Company S.L.',
    ];

    $mappedData = ModelMappingService::mapFields($data, 'user_tax_info');

    expect($mappedData['user_id'])->toBe(1);
    expect($mappedData['tax_id'])->toBe('ESB12345678');
    expect($mappedData['company_name'])->toBe('Test Company S.L.');
});

it('can reverse map fields correctly', function () {
    $this->app['config']->set('larabill.field_mappings.user_tax_info', [
        'user_id'      => 'customer_id',
        'tax_id'       => 'fiscal_id',
        'company_name' => 'business_name',
    ]);

    $data = [
        'user_id'      => 1,
        'tax_id'       => 'ESB12345678',
        'company_name' => 'Test Company S.L.',
    ];

    $reverseMappedData = ModelMappingService::reverseMapFields($data, 'user_tax_info');

    expect($reverseMappedData['customer_id'])->toBe(1);
    expect($reverseMappedData['fiscal_id'])->toBe('ESB12345678');
    expect($reverseMappedData['business_name'])->toBe('Test Company S.L.');
});

it('can validate model mapping configuration', function () {
    expect(ModelMappingService::validateModelMapping('user', \AichaDigital\Larabill\Tests\Models\User::class))->toBeTrue();
    expect(ModelMappingService::validateModelMapping('user', 'InvalidClass'))->toBeFalse();
});

it('throws exception for unknown model type', function () {
    expect(function () {
        ModelMappingService::getModelClass('unknown_model');
    })->toThrow(\InvalidArgumentException::class);
});
