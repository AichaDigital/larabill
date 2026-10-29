<?php

declare(strict_types=1);

use AichaDigital\Larabill\Database\Seeders\LegalEntityTypesSeeder;
use AichaDigital\Larabill\Database\Seeders\TaxGroupSeeder;
use AichaDigital\Larabill\Database\Seeders\TaxRateSeeder;
use AichaDigital\Larabill\Database\Seeders\TaxRatesSeeder;
use AichaDigital\Larabill\Models\LegalEntityType;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;

/*
 * AID-1819 — the seeders ship in src/ and consumers run them on install, yet
 * no test executed them. Their contract: re-running is a no-op (they key on
 * firstOrCreate/updateOrCreate), every seeded rate is a base-100 percentage
 * in [0, 100 %] (zero is a valid exempt rate: IPSI Ceuta/Melilla), and
 * TaxGroupSeeder links the rates it names — it silently skips a missing
 * rate, so a renamed rate would leave an empty group.
 */

dataset('rate seeders', [
    'TaxRateSeeder'  => [TaxRateSeeder::class],
    'TaxRatesSeeder' => [TaxRatesSeeder::class],
]);

it('seeds base-100 rates idempotently', function (string $seeder) {
    (new $seeder)->run();
    $count = TaxRate::query()->count();

    (new $seeder)->run();

    expect($count)->toBeGreaterThan(0)
        ->and(TaxRate::query()->count())->toBe($count);

    foreach (TaxRate::query()->toBase()->pluck('rate', 'name') as $name => $rate) {
        expect((int) $rate)->toBeGreaterThanOrEqual(0, "{$name}")->toBeLessThanOrEqual(10000, "{$name}");
    }
})->with('rate seeders');

it('links every tax group to the rates it names, idempotently', function () {
    (new TaxRateSeeder)->run();
    (new TaxGroupSeeder)->run();
    (new TaxGroupSeeder)->run();

    $groups = TaxGroup::query()->with('taxRates')->get();

    expect($groups)->toHaveCount(6);
    foreach ($groups as $group) {
        expect($group->taxRates)->not->toBeEmpty("Tax group [{$group->name}] has no rates.");
    }

    expect(TaxGroup::query()->where('name', 'Venta Estándar Boston')->sole()->taxRates->pluck('name')->all())
        ->toBe(['MA State Sales Tax', 'Boston City Surcharge']);
});

it('seeds unique Spanish legal entity types idempotently', function () {
    (new LegalEntityTypesSeeder)->run();
    $codes = LegalEntityType::query()->pluck('code')->all();

    (new LegalEntityTypesSeeder)->run();

    expect($codes)->not->toBeEmpty()
        ->and($codes)->toContain('INDIVIDUAL')
        ->and(array_unique($codes))->toHaveCount(count($codes))
        ->and(LegalEntityType::query()->count())->toBe(count($codes));
});
