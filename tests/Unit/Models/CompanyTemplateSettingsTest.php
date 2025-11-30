<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\{SettingScope, SettingType, TemplateInvoiceType};
use AichaDigital\Larabill\Models\CompanyTemplateSettings;

it('can create a company template setting', function () {
    $setting = CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'modern-template',
        'is_active'    => true,
    ]);

    expect($setting)->toBeInstanceOf(CompanyTemplateSettings::class);
    expect($setting->user_id)->toBe('company-123');
    expect($setting->setting_type)->toBe(SettingType::TEMPLATE);
    expect($setting->is_active)->toBeTrue();
});

it('can scope settings for a company', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'template-1',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'user_id'      => 'company-456',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'template-2',
        'is_active'    => true,
    ]);

    $settings = CompanyTemplateSettings::forCompany('company-123')->get();

    expect($settings)->toHaveCount(1);
    expect($settings->first()->user_id)->toBe('company-123');
});

it('can scope settings by setting type', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'modern',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::NOTES,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'Default notes',
        'is_active'    => true,
    ]);

    $templateSettings = CompanyTemplateSettings::bySettingType(SettingType::TEMPLATE)->get();

    expect($templateSettings)->toHaveCount(1);
    expect($templateSettings->first()->setting_type)->toBe(SettingType::TEMPLATE);
});

it('can scope settings by invoice type', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'fiscal-template',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::PROFORMA,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'proforma-template',
        'is_active'    => true,
    ]);

    $fiscalSettings = CompanyTemplateSettings::byInvoiceType(TemplateInvoiceType::FISCAL)->get();

    expect($fiscalSettings)->toHaveCount(1);
    expect($fiscalSettings->first()->invoice_type)->toBe(TemplateInvoiceType::FISCAL);
});

it('can scope settings by scope', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'global-template',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::CLIENT,
        'client_id'    => 'client-1',
        'value'        => 'client-template',
        'is_active'    => true,
    ]);

    $globalSettings = CompanyTemplateSettings::byScope(SettingScope::GLOBAL_SCOPE)->get();

    expect($globalSettings)->toHaveCount(1);
    expect($globalSettings->first()->scope)->toBe(SettingScope::GLOBAL_SCOPE);
});

it('can scope active settings only', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'active-template',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'inactive-template',
        'is_active'    => false,
    ]);

    $activeSettings = CompanyTemplateSettings::active()->get();

    expect($activeSettings)->toHaveCount(1);
    expect($activeSettings->first()->is_active)->toBeTrue();
});

it('can get setting with client-specific priority', function () {
    // Create global setting
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::NOTES,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'Global default notes',
        'is_active'    => true,
    ]);

    // Create client-specific setting
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::NOTES,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::CLIENT,
        'client_id'    => 'client-vip',
        'value'        => 'VIP client notes',
        'is_active'    => true,
    ]);

    // When requesting with client_id, should return client-specific
    $clientSetting = CompanyTemplateSettings::getSetting(
        'company-123',
        SettingType::NOTES,
        TemplateInvoiceType::FISCAL,
        'client-vip'
    );

    expect($clientSetting)->toBe('VIP client notes');

    // When requesting without client_id, should return global
    $globalSetting = CompanyTemplateSettings::getSetting(
        'company-123',
        SettingType::NOTES,
        TemplateInvoiceType::FISCAL
    );

    expect($globalSetting)->toBe('Global default notes');
});

it('returns null when getting non-existent setting', function () {
    $setting = CompanyTemplateSettings::getSetting(
        'non-existent-company',
        SettingType::NOTES,
        TemplateInvoiceType::FISCAL
    );

    expect($setting)->toBeNull();
});

it('can set setting with global scope', function () {
    $setting = CompanyTemplateSettings::setSetting(
        'company-123',
        SettingType::NOTES,
        TemplateInvoiceType::FISCAL,
        'Default invoice notes'
    );

    expect($setting)->toBeInstanceOf(CompanyTemplateSettings::class);
    expect($setting->value)->toBe('Default invoice notes');
    expect($setting->scope)->toBe(SettingScope::GLOBAL_SCOPE);
    expect($setting->is_active)->toBeTrue();
});

it('can set setting with client scope', function () {
    $setting = CompanyTemplateSettings::setSetting(
        'company-123',
        SettingType::PAYMENT_TERMS,
        TemplateInvoiceType::FISCAL,
        'Net 30 days',
        SettingScope::CLIENT,
        'client-abc'
    );

    expect($setting)->toBeInstanceOf(CompanyTemplateSettings::class);
    expect($setting->value)->toBe('Net 30 days');
    expect($setting->scope)->toBe(SettingScope::CLIENT);
    expect($setting->client_id)->toBe('client-abc');
});

it('can update existing setting', function () {
    // Create initial setting
    CompanyTemplateSettings::setSetting(
        'company-123',
        SettingType::NOTES,
        TemplateInvoiceType::FISCAL,
        'Original notes'
    );

    // Update same setting
    $updated = CompanyTemplateSettings::setSetting(
        'company-123',
        SettingType::NOTES,
        TemplateInvoiceType::FISCAL,
        'Updated notes'
    );

    expect($updated->value)->toBe('Updated notes');
    expect(CompanyTemplateSettings::count())->toBe(1); // Should update, not create new
});

