<?php

declare(strict_types=1);

use AichaDigital\Larabill\DataTransferObjects\CustomData;

it('can create a custom data DTO', function () {
    $dto = new CustomData(data: ['key1' => 'value1', 'key2' => 'value2']);

    expect($dto->data)->toBe(['key1' => 'value1', 'key2' => 'value2']);
});

it('can create custom data from array', function () {
    $data = ['key1' => 'value1', 'nested' => ['key2' => 'value2']];

    $dto = CustomData::fromArray($data);

    expect($dto->data)->toBe($data);
});

it('can get a value from custom data', function () {
    $dto = new CustomData(data: ['key1' => 'value1', 'nested' => ['key2' => 'value2']]);

    expect($dto->get('key1'))->toBe('value1')
        ->and($dto->get('nested.key2'))->toBe('value2')
        ->and($dto->get('missing', 'default'))->toBe('default');
});

it('can check if a key exists', function () {
    $dto = new CustomData(data: ['key1' => 'value1', 'nested' => ['key2' => 'value2']]);

    expect($dto->has('key1'))->toBeTrue()
        ->and($dto->has('nested'))->toBeTrue()
        ->and($dto->has('missing'))->toBeFalse();
});

it('can convert custom data to array', function () {
    $data = ['key1' => 'value1', 'nested' => ['key2' => 'value2']];
    $dto  = new CustomData(data: $data);

    expect($dto->toArray())->toBe($data);
});

it('handles empty custom data', function () {
    $dto = new CustomData;

    expect($dto->data)->toBe([])
        ->and($dto->toArray())->toBe([]);
});
