<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\CompanyTemplateSettings;

it('can create a company template setting', function () {
    $setting = CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'modern-template',
        'is_active'    => true,
    ]);

    expect($setting)->toBeInstanceOf(CompanyTemplateSettings::class);
    expect($setting->company_id)->toBe('company-123');
    expect($setting->setting_type)->toBe(CompanyTemplateSettings::SETTING_TEMPLATE);
    expect($setting->is_active)->toBeTrue();
});

it('can scope settings for a company', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'template-1',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'company_id'   => 'company-456',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'template-2',
        'is_active'    => true,
    ]);

    $settings = CompanyTemplateSettings::forCompany('company-123')->get();

    expect($settings)->toHaveCount(1);
    expect($settings->first()->company_id)->toBe('company-123');
});

it('can scope settings by setting type', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'modern',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_NOTES,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'Default notes',
        'is_active'    => true,
    ]);

    $templateSettings = CompanyTemplateSettings::bySettingType(CompanyTemplateSettings::SETTING_TEMPLATE)->get();

    expect($templateSettings)->toHaveCount(1);
    expect($templateSettings->first()->setting_type)->toBe(CompanyTemplateSettings::SETTING_TEMPLATE);
});

it('can scope settings by invoice type', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'fiscal-template',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'proforma',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'proforma-template',
        'is_active'    => true,
    ]);

    $fiscalSettings = CompanyTemplateSettings::byInvoiceType('fiscal')->get();

    expect($fiscalSettings)->toHaveCount(1);
    expect($fiscalSettings->first()->invoice_type)->toBe('fiscal');
});

it('can scope settings by scope', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'global-template',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_CLIENT,
        'client_id'    => 'client-1',
        'value'        => 'client-template',
        'is_active'    => true,
    ]);

    $globalSettings = CompanyTemplateSettings::byScope(CompanyTemplateSettings::SCOPE_GLOBAL)->get();

    expect($globalSettings)->toHaveCount(1);
    expect($globalSettings->first()->scope)->toBe(CompanyTemplateSettings::SCOPE_GLOBAL);
});

it('can scope active settings only', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'active-template',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
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
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_NOTES,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'Global default notes',
        'is_active'    => true,
    ]);

    // Create client-specific setting
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_NOTES,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_CLIENT,
        'client_id'    => 'client-vip',
        'value'        => 'VIP client notes',
        'is_active'    => true,
    ]);

    // When requesting with client_id, should return client-specific
    $clientSetting = CompanyTemplateSettings::getSetting(
        'company-123',
        CompanyTemplateSettings::SETTING_NOTES,
        'fiscal',
        'client-vip'
    );

    expect($clientSetting)->toBe('VIP client notes');

    // When requesting without client_id, should return global
    $globalSetting = CompanyTemplateSettings::getSetting(
        'company-123',
        CompanyTemplateSettings::SETTING_NOTES,
        'fiscal'
    );

    expect($globalSetting)->toBe('Global default notes');
});

it('returns null when getting non-existent setting', function () {
    $setting = CompanyTemplateSettings::getSetting(
        'non-existent-company',
        CompanyTemplateSettings::SETTING_NOTES,
        'fiscal'
    );

    expect($setting)->toBeNull();
});

it('can set setting with global scope', function () {
    $setting = CompanyTemplateSettings::setSetting(
        'company-123',
        CompanyTemplateSettings::SETTING_NOTES,
        'fiscal',
        'Default invoice notes'
    );

    expect($setting)->toBeInstanceOf(CompanyTemplateSettings::class);
    expect($setting->value)->toBe('Default invoice notes');
    expect($setting->scope)->toBe(CompanyTemplateSettings::SCOPE_GLOBAL);
    expect($setting->is_active)->toBeTrue();
});

it('can set setting with client scope', function () {
    $setting = CompanyTemplateSettings::setSetting(
        'company-123',
        CompanyTemplateSettings::SETTING_PAYMENT_TERMS,
        'fiscal',
        'Net 30 days',
        CompanyTemplateSettings::SCOPE_CLIENT,
        'client-abc'
    );

    expect($setting)->toBeInstanceOf(CompanyTemplateSettings::class);
    expect($setting->value)->toBe('Net 30 days');
    expect($setting->scope)->toBe(CompanyTemplateSettings::SCOPE_CLIENT);
    expect($setting->client_id)->toBe('client-abc');
});

it('can update existing setting', function () {
    // Create initial setting
    CompanyTemplateSettings::setSetting(
        'company-123',
        CompanyTemplateSettings::SETTING_NOTES,
        'fiscal',
        'Original notes'
    );

    // Update same setting
    $updated = CompanyTemplateSettings::setSetting(
        'company-123',
        CompanyTemplateSettings::SETTING_NOTES,
        'fiscal',
        'Updated notes'
    );

    expect($updated->value)->toBe('Updated notes');
    expect(CompanyTemplateSettings::count())->toBe(1); // Should update, not create new
});

