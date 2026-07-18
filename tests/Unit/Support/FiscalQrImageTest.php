<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\FiscalQrImage;

// AID-536: this suite owns the mutation testing of its subject (pest --mutate).
mutates(\AichaDigital\Larabill\Support\FiscalQrImage::class);

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

it('rejects a well-formed svg carrying an external href (AID-537)', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><image href="http://evil.example/x.png"/></svg>';

    expect(FiscalQrImage::classify($svg))->toBeNull();
});

it('rejects a well-formed svg carrying an external xlink:href (AID-537)', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="10" height="10"><image xlink:href="https://evil.example/x.png"/></svg>';

    expect(FiscalQrImage::classify($svg))->toBeNull();
});

it('rejects a well-formed svg with an external url() in styles (AID-537)', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill: url(http://evil.example/f.svg#p)" width="1" height="1"/></svg>';

    expect(FiscalQrImage::classify($svg))->toBeNull();
});

it('keeps accepting fragment and data: references (AID-537)', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs><path id="p" d="M0 0"/></defs><use href="#p"/><image xlink:href="data:image/png;base64,iVBORw0KGgo="/></svg>';

    expect(FiscalQrImage::classify($svg))->toBe('svg');
});

it('normalizes the svg root to the 35mm presentation box (AID-537)', function () {
    // The shape lara-verifactu/BaconQrCode emits: intrinsic px + viewBox.
    // dompdf renders inline SVG at its INTRINSIC size — 300px is ~79mm, double
    // the AEAT 30-40mm band (QR spec v0.4.7 arts. 20-21).
    $svg = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="300" height="300" viewBox="0 0 300 300"><rect width="300" height="300"/></svg>';

    $scaled = FiscalQrImage::atPresentationSize($svg);

    expect($scaled)->toContain('width="132"')
        ->toContain('height="132"')
        ->toContain('viewBox="0 0 300 300"')
        ->not->toContain('<?xml');
});

it('synthesizes a viewBox when the source svg lacks one (AID-537)', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"><rect width="300" height="300"/></svg>';

    $scaled = FiscalQrImage::atPresentationSize($svg);

    expect($scaled)->toContain('width="132"')
        ->toContain('height="132"')
        ->toContain('viewBox="0 0 300 300"');
});
