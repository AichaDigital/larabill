<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\InvoiceTemplate;

it('can create an invoice template', function () {
    $template = InvoiceTemplate::create([
        'name'          => 'modern',
        'display_name'  => 'Modern Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.templates.modern',
        'description'   => 'A modern invoice template',
        'is_default'    => false,
        'is_active'     => true,
        'settings'      => ['color' => 'blue', 'font' => 'Arial'],
    ]);

    expect($template)->toBeInstanceOf(InvoiceTemplate::class);
    expect($template->name)->toBe('modern');
    expect($template->display_name)->toBe('Modern Template');
    expect($template->type)->toBe('fiscal');
    expect($template->is_active)->toBeTrue();
    expect($template->settings)->toBe(['color' => 'blue', 'font' => 'Arial']);
});

it('can scope templates by type', function () {
    InvoiceTemplate::create([
        'name'          => 'fiscal-1',
        'display_name'  => 'Fiscal Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.fiscal',
        'is_active'     => true,
    ]);

    InvoiceTemplate::create([
        'name'          => 'proforma-1',
        'display_name'  => 'Proforma Template',
        'serie' => InvoiceSerieType::PROFORMA->value,
        'template_path' => 'pdf.proforma',
        'is_active'     => true,
    ]);

    $fiscalTemplates = InvoiceTemplate::byType('fiscal')->get();

    expect($fiscalTemplates)->toHaveCount(1);
    expect($fiscalTemplates->first()->type)->toBe('fiscal');
});

it('can scope active templates only', function () {
    InvoiceTemplate::create([
        'name'          => 'active-template',
        'display_name'  => 'Active Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.active',
        'is_active'     => true,
    ]);

    InvoiceTemplate::create([
        'name'          => 'inactive-template',
        'display_name'  => 'Inactive Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.inactive',
        'is_active'     => false,
    ]);

    $activeTemplates = InvoiceTemplate::active()->get();

    expect($activeTemplates)->toHaveCount(1);
    expect($activeTemplates->first()->is_active)->toBeTrue();
});

it('can scope default templates', function () {
    InvoiceTemplate::create([
        'name'          => 'default-template',
        'display_name'  => 'Default Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.default',
        'is_default'    => true,
        'is_active'     => true,
    ]);

    InvoiceTemplate::create([
        'name'          => 'non-default-template',
        'display_name'  => 'Non-Default Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.non-default',
        'is_default'    => false,
        'is_active'     => true,
    ]);

    $defaultTemplates = InvoiceTemplate::default()->get();

    expect($defaultTemplates)->toHaveCount(1);
    expect($defaultTemplates->first()->is_default)->toBeTrue();
});

it('can get default template for a specific type', function () {
    InvoiceTemplate::create([
        'name'          => 'fiscal-default',
        'display_name'  => 'Fiscal Default',
        'type'          => 'fiscal',
        'template_path' => 'pdf.fiscal-default',
        'is_default'    => true,
        'is_active'     => true,
    ]);

    InvoiceTemplate::create([
        'name'          => 'proforma-default',
        'display_name'  => 'Proforma Default',
        'serie' => InvoiceSerieType::PROFORMA->value,
        'template_path' => 'pdf.proforma-default',
        'is_default'    => true,
        'is_active'     => true,
    ]);

    $fiscalDefault = InvoiceTemplate::getDefaultForType('fiscal');

    expect($fiscalDefault)->not->toBeNull();
    expect($fiscalDefault->type)->toBe('fiscal');
    expect($fiscalDefault->is_default)->toBeTrue();
});

it('returns null when no default template exists for type', function () {
    $default = InvoiceTemplate::getDefaultForType('non-existent');

    expect($default)->toBeNull();
});

it('can get all active templates for a type', function () {
    InvoiceTemplate::create([
        'name'          => 'fiscal-default',
        'display_name'  => 'Fiscal Default',
        'type'          => 'fiscal',
        'template_path' => 'pdf.fiscal-default',
        'is_default'    => true,
        'is_active'     => true,
    ]);

    InvoiceTemplate::create([
        'name'          => 'fiscal-alternative',
        'display_name'  => 'Fiscal Alternative',
        'type'          => 'fiscal',
        'template_path' => 'pdf.fiscal-alt',
        'is_default'    => false,
        'is_active'     => true,
    ]);

    InvoiceTemplate::create([
        'name'          => 'fiscal-inactive',
        'display_name'  => 'Fiscal Inactive',
        'type'          => 'fiscal',
        'template_path' => 'pdf.fiscal-inactive',
        'is_default'    => false,
        'is_active'     => false,
    ]);

    $activeTemplates = InvoiceTemplate::getActiveForType('fiscal');

    expect($activeTemplates)->toHaveCount(2);
    // Default template should be first
    expect($activeTemplates->first()->is_default)->toBeTrue();
    expect($activeTemplates->last()->name)->toBe('fiscal-alternative');
});

