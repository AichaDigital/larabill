<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\FiscalQrImage;

it('classifies a well-formed svg', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';

    expect(FiscalQrImage::classify($svg))->toBe('svg');
});

it('classifies an svg preceded by an xml declaration', function () {
    $svg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';

    expect(FiscalQrImage::classify($svg))->toBe('svg');
});

it('classifies a valid base64 png data uri', function () {
    // 1x1 transparent PNG
    $png = 'data:image/png;base64,'.base64_encode(
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true)
    );

    expect(FiscalQrImage::classify($png))->toBe('png');
});

it('rejects a base64 png whose payload is not a png', function () {
    $fake = 'data:image/png;base64,'.base64_encode('this is not a png');

    expect(FiscalQrImage::classify($fake))->toBeNull();
});

it('rejects invalid base64', function () {
    expect(FiscalQrImage::classify('data:image/png;base64,not!valid!base64!'))->toBeNull();
});

it('rejects a truncated or malformed svg', function () {
    expect(FiscalQrImage::classify('<svg xmlns="http://www.w3.org/2000/svg"><rect'))->toBeNull();
});

it('rejects well-formed xml whose root is not svg', function () {
    expect(FiscalQrImage::classify('<html><body/></html>'))->toBeNull();
});

it('rejects the bare AEAT cotejo url', function () {
    expect(FiscalQrImage::classify('https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=B12345678'))->toBeNull();
});

it('rejects null and empty', function () {
    expect(FiscalQrImage::classify(null))->toBeNull()
        ->and(FiscalQrImage::classify(''))->toBeNull()
        ->and(FiscalQrImage::classify('   '))->toBeNull();
});

it('rejects the removed fake connector format', function () {
    expect(FiscalQrImage::classify('QR:a1b2c3d4e5f6a7b8:eyJpbnZvaWNlX2lkIjoi'))->toBeNull();
});