it('can get all settings for a company ordered correctly', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_NOTES,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'Notes',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'proforma',
        'scope'        => CompanyTemplateSettings::SCOPE_CLIENT,
        'client_id'    => 'client-1',
        'value'        => 'Template',
        'is_active'    => true,
    ]);

    $allSettings = CompanyTemplateSettings::getCompanySettings('company-123');

    expect($allSettings)->toHaveCount(2);
    expect($allSettings->first()->setting_type)->toBe(CompanyTemplateSettings::SETTING_NOTES);
});

it('can get template settings for a company', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'modern-template',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_CLIENT,
        'client_id'    => 'client-1',
        'value'        => 'client-template',
        'is_active'    => true,
    ]);

    $templateSettings = CompanyTemplateSettings::getTemplateSettings('company-123', 'fiscal');

    expect($templateSettings)->toBeArray();
    expect($templateSettings)->toHaveKey(CompanyTemplateSettings::SCOPE_GLOBAL);
    expect($templateSettings)->toHaveKey(CompanyTemplateSettings::SCOPE_CLIENT);
    expect($templateSettings[CompanyTemplateSettings::SCOPE_GLOBAL])->toBe('modern-template');
});

it('can get default notes for a company', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_NOTES,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'Standard invoice notes',
        'is_active'    => true,
    ]);

    $notes = CompanyTemplateSettings::getDefaultNotes('company-123', 'fiscal');

    expect($notes)->toBe('Standard invoice notes');
});

it('can get payment terms for a company', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_PAYMENT_TERMS,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'Payment due in 30 days',
        'is_active'    => true,
    ]);

    $terms = CompanyTemplateSettings::getPaymentTerms('company-123', 'fiscal');

    expect($terms)->toBe('Payment due in 30 days');
});

it('can get client-specific notes', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_NOTES,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_CLIENT,
        'client_id'    => 'client-premium',
        'value'        => 'Premium client notes',
        'is_active'    => true,
    ]);

    $notes = CompanyTemplateSettings::getDefaultNotes('company-123', 'fiscal', 'client-premium');

    expect($notes)->toBe('Premium client notes');
});

it('can get available setting types', function () {
    $types = CompanyTemplateSettings::getSettingTypes();

    expect($types)->toBeArray();
    expect($types)->toHaveKey(CompanyTemplateSettings::SETTING_TEMPLATE);
    expect($types)->toHaveKey(CompanyTemplateSettings::SETTING_NOTES);
    expect($types)->toHaveKey(CompanyTemplateSettings::SETTING_PAYMENT_TERMS);
    expect($types[CompanyTemplateSettings::SETTING_TEMPLATE])->toBe('Template');
});

it('can get available scopes', function () {
    $scopes = CompanyTemplateSettings::getScopes();

    expect($scopes)->toBeArray();
    expect($scopes)->toHaveKey(CompanyTemplateSettings::SCOPE_GLOBAL);
    expect($scopes)->toHaveKey(CompanyTemplateSettings::SCOPE_CLIENT);
    expect($scopes)->toHaveKey(CompanyTemplateSettings::SCOPE_INDIVIDUAL);
    expect($scopes[CompanyTemplateSettings::SCOPE_GLOBAL])->toBe('Global');
});

it('ignores inactive settings when getting value', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_NOTES,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'Inactive notes',
        'is_active'    => false,
    ]);

    $notes = CompanyTemplateSettings::getDefaultNotes('company-123', 'fiscal');

    expect($notes)->toBeNull();
});

it('returns client-specific setting when both global and client exist', function () {
    // Create global setting first
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_PAYMENT_TERMS,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'Net 30 days',
        'is_active'    => true,
    ]);

    // Create client-specific setting
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_PAYMENT_TERMS,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_CLIENT,
        'client_id'    => 'client-vip',
        'value'        => 'Net 15 days',
        'is_active'    => true,
    ]);

    // Should return client-specific
    $vipTerms = CompanyTemplateSettings::getPaymentTerms('company-123', 'fiscal', 'client-vip');
    expect($vipTerms)->toBe('Net 15 days');

    // Should return global for other clients
    $standardTerms = CompanyTemplateSettings::getPaymentTerms('company-123', 'fiscal');
    expect($standardTerms)->toBe('Net 30 days');
});

it('returns global setting when client-specific is inactive', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_NOTES,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'Global notes',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_NOTES,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_CLIENT,
        'client_id'    => 'client-1',
        'value'        => 'Client notes',
        'is_active'    => false, // Inactive
    ]);

    $notes = CompanyTemplateSettings::getDefaultNotes('company-123', 'fiscal', 'client-1');

    expect($notes)->toBe('Global notes'); // Falls back to global
});

it('filters out inactive settings when getting all company settings', function () {
    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_TEMPLATE,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'active-template',
        'is_active'    => true,
    ]);

    CompanyTemplateSettings::create([
        'company_id'   => 'company-123',
        'setting_type' => CompanyTemplateSettings::SETTING_NOTES,
        'invoice_type' => 'fiscal',
        'scope'        => CompanyTemplateSettings::SCOPE_GLOBAL,
        'value'        => 'inactive-notes',
        'is_active'    => false,
    ]);

    $settings = CompanyTemplateSettings::getCompanySettings('company-123');

    expect($settings)->toHaveCount(1); // Only active one
    expect($settings->first()->is_active)->toBeTrue();
});
