<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use DOMDocument;

/**
 * Structural validation of the fiscal QR persisted on `invoices.fiscal_verification_qr`.
 *
 * larabill does NOT generate QR codes: it consumes what the fiscal registration
 * persisted. But it does check that the value is an image it can hand to dompdf,
 * because a recognised prefix is not a usable image — `data:image/png;base64,garbage`
 * would render nothing while the service reported success (AID-508).
 *
 * Scope: structure only. Fiscal content, scannability and the encoded cotejo URL
 * belong to the producer (lara-verifactu, per the AEAT QR spec v0.4.7). This class
 * cannot defer to that producer: it is optional, and the column may also be written
 * by another integration or hold historical data.
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
final class FiscalQrImage
{
    private const PNG_DATA_URI_PREFIX = 'data:image/png;base64,';

    /** PNG signature: \x89PNG\r\n\x1a\n */
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Classify the persisted value as a usable image.
     *
     * @return string|null 'svg', 'png', or null when the value is unusable
     *                     (absent, a bare URL, an unknown format, invalid
     *                     base64, or malformed XML). Callers treat null as
     *                     absence.
     */
    public static function classify(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, self::PNG_DATA_URI_PREFIX)) {
            return self::isValidPng(substr($value, strlen(self::PNG_DATA_URI_PREFIX))) ? 'png' : null;
        }

        return self::isValidSvg($value) ? 'svg' : null;
    }

    private static function isValidPng(string $base64): bool
    {
        $binary = base64_decode($base64, true);

        return $binary !== false && str_starts_with($binary, self::PNG_SIGNATURE);
    }

    private static function isValidSvg(string $value): bool
    {
        // Strip a leading XML declaration: lara-verifactu emits the SVG with one.
        $candidate = preg_replace('/^<\?xml[^>]*\?>\s*/i', '', $value) ?? $value;

        if (! str_starts_with(ltrim($candidate), '<svg')) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;

        // LIBXML_NONET: never resolve external entities over the network. The value
        // is untrusted input that ends up inlined raw in the template, and dompdf
        // runs with isRemoteEnabled.
        $wellFormed = $document->loadXML($value, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $wellFormed && $document->documentElement?->localName === 'svg';
    }
}
