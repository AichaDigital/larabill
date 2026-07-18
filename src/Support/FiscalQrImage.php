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
     * 35mm at dompdf's 96dpi (35 × 96 / 25.4 ≈ 132.28) — the AEAT QR spec
     * v0.4.7 (arts. 20-21) requires 30-40mm printed. Inline SVG renders at
     * its INTRINSIC size in dompdf; the template's 35mm wrapper div does not
     * rescale it (lara-verifactu emits 300px ≈ 79mm — double the band).
     */
    private const PRESENTATION_PX = 132;

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

        if (! $wellFormed || $document->documentElement?->localName !== 'svg') {
            return false;
        }

        return ! self::carriesExternalReferences($document);
    }

    /**
     * AID-537: a WELL-FORMED svg can still exfiltrate — the value is inlined
     * raw in the template and dompdf runs with isRemoteEnabled. Anything
     * referencing outside the document (href/xlink:href with a scheme, path
     * or protocol-relative target, url(...) in styles) disqualifies it;
     * fragment (#id) and data: references stay. Defense-in-depth: reaching
     * this column already requires database write access.
     */
    private static function carriesExternalReferences(DOMDocument $document): bool
    {
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach (['href', 'xlink:href'] as $name) {
                if (! $element->hasAttribute($name)) {
                    continue;
                }

                $target = trim($element->getAttribute($name));

                if ($target !== ''
                    && ! str_starts_with($target, '#')
                    && ! str_starts_with(strtolower($target), 'data:')) {
                    return true;
                }
            }

            $style = $element->localName === 'style'
                ? $element->textContent
                : $element->getAttribute('style');

            if ($style !== '' && preg_match('/url\s*\(\s*["\']?\s*(?!["\']?\s*#|["\']?\s*data:)/i', $style) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rewrite the svg root to the 35mm presentation box (AID-537).
     *
     * Keeps (or synthesizes, from the intrinsic width/height) the viewBox so
     * the drawing scales instead of cropping, and drops the XML declaration —
     * the value is inlined inside an HTML template. Returns the input
     * untouched when it cannot be parsed; callers only reach this with a
     * value classify() already accepted.
     */
    public static function atPresentationSize(string $svg): string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded   = $document->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        if (! $loaded || $root === null || $root->localName !== 'svg') {
            return $svg;
        }

        if (! $root->hasAttribute('viewBox')) {
            $width  = (float) $root->getAttribute('width');
            $height = (float) $root->getAttribute('height');

            if ($width > 0 && $height > 0) {
                $root->setAttribute('viewBox', sprintf('0 0 %s %s', rtrim(rtrim(number_format($width, 2, '.', ''), '0'), '.'), rtrim(rtrim(number_format($height, 2, '.', ''), '0'), '.')));
            }
        }

        $root->setAttribute('width', (string) self::PRESENTATION_PX);
        $root->setAttribute('height', (string) self::PRESENTATION_PX);

        $rendered = $document->saveXML($root);

        return $rendered === false ? $svg : $rendered;
    }
}
