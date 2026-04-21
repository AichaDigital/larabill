<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Tests\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('can create an invoice series control', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'prefix'            => 'INV',
        'serie'             => InvoiceSerieType::INVOICE,
        'fiscal_year'       => 2024,
        'fiscal_year_start' => now()->startOfYear(),
        'fiscal_year_end'   => now()->endOfYear(),
        'last_number'       => 0,
        'start_number'      => 1,
        'reset_annually'    => true,
        'is_active'         => true,
    ]);

    expect($control)->toBeInstanceOf(InvoiceSeriesControl::class)
        ->and($control->prefix)->toBe('INV')
        ->and($control->serie)->toBe(InvoiceSerieType::INVOICE)
        ->and($control->fiscal_year)->toBe(2024)
        ->and($control->last_number)->toBe(0)
        ->and($control->start_number)->toBe(1)
        ->and($control->reset_annually)->toBeTrue()
        ->and($control->is_active)->toBeTrue();
});

it('can cast serie as enum', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'serie' => InvoiceSerieType::PROFORMA,
    ]);

    expect($control->serie)->toBeInstanceOf(InvoiceSerieType::class)
        ->and($control->serie)->toBe(InvoiceSerieType::PROFORMA);
});

it('can belong to a user', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $userModel = config('larabill.user_model', 'App\\Models\\User');

    expect($control->user)->toBeInstanceOf($userModel)
        ->and($control->user->id)->toBe($this->user->id);
});

it('can have null user_id', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'user_id' => null,
    ]);

    expect($control->user_id)->toBeNull();
});

it('can scope active series only', function () {
    InvoiceSeriesControl::factory()->create(['is_active' => true]);
    InvoiceSeriesControl::factory()->create(['is_active' => false]);

    $active = InvoiceSeriesControl::active()->get();

    expect($active)->toHaveCount(1)
        ->and($active->first()->is_active)->toBeTrue();
});

it('can scope by fiscal year', function () {
    InvoiceSeriesControl::factory()->create(['fiscal_year' => 2024]);
    InvoiceSeriesControl::factory()->create(['fiscal_year' => 2023]);

    $year2024 = InvoiceSeriesControl::fiscalYear(2024)->get();

    expect($year2024)->toHaveCount(1)
        ->and($year2024->first()->fiscal_year)->toBe(2024);
});

it('can scope by serie type', function () {
    InvoiceSeriesControl::factory()->create(['serie' => InvoiceSerieType::INVOICE]);
    InvoiceSeriesControl::factory()->create(['serie' => InvoiceSerieType::PROFORMA]);

    $fiscal = InvoiceSeriesControl::serie(InvoiceSerieType::INVOICE)->get();

    expect($fiscal)->toHaveCount(1)
        ->and($fiscal->first()->serie)->toBe(InvoiceSerieType::INVOICE);
});

it('can scope by prefix', function () {
    InvoiceSeriesControl::factory()->create(['prefix' => 'INV']);
    InvoiceSeriesControl::factory()->create(['prefix' => 'PRO']);

    $invSeries = InvoiceSeriesControl::prefix('INV')->get();

    expect($invSeries)->toHaveCount(1)
        ->and($invSeries->first()->prefix)->toBe('INV');
});

it('can get next number', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'last_number' => 100,
    ]);

    expect($control->getNextNumber())->toBe(101);
});

it('calculates next number from start_number when last_number is 0', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'last_number'  => 0,
        'start_number' => 1,
    ]);

    expect($control->getNextNumber())->toBe(1);
});

it('can store validation_rules as array', function () {
    $rules = [
        'min_length' => 5,
        'max_length' => 20,
        'pattern'    => '^[A-Z]{3}',
    ];

    $control = InvoiceSeriesControl::factory()->create([
        'validation_rules' => $rules,
    ]);

    expect($control->validation_rules)->toBe($rules)
        ->and($control->validation_rules['min_length'])->toBe(5);
});

it('can have null validation_rules', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'validation_rules' => null,
    ]);

    expect($control->validation_rules)->toBeNull();
});

