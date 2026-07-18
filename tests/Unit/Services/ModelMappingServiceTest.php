<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\ModelMappingService;
use AichaDigital\Larabill\Tests\Models\CustomUser;
use AichaDigital\Larabill\Tests\Models\TestUser;

/*
 * AID-553 — user model resolution chain.
 *
 * The 'user' mapping resolves `larabill.models.user` (explicit override)
 * → `larabill.user_model` (canonical key, the one TestCase and the rest of
 * the package configure) → a loud RuntimeException. The historical silent
 * fallback to a tests-namespace class must never come back: it does not
 * autoload in production and made relations resolve a different table than
 * the configured user model, silently skipping customer_snapshot generation.
 */

test('user mapping resolves an explicitly configured models.user class', function () {
    config()->set('larabill.models.user', CustomUser::class);

    expect(ModelMappingService::getModelClass('user'))->toBe(CustomUser::class);
});

test('user mapping falls back to larabill.user_model when models.user is not set', function () {
    config()->set('larabill.models.user', null);

    expect(ModelMappingService::getModelClass('user'))->toBe(TestUser::class);
});

test('user mapping falls back to larabill.user_model when models.user is not an existing class', function () {
    config()->set('larabill.models.user', 'App\\Models\\NonExistentUser');

    expect(ModelMappingService::getModelClass('user'))->toBe(TestUser::class);
});

test('user mapping fails loudly when neither models.user nor user_model resolve', function () {
    config()->set('larabill.models.user', null);
    config()->set('larabill.user_model', null);

    expect(fn () => ModelMappingService::getModelClass('user'))
        ->toThrow(RuntimeException::class, 'larabill.user_model');
});

test('invoice relations resolve the same user model as larabill.user_model in the default suite environment', function () {
    expect(ModelMappingService::getModelClass('user'))->toBe(config('larabill.user_model'))
        ->and((new Invoice)->user()->getRelated())->toBeInstanceOf(TestUser::class)
        ->and((new Invoice)->billableUser()->getRelated())->toBeInstanceOf(TestUser::class);
});

test('package model types keep their package defaults', function () {
    config()->set('larabill.models.invoice', null);
    config()->set('larabill.models.company_fiscal_config', null);

    expect(ModelMappingService::getModelClass('invoice'))->toBe(Invoice::class)
        ->and(ModelMappingService::getModelClass('company_fiscal_config'))->toBe(CompanyFiscalConfig::class);
});

test('shipped config models block does not carry the user key nor ADR-003 leftovers', function () {
    // 'user' must not ship in the models block: a published copy would shadow
    // larabill.user_model / LARABILL_USER_MODEL (models.user wins when it points
    // to an existing class, e.g. App\Models\User). 'customer' and
    // 'customer_fiscal_data' reference models removed by ADR-003.
    $shipped = require dirname(__DIR__, 3).'/config/larabill.php';

    expect($shipped['models'])
        ->not->toHaveKey('user')
        ->not->toHaveKey('customer')
        ->not->toHaveKey('customer_fiscal_data');
});

test('unknown model type throws an InvalidArgumentException', function () {
    expect(fn () => ModelMappingService::getModelClass('nonexistent'))
        ->toThrow(InvalidArgumentException::class, 'Unknown model type');
});

arch('production code must not depend on the tests namespace')
    ->expect('AichaDigital\Larabill')
    ->not->toUse('AichaDigital\Larabill\Tests');
