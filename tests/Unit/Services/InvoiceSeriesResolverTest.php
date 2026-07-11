<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Services\InvoiceSeriesResolver;

/*
 * InvoiceSeriesResolver — single source of truth for the fiscal SERIES
 * (AID-307). Covers the full resolution cascade and the v4→v5 backward
 * compatibility path (a legacy config with no `series` map must still resolve
 * to the historical FAC/PRO defaults with no consumer action).
 */

beforeEach(function () {
    $this->resolver = new InvoiceSeriesResolver;
});

it('resolves the configured series per fiscal type', function () {
    config()->set('larabill.invoice_numbering.series', [
        'invoice'       => 'FAC',
        'proforma'      => 'PRO',
        'rectificative' => 'RECT',
        'simplified'    => 'TIK',
    ]);

    expect($this->resolver->resolve(InvoiceSerieType::INVOICE))->toBe('FAC')
        ->and($this->resolver->resolve(InvoiceSerieType::PROFORMA))->toBe('PRO')
        ->and($this->resolver->resolve(InvoiceSerieType::RECTIFICATIVE))->toBe('RECT')
        ->and($this->resolver->resolve(InvoiceSerieType::SIMPLIFIED))->toBe('TIK');
});

it('honours a configured custom series for a fiscal type', function () {
    config()->set('larabill.invoice_numbering.series.invoice', 'EXPORT');

    expect($this->resolver->resolve(InvoiceSerieType::INVOICE))->toBe('EXPORT');
});

it('lets the caller request an explicit series (multi-series lever)', function () {
    config()->set('larabill.invoice_numbering.series.invoice', 'FAC');

    expect($this->resolver->resolve(InvoiceSerieType::INVOICE, 'ARB'))->toBe('ARB');
});

it('treats a blank explicit request as no request and falls through to config', function () {
    config()->set('larabill.invoice_numbering.series.invoice', 'FAC');

    expect($this->resolver->resolve(InvoiceSerieType::INVOICE, '   '))->toBe('FAC');
});

it('falls back to legacy prefix config when the series map is absent (v4 upgrade)', function () {
    // Simulate a v4 consumer's config: no `series` map, only the legacy keys.
    config()->set('larabill.invoice_numbering.series', null);
    config()->set('larabill.invoice_numbering.invoice_prefix', 'FAC');
    config()->set('larabill.invoice_numbering.proforma_prefix', 'PRO');

    expect($this->resolver->resolve(InvoiceSerieType::INVOICE))->toBe('FAC')
        ->and($this->resolver->resolve(InvoiceSerieType::PROFORMA))->toBe('PRO');
});

it('falls back to the enum default when neither series map nor legacy key exists', function () {
    // No config at all for series → the built-in defaults answer, including the
    // types that never had a legacy key (simplified, rectificative).
    config()->set('larabill.invoice_numbering.series', null);
    config()->set('larabill.invoice_numbering.invoice_prefix', null);
    config()->set('larabill.invoice_numbering.proforma_prefix', null);

    expect($this->resolver->resolve(InvoiceSerieType::INVOICE))->toBe('FAC')
        ->and($this->resolver->resolve(InvoiceSerieType::PROFORMA))->toBe('PRO')
        ->and($this->resolver->resolve(InvoiceSerieType::SIMPLIFIED))->toBe('TIK')
        ->and($this->resolver->resolve(InvoiceSerieType::RECTIFICATIVE))->toBe('RECT');
});

it('accepts a series exactly at the AEAT-derived 50-char limit (AID-429)', function () {
    // 50 = NumSerieFactura maxLength (60, XSD TextoIDFacturaType) − 10-digit
    // worst-case unpadded correlative appended by the adapter.
    $atLimit = str_repeat('S', 50);

    expect($this->resolver->resolve(InvoiceSerieType::INVOICE, $atLimit))->toBe($atLimit);
});

it('rejects an explicitly requested series longer than the column width', function () {
    expect(fn () => $this->resolver->resolve(InvoiceSerieType::INVOICE, str_repeat('S', 51)))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts real-world multi-word series literals the old 10-char cap rejected', function () {
    // The AID-429 motivating case: per-installation rectificative series.
    expect($this->resolver->resolve(InvoiceSerieType::RECTIFICATIVE, 'RECT-CASTRIS'))->toBe('RECT-CASTRIS');
});
