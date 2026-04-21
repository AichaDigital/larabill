<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\LegalEntityType;
use Illuminate\Database\Eloquent\Relations\HasMany;

it('can create a legal entity type', function () {
    $type = LegalEntityType::factory()->create([
        'code'      => 'TEST_TYPE',
        'name'      => 'Test Legal Entity',
        'is_active' => true,
    ]);

    expect($type->code)->toBe('TEST_TYPE')
        ->and($type->name)->toBe('Test Legal Entity')
        ->and($type->is_active)->toBeTrue()
        ->and($type->exists)->toBeTrue();
});

it('uses id as primary key', function () {
    $type = LegalEntityType::factory()->create();

    expect($type->getKeyName())->toBe('id')
        ->and($type->getKey())->toBeInt()
        ->and($type->incrementing)->toBeTrue();
});

it('can scope active types', function () {
    LegalEntityType::factory()->create(['is_active' => true]);
    LegalEntityType::factory()->create(['is_active' => false]);

    $activeTypes = LegalEntityType::active()->get();

    expect($activeTypes)->toHaveCount(1)
        ->and($activeTypes->first()->is_active)->toBeTrue();
});

it('can create different legal entity types by code', function () {
    $person = LegalEntityType::factory()->create([
        'code' => 'PERSONA_FISICA',
        'name' => 'Persona Física',
    ]);

    $company = LegalEntityType::factory()->create([
        'code' => 'SOCIEDAD_LIMITADA',
        'name' => 'Sociedad Limitada',
    ]);

    expect($person->code)->toBe('PERSONA_FISICA')
        ->and($company->code)->toBe('SOCIEDAD_LIMITADA');
});

it('has relationship with users', function () {
    $type = LegalEntityType::factory()->create();

    expect($type->users())->toBeInstanceOf(HasMany::class);
});

it('can filter by country', function () {
    $spanishType = LegalEntityType::factory()->create(['country_code' => 'ES']);
    $frenchType  = LegalEntityType::factory()->create(['country_code' => 'FR']);

    $spanish = LegalEntityType::where('country_code', 'ES')->get();

    expect($spanish)->toHaveCount(1)
        ->and($spanish->first()->id)->toBe($spanishType->id);
});

it('requires tax id by default', function () {
    $type = LegalEntityType::factory()->create();

    expect($type->requires_tax_id)->toBeTrue();
});

it('casts metadata as array', function () {
    $type = LegalEntityType::factory()->create([
        'metadata' => ['registro_mercantil' => true, 'escritura_publica' => false],
    ]);

    expect($type->metadata)->toBeArray()
        ->and($type->metadata)->toHaveKey('registro_mercantil')
        ->and($type->metadata['registro_mercantil'])->toBeTrue();
});
