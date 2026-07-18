<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\PDF;

use AichaDigital\Larabill\Exceptions\FiscalContentMissingException;
use AichaDigital\Larabill\Models\Invoice;

/**
 * Post-render guard of the safe-restyle guarantee (ADR-011, AID-502).
 *
 * Asserts that every non-empty mandatory fiscal datum the data layer handed to
 * the template (RD 1619/2012 arts. 6/7) appears in the rendered HTML. It
 * guards RENDER FIDELITY — what the data says must reach the paper — never
 * data completeness: an empty datum is an emission concern and is skipped
 * here. The public contract is the exception it throws.
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class FiscalContentValidator
{
    /**
     * Expedition-date renderings a template may legitimately choose between.
     * A format outside this set is a rewrite, which the contract disallows.
     */
    private const DATE_FORMATS = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y'];

    /**
     * @param  array<string, mixed>  $templateData  The exact array the template received
     *
     * @throws FiscalContentMissingException
     */
    public function validate(Invoice $invoice, array $templateData, string $html): void
    {
        $haystack = $this->normalize($html);
        $missing  = [];

        foreach ($this->mandatoryContent($invoice, $templateData) as $field => $needles) {
            if ($needles === []) {
                continue; // Empty datum in the data layer: emission's concern, not render's.
            }

            if (! $this->anyPresent($haystack, $needles)) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw FiscalContentMissingException::forInvoice($invoice, $missing);
        }
    }

    /**
     * The mandatory slots and their accepted renderings, derived from the SAME
     * data the template received. Each slot maps to a list of needles of which
     * at least ONE must appear (dates tolerate several formats; everything
     * else is verbatim).
     *
     * @param  array<string, mixed>  $templateData
     * @return array<string, list<string>>
     */
    protected function mandatoryContent(Invoice $invoice, array $templateData): array
    {
        $content = [
            'fiscal_number' => $this->needle($invoice->fiscal_number),
            'invoice_date'  => $invoice->invoice_date === null
                ? []
                : array_map(fn (string $format): string => $invoice->invoice_date->format($format), self::DATE_FORMATS),
            'operation_date' => $this->needle($templateData['operation_date'] ?? null),
            'issuer.name'    => $this->needle($templateData['company']['name'] ?? null),
            'issuer.tax_id'  => $this->needle($templateData['company']['tax_id'] ?? null),
        ];

        // Simplified invoices identify no recipient (RD 1619/2012 art. 7).
        if ($invoice->serie->requiresFullCustomerData()) {
            $content['customer.name']   = $this->needle($templateData['client']['name'] ?? null);
            $content['customer.tax_id'] = $this->needle($templateData['client']['tax_id'] ?? null);
        }

        // Per line: the description and the unit price without tax are the
        // named per-operation mandata (art. 6.1.f).
        foreach ($templateData['items'] ?? [] as $index => $item) {
            $content["items.{$index}.description"] = $this->needle($item['description'] ?? null);
            $content["items.{$index}.unit_price"]  = $this->needle($item['unit_price'] ?? null);
        }

        $content['totals.subtotal'] = $this->needle($templateData['totals']['subtotal'] ?? null);
        $content['totals.total']    = $this->needle($templateData['totals']['total'] ?? null);

        // The per-rate breakdown (art. 6.1.g/h): every row, all three figures.
        foreach ($templateData['totals']['tax_breakdown'] ?? [] as $index => $row) {
            foreach (['rate', 'base', 'amount'] as $figure) {
                $content["totals.tax_breakdown.{$index}.{$figure}"] = $this->needle($row[$figure] ?? null);
            }
        }

        return $content;
    }

    /**
     * A single verbatim needle, or none when the datum is empty.
     *
     * @return list<string>
     */
    private function needle(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return [$value];
    }

    /**
     * @param  list<string>  $needles
     */
    private function anyPresent(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Text as a reader of the PDF would see it: tags removed, entities
     * decoded, whitespace collapsed. Needles get the same treatment so values
     * split across inline markup or reflowed by the template still match.
     */
    private function normalize(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