it('can store last_used_at timestamp', function () {
    $timestamp = now();
    $control   = InvoiceSeriesControl::factory()->create([
        'last_used_at' => $timestamp,
    ]);

    expect($control->last_used_at)->toBeInstanceOf(Carbon::class)
        ->and($control->last_used_at->format('Y-m-d H:i'))->toBe($timestamp->format('Y-m-d H:i'));
});

it('can have null last_used_at', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'last_used_at' => null,
    ]);

    expect($control->last_used_at)->toBeNull();
});

it('can store description', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'description' => 'Standard invoice series',
    ]);

    expect($control->description)->toBe('Standard invoice series');
});

it('can have null description', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'description' => null,
    ]);

    expect($control->description)->toBeNull();
});

it('can store custom number_format', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'number_format' => '{{prefix}}-{{year}}-{{number}}',
    ]);

    expect($control->number_format)->toBe('{{prefix}}-{{year}}-{{number}}');
});

it('can cast fiscal_year_start as date', function () {
    $date    = now()->startOfYear();
    $control = InvoiceSeriesControl::factory()->create([
        'fiscal_year_start' => $date,
    ]);

    expect($control->fiscal_year_start)->toBeInstanceOf(Carbon::class)
        ->and($control->fiscal_year_start->format('Y-m-d'))->toBe($date->format('Y-m-d'));
});

it('can cast fiscal_year_end as date', function () {
    $date    = now()->endOfYear();
    $control = InvoiceSeriesControl::factory()->create([
        'fiscal_year_end' => $date,
    ]);

    expect($control->fiscal_year_end)->toBeInstanceOf(Carbon::class)
        ->and($control->fiscal_year_end->format('Y-m-d'))->toBe($date->format('Y-m-d'));
});

it('can chain multiple scopes', function () {
    InvoiceSeriesControl::factory()->create([
        'prefix'      => 'INV',
        'serie'       => InvoiceSerieType::INVOICE,
        'fiscal_year' => 2024,
        'is_active'   => true,
    ]);
    InvoiceSeriesControl::factory()->create([
        'prefix'      => 'INV',
        'serie'       => InvoiceSerieType::INVOICE,
        'fiscal_year' => 2024,
        'is_active'   => false,
    ]);

    $controls = InvoiceSeriesControl::active()
        ->prefix('INV')
        ->serie(InvoiceSerieType::INVOICE)
        ->fiscalYear(2024)
        ->get();

    expect($controls)->toHaveCount(1)
        ->and($controls->first()->is_active)->toBeTrue();
});

it('can create control with reset_annually false', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'reset_annually' => false,
    ]);

    expect($control->reset_annually)->toBeFalse();
});

it('increments last_number correctly', function () {
    $control = InvoiceSeriesControl::factory()->create([
        'last_number' => 5,
    ]);

    expect($control->getNextNumber())->toBe(6)
        ->and($control->last_number)->toBe(5); // Should not modify
});

it('can have different prefixes for same serie', function () {
    InvoiceSeriesControl::factory()->create([
        'prefix' => 'A',
        'serie'  => InvoiceSerieType::INVOICE,
    ]);
    InvoiceSeriesControl::factory()->create([
        'prefix' => 'B',
        'serie'  => InvoiceSerieType::INVOICE,
    ]);

    $controls = InvoiceSeriesControl::serie(InvoiceSerieType::INVOICE)->get();

    expect($controls)->toHaveCount(2);
});

it('can have same prefix for different fiscal years', function () {
    InvoiceSeriesControl::factory()->create([
        'prefix'      => 'INV',
        'fiscal_year' => 2024,
    ]);
    InvoiceSeriesControl::factory()->create([
        'prefix'      => 'INV',
        'fiscal_year' => 2023,
    ]);

    $controls = InvoiceSeriesControl::prefix('INV')->get();

    expect($controls)->toHaveCount(2);
});

it('maintains separate counters for different series', function () {
    $control1 = InvoiceSeriesControl::factory()->create([
        'prefix'      => 'A',
        'last_number' => 10,
    ]);
    $control2 = InvoiceSeriesControl::factory()->create([
        'prefix'      => 'B',
        'last_number' => 5,
    ]);

    expect($control1->getNextNumber())->toBe(11)
        ->and($control2->getNextNumber())->toBe(6);
});