it('can get all settings for a company ordered correctly', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::NOTES,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'Notes',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::PROFORMA,
        'scope'        => SettingScope::CLIENT,
        'client_id'    => 'client-1',
        'value'        => 'Template',
        'is_active'    => true,
    ]);

    $allSettings = CompanyTemplateSettings::getCompanySettings('company-123');

    expect($allSettings)->toHaveCount(2);
    // Ordered by setting_type (TEMPLATE=0 < NOTES=1) so TEMPLATE comes first
    expect($allSettings->first()->setting_type)->toBe(SettingType::TEMPLATE);
});

it('can get template settings for a company', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'modern-template',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::CLIENT,
        'client_id'    => 'client-1',
        'value'        => 'client-template',
        'is_active'    => true,
    ]);

    $templateSettings = CompanyTemplateSettings::getTemplateSettings('company-123', TemplateInvoiceType::FISCAL);

    expect($templateSettings)->toBeArray();
    expect($templateSettings)->toHaveKey('GLOBAL_SCOPE');
    expect($templateSettings)->toHaveKey('CLIENT');
    expect($templateSettings['GLOBAL_SCOPE'])->toBe('modern-template');
});

it('can get default notes for a company', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::NOTES,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'Standard invoice notes',
        'is_active'    => true,
    ]);

    $notes = CompanyTemplateSettings::getDefaultNotes('company-123', TemplateInvoiceType::FISCAL);

    expect($notes)->toBe('Standard invoice notes');
});

it('can get payment terms for a company', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::PAYMENT_TERMS,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'Payment due in 30 days',
        'is_active'    => true,
    ]);

    $terms = CompanyTemplateSettings::getPaymentTerms('company-123', TemplateInvoiceType::FISCAL);

    expect($terms)->toBe('Payment due in 30 days');
});

it('can get client-specific notes', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::NOTES,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::CLIENT,
        'client_id'    => 'client-premium',
        'value'        => 'Premium client notes',
        'is_active'    => true,
    ]);

    $notes = CompanyTemplateSettings::getDefaultNotes('company-123', TemplateInvoiceType::FISCAL, 'client-premium');

    expect($notes)->toBe('Premium client notes');
});

it('can get available setting types', function () {
    $types = CompanyTemplateSettings::getSettingTypes();

    expect($types)->toBeArray();
    expect($types)->toHaveKey(SettingType::TEMPLATE->value);
    expect($types)->toHaveKey(SettingType::NOTES->value);
    expect($types)->toHaveKey(SettingType::PAYMENT_TERMS->value);
    expect($types[SettingType::TEMPLATE->value])->toBe('Template');
});

it('can get available scopes', function () {
    $scopes = CompanyTemplateSettings::getScopes();

    expect($scopes)->toBeArray();
    expect($scopes)->toHaveKey(SettingScope::GLOBAL_SCOPE->value);
    expect($scopes)->toHaveKey(SettingScope::CLIENT->value);
    expect($scopes)->toHaveKey(SettingScope::INDIVIDUAL->value);
    expect($scopes[SettingScope::GLOBAL_SCOPE->value])->toBe('Global');
});

it('ignores inactive settings when getting value', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::NOTES,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'Inactive notes',
        'is_active'    => false,
    ]);

    $notes = CompanyTemplateSettings::getDefaultNotes('company-123', TemplateInvoiceType::FISCAL);

    expect($notes)->toBeNull();
});

it('returns client-specific setting when both global and client exist', function () {
    // Create global setting first
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::PAYMENT_TERMS,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'Net 30 days',
        'is_active'    => true,
    ]);

    // Create client-specific setting
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::PAYMENT_TERMS,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::CLIENT,
        'client_id'    => 'client-vip',
        'value'        => 'Net 15 days',
        'is_active'    => true,
    ]);

    // Should return client-specific
    $vipTerms = CompanyTemplateSettings::getPaymentTerms('company-123', TemplateInvoiceType::FISCAL, 'client-vip');
    expect($vipTerms)->toBe('Net 15 days');

    // Should return global for other clients
    $standardTerms = CompanyTemplateSettings::getPaymentTerms('company-123', TemplateInvoiceType::FISCAL);
    expect($standardTerms)->toBe('Net 30 days');
});

it('returns global setting when client-specific is inactive', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::NOTES,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'Global notes',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::NOTES,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::CLIENT,
        'client_id'    => 'client-1',
        'value'        => 'Client notes',
        'is_active'    => false, // Inactive
    ]);

    $notes = CompanyTemplateSettings::getDefaultNotes('company-123', TemplateInvoiceType::FISCAL, 'client-1');

    expect($notes)->toBe('Global notes'); // Falls back to global
});

it('filters out inactive settings when getting all company settings', function () {
    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::TEMPLATE,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'active-template',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'user_id'      => 'company-123',
        'setting_type' => SettingType::NOTES,
        'invoice_type' => TemplateInvoiceType::FISCAL,
        'scope'        => SettingScope::GLOBAL_SCOPE,
        'value'        => 'inactive-notes',
        'is_active'    => false,
    ]);

    $settings = CompanyTemplateSettings::getCompanySettings('company-123');

    expect($settings)->toHaveCount(1); // Only active one
    expect($settings->first()->is_active)->toBeTrue();
});
