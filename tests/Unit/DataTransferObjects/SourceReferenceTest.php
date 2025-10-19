<?php

declare(strict_types=1);

use AichaDigital\Larabill\DataTransferObjects\SourceReference;

it('can create a source reference DTO', function () {
    $dto = new SourceReference(
        type: 'article_service',
        articleId: 123,
        serviceStatusId: 456,
        instanceIdentifier: 'example.com',
        additional: ['key' => 'value']
    );

    expect($dto->type)->toBe('article_service')
        ->and($dto->articleId)->toBe(123)
        ->and($dto->serviceStatusId)->toBe(456)
        ->and($dto->instanceIdentifier)->toBe('example.com')
        ->and($dto->additional)->toBe(['key' => 'value']);
});

it('can create source reference from array', function () {
    $data = [
        'type'                => 'article_service',
        'article_id'          => 123,
        'service_status_id'   => 456,
        'instance_identifier' => 'example.com',
        'additional'          => ['key' => 'value'],
    ];

    $dto = SourceReference::fromArray($data);

    expect($dto->type)->toBe('article_service')
        ->and($dto->articleId)->toBe(123)
        ->and($dto->serviceStatusId)->toBe(456)
        ->and($dto->instanceIdentifier)->toBe('example.com')
        ->and($dto->additional)->toBe(['key' => 'value']);
});

it('can convert source reference to array', function () {
    $dto = new SourceReference(
        type: 'article_service',
        articleId: 123,
        serviceStatusId: 456,
        instanceIdentifier: 'example.com',
        additional: ['key' => 'value']
    );

    $array = $dto->toArray();

    expect($array)->toBe([
        'type'                => 'article_service',
        'article_id'          => 123,
        'service_status_id'   => 456,
        'instance_identifier' => 'example.com',
        'additional'          => ['key' => 'value'],
    ]);
});

it('handles null values in source reference', function () {
    $dto = new SourceReference(type: 'manual');

    expect($dto->type)->toBe('manual')
        ->and($dto->articleId)->toBeNull()
        ->and($dto->serviceStatusId)->toBeNull()
        ->and($dto->instanceIdentifier)->toBeNull()
        ->and($dto->additional)->toBeNull();
});