it('can check if template exists and is active', function () {
    InvoiceTemplate::create([
        'name'          => 'existing-template',
        'display_name'  => 'Existing Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.existing',
        'is_active'     => true,
    ]);

    InvoiceTemplate::create([
        'name'          => 'inactive-template',
        'display_name'  => 'Inactive Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.inactive',
        'is_active'     => false,
    ]);

    expect(InvoiceTemplate::existsAndActive('existing-template', 'fiscal'))->toBeTrue();
    expect(InvoiceTemplate::existsAndActive('inactive-template', 'fiscal'))->toBeFalse();
    expect(InvoiceTemplate::existsAndActive('non-existent', 'fiscal'))->toBeFalse();
});

it('can get template by name and type', function () {
    InvoiceTemplate::create([
        'name'          => 'modern',
        'display_name'  => 'Modern Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.modern',
        'is_active'     => true,
    ]);

    $template = InvoiceTemplate::getByName('modern', 'fiscal');

    expect($template)->not->toBeNull();
    expect($template->name)->toBe('modern');
    expect($template->type)->toBe('fiscal');
});

it('returns null when getting inactive template by name', function () {
    InvoiceTemplate::create([
        'name'          => 'inactive',
        'display_name'  => 'Inactive Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.inactive',
        'is_active'     => false,
    ]);

    $template = InvoiceTemplate::getByName('inactive', 'fiscal');

    expect($template)->toBeNull();
});

it('can get and set template settings', function () {
    $template = InvoiceTemplate::create([
        'name'          => 'configurable',
        'display_name'  => 'Configurable Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.configurable',
        'is_active'     => true,
        'settings'      => ['color' => 'blue', 'font' => 'Arial'],
    ]);

    // Get setting
    expect($template->getSetting('color'))->toBe('blue');
    expect($template->getSetting('font'))->toBe('Arial');
    expect($template->getSetting('non-existent', 'default'))->toBe('default');

    // Set setting
    $template->setSetting('color', 'red');
    $template->setSetting('size', '12pt');

    expect($template->settings['color'])->toBe('red');
    expect($template->settings['size'])->toBe('12pt');
});

it('can get template settings when settings is null', function () {
    $template = InvoiceTemplate::create([
        'name'          => 'no-settings',
        'display_name'  => 'No Settings Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.no-settings',
        'is_active'     => true,
        'settings'      => null,
    ]);

    expect($template->getSetting('any', 'default'))->toBe('default');
});

it('can set template settings when settings is null', function () {
    $template = InvoiceTemplate::create([
        'name'          => 'init-settings',
        'display_name'  => 'Init Settings Template',
        'type'          => 'fiscal',
        'template_path' => 'pdf.init-settings',
        'is_active'     => true,
        'settings'      => null,
    ]);

    $template->setSetting('new_key', 'new_value');

    expect($template->settings)->toBeArray();
    expect($template->settings['new_key'])->toBe('new_value');
});

it('can get available template types', function () {
    $types = InvoiceTemplate::getAvailableTypes();

    expect($types)->toBeArray();
    expect($types)->toHaveKey('fiscal');
    expect($types)->toHaveKey('proforma');
    expect($types)->toHaveKey('reverse-charge');
    expect($types)->toHaveKey('exempt');
    expect($types['fiscal'])->toBe('Factura Fiscal');
});

it('can get template statistics', function () {
    InvoiceTemplate::create([
        'name'          => 'fiscal-1',
        'display_name'  => 'Fiscal 1',
        'type'          => 'fiscal',
        'template_path' => 'pdf.fiscal-1',
        'is_default'    => true,
        'is_active'     => true,
    ]);

    InvoiceTemplate::create([
        'name'          => 'fiscal-2',
        'display_name'  => 'Fiscal 2',
        'type'          => 'fiscal',
        'template_path' => 'pdf.fiscal-2',
        'is_default'    => false,
        'is_active'     => true,
    ]);

    InvoiceTemplate::create([
        'name'          => 'proforma-1',
        'display_name'  => 'Proforma 1',
        'serie' => InvoiceSerieType::PROFORMA->value,
        'template_path' => 'pdf.proforma-1',
        'is_default'    => true,
        'is_active'     => true,
    ]);

    InvoiceTemplate::create([
        'name'          => 'inactive-template',
        'display_name'  => 'Inactive',
        'type'          => 'fiscal',
        'template_path' => 'pdf.inactive',
        'is_default'    => false,
        'is_active'     => false,
    ]);

    $stats = InvoiceTemplate::getStatistics();

    expect($stats)->toBeArray();
    expect($stats['total_templates'])->toBe(4);
    expect($stats['active_templates'])->toBe(3);
    expect($stats['default_templates'])->toBe(2);
    expect($stats['templates_by_type'])->toHaveKey('fiscal');
    expect($stats['templates_by_type'])->toHaveKey('proforma');
    expect($stats['templates_by_type']['fiscal'])->toBe(2);
    expect($stats['templates_by_type']['proforma'])->toBe(1);
});
