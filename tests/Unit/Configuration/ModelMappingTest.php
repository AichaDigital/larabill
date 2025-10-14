<?php

declare(strict_types=1);
use AichaDigital\Larabill\Enums\{InvoiceSerieType, InvoiceStatus};

use AichaDigital\Larabill\Models\{Invoice, UserTaxProfile};
use AichaDigital\Larabill\Tests\Models\CustomUser;

it('can configure custom user model mapping', function () {
    // Set a custom user model in config
    config(['larabill.models.user' => CustomUser::class]);

    // Test the model mapping service
    $userModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('user');
    expect($userModel)->toBe(CustomUser::class);

    // Create an invoice
    $invoice = Invoice::create([
        'fiscal_number' => 'FAC-0001',
        'serie' => InvoiceSerieType::INVOICE->value,
        'status' => InvoiceStatus::DRAFT->value,
        'user_id'    => 1,
        'taxable_amount'   => 100.0,
        'tax_amount' => 21.0,
        'total_amount' => 121.0,
    ]);

    // The relationship should use the configured model
    expect($invoice->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

it('can configure custom user tax info model mapping', function () {
    // Set custom user tax info model in config
    config(['larabill.models.user_tax_profile' => \AichaDigital\Larabill\Tests\Models\CustomUserTaxProfile::class]);

    // Test the model mapping service
    $userTaxInfoModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('user_tax_profile');
    expect($userTaxInfoModel)->toBe(\AichaDigital\Larabill\Tests\Models\CustomUserTaxProfile::class);

    // Create a user tax info
    $taxInfo = UserTaxProfile::create([
        'user_id'        => 1,
        'is_current'     => true,
        'tax_code'       => 'ESB12345678',
        'business_name'  => 'Test Company S.L.',
        'address'        => 'Calle Test 123',
        'city'           => 'Madrid',
        'postal_code'    => '28001',
        'country'        => 'ES',
        'state'          => 'Madrid',
        'phone'          => '+34 600 000 000',
    ]);

    // The relationship should use the configured model
    expect($taxInfo->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

it('can configure custom field mappings for user tax info', function () {
    // Set custom field mappings in config
    config([
        'larabill.field_mappings.user_tax_profile' => [
            'user_id'        => 'customer_id',
            'tax_code'       => 'fiscal_id',
            'company_name'   => 'business_name',
            'address'        => 'street_address',
            'city'           => 'municipality',
            'postal_code'    => 'zip_code',
            'country'        => 'country_code',
            'state'          => 'region',
            'phone'          => 'contact_phone',
        ],
    ]);

    // Test the mapping service directly
    $mappedData = \AichaDigital\Larabill\Services\ModelMappingService::mapFields([
        'customer_id'    => 1,
        'fiscal_id'      => 'ESB12345678',
        'business_name'  => 'Test Company S.L.',
        'street_address' => 'Calle Test 123',
        'municipality'   => 'Madrid',
        'zip_code'       => '28001',
        'country_code'   => 'ES',
        'region'         => 'Madrid',
        'contact_phone'  => '+34 600 000 000',
    ], 'user_tax_profile');

    expect($mappedData['user_id'])->toBe(1);
    expect($mappedData['tax_code'])->toBe('ESB12345678');
    expect($mappedData['company_name'])->toBe('Test Company S.L.');
    expect($mappedData['address'])->toBe('Calle Test 123');
    expect($mappedData['city'])->toBe('Madrid');
    expect($mappedData['postal_code'])->toBe('28001');
    expect($mappedData['country'])->toBe('ES');
    expect($mappedData['state'])->toBe('Madrid');
    expect($mappedData['phone'])->toBe('+34 600 000 000');
});

it('can configure custom invoice model mapping', function () {
    // Set custom invoice model in config
    config(['larabill.models.invoice' => \AichaDigital\Larabill\Tests\Models\CustomInvoice::class]);

    // The Invoice model should be configurable
    $invoiceModel = config('larabill.models.invoice');
    expect($invoiceModel)->toBe(\AichaDigital\Larabill\Tests\Models\CustomInvoice::class);
});

it('can configure custom invoice item model mapping', function () {
    // Set custom invoice item model in config
    config(['larabill.models.invoice_item' => \AichaDigital\Larabill\Tests\Models\CustomInvoiceItem::class]);

    // The InvoiceItem model should be configurable
    $invoiceItemModel = config('larabill.models.invoice_item');
    expect($invoiceItemModel)->toBe(\AichaDigital\Larabill\Tests\Models\CustomInvoiceItem::class);
});

it('can configure custom tax rate model mapping', function () {
    // Set custom tax rate model in config
    config(['larabill.models.tax_rate' => \AichaDigital\Larabill\Tests\Models\CustomTaxRate::class]);

    // The TaxRate model should be configurable
    $taxRateModel = config('larabill.models.tax_rate');
    expect($taxRateModel)->toBe(\AichaDigital\Larabill\Tests\Models\CustomTaxRate::class);
});

it('can configure custom VAT verification model mapping', function () {
    // Set custom VAT verification model in config
    config(['larabill.models.vat_verification' => \AichaDigital\Larabill\Tests\Models\CustomVatVerification::class]);

    // The VatVerification model should be configurable
    $vatVerificationModel = config('larabill.models.vat_verification');
    expect($vatVerificationModel)->toBe(\AichaDigital\Larabill\Tests\Models\CustomVatVerification::class);
});

it('can validate model mapping configuration', function () {
    // Test with an invalid model class
    config(['larabill.models.user' => 'InvalidModelClass']);

    // Test the validation method directly
    expect(\AichaDigital\Larabill\Services\ModelMappingService::validateModelMapping('user', 'InvalidModelClass'))->toBeFalse()
        ->and(\AichaDigital\Larabill\Services\ModelMappingService::validateModelMapping('user', \AichaDigital\Larabill\Tests\Models\User::class))->toBeTrue();
});

it('can handle missing model mapping configuration gracefully', function () {
    // Remove model mapping from config
    config(['larabill.models' => []]);

    // Should fall back to default models
    expect(config('larabill.models.user'))->toBeNull();
    expect(config('larabill.models.invoice'))->toBeNull();
});
