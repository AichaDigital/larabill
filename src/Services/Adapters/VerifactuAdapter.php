<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\Adapters;

use AichaDigital\Larabill\Models\{Invoice, InvoiceItem};

/**
 * VerifactuAdapter
 *
 * Adapter to convert Larabill Invoice (base100 integers) to Lara-Verifactu format (decimal:2).
 * This adapter ensures proper conversion of monetary values for AEAT XML generation.
 *
 * @see https://github.com/AichaDigital/lara-verifactu
 */
class VerifactuAdapter
{
    /**
     * Convert a Larabill Invoice to Verifactu-compatible array.
     *
     * Converts base100 integers to decimal(2) format for AEAT XML.
     *
     * @param  Invoice  $invoice  The Larabill invoice to convert
     * @return array<string, mixed> Verifactu-compatible invoice data
     */
    public static function toVerifactuInvoice(Invoice $invoice): array
    {
        // Refresh invoice to ensure relationships are loaded
        $invoice = $invoice->fresh(['taxProfile', 'customer']);

        return [
            'serie'              => $invoice->serie->value ?? null,
            'number'             => (string) $invoice->series_number,
            'issue_date'         => $invoice->invoice_date,
            'issue_time'         => $invoice->invoice_date,
            'type'               => self::mapInvoiceType($invoice),
            'simplified'         => $invoice->is_simplified      ?? false,
            'rectification_type' => $invoice->rectification_type ?? null,
            'base_amount'        => self::base100ToDecimal($invoice->taxable_amount),
            'tax_amount'         => self::base100ToDecimal($invoice->total_tax_amount),
            'total_amount'       => self::base100ToDecimal($invoice->total_amount),
            'currency'           => 'EUR',
            'recipient_nif'      => $invoice->taxProfile->tax_code ?? null,
            'recipient_id_type'  => self::mapRecipientIdType($invoice),
            'recipient_id'       => $invoice->taxProfile->tax_code     ?? null,
            'recipient_name'     => $invoice->customer->display_name   ?? null,
            'recipient_country'  => $invoice->taxProfile->country_code ?? 'ES',
            'regime_type'        => self::mapRegimeType($invoice),
            'operation_key'      => self::mapOperationKey($invoice),
            'description'        => $invoice->notes ?? null,
            'metadata'           => [
                'larabill_invoice_id' => $invoice->id,
                'is_roi_taxed'        => $invoice->is_roi_taxed,
            ],
        ];
    }

    /**
     * Convert a Larabill InvoiceItem to Verifactu breakdown format.
     *
     * @param  InvoiceItem  $item  The invoice item to convert
     * @return array<string, mixed> Verifactu-compatible breakdown data
     */
    public static function toVerifactuBreakdown(InvoiceItem $item): array
    {
        return [
            'description'  => $item->description ?? 'Item',
            'quantity'     => self::base100ToDecimal($item->quantity),
            'unit_price'   => self::base100ToDecimal($item->unit_price),
            'base_amount'  => self::base100ToDecimal($item->taxable_amount),
            'tax_rate'     => self::base10000ToDecimal($item->tax_rate ?? 0),
            'tax_amount'   => self::base100ToDecimal($item->total_tax_amount),
            'total_amount' => self::base100ToDecimal($item->total_amount),
        ];
    }

    /**
     * Convert base100 integer to decimal (2 decimals).
     *
     * @param  int  $base100  Value in base100 (e.g., 1234 = €12.34)
     * @return float Decimal value (e.g., 12.34)
     */
    public static function base100ToDecimal(int $base100): float
    {
        return round($base100 / 100, 2);
    }

    /**
     * Convert base10000 integer to decimal (2 decimals) for tax rates.
     *
     * @param  int  $base10000  Value in base10000 (e.g., 2100 = 21.00%)
     * @return float Decimal percentage (e.g., 21.00)
     */
    public static function base10000ToDecimal(int $base10000): float
    {
        return round($base10000 / 100, 2);
    }

    /**
     * Map Larabill invoice type to Verifactu InvoiceTypeEnum.
     *
     * @param  Invoice  $invoice  The Larabill invoice
     * @return string Verifactu invoice type (F1, F2, F3, R1-R5)
     */
    private static function mapInvoiceType(Invoice $invoice): string
    {
        // F1: Factura completa
        // F2: Factura simplificada
        // F3: Factura emitida en sustitución de facturas simplificadas
        // R1-R5: Rectificativas
        if ($invoice->rectifies_invoice_id !== null) {
            return 'R1'; // Por diferencias (más común)
        }

        return ($invoice->is_simplified ?? false) ? 'F2' : 'F1';
    }

    /**
     * Map Larabill customer ID type to Verifactu IdTypeEnum.
     *
     * @param  Invoice  $invoice  The Larabill invoice
     * @return string|null Verifactu ID type (02=NIF, 03=Pasaporte, etc.)
     */
    private static function mapRecipientIdType(Invoice $invoice): ?string
    {
        $countryCode = $invoice->taxProfile->country_code ?? 'ES';

        // 02: NIF (España)
        // 03: Pasaporte
        // 04: Doc oficial país residencia
        // 05: Cert residencia fiscal
        // 06: Otro doc probatorio
        // 07: No censado (sin documento)

        if ($countryCode === 'ES') {
            return '02'; // NIF
        }

        // Para intracomunitarios (EU), usar código 04
        if (in_array($countryCode, ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'])) {
            return '04'; // Doc oficial país residencia
        }

        return '03'; // Pasaporte (resto del mundo)
    }

    /**
     * Map Larabill invoice to Verifactu RegimeTypeEnum.
     *
     * @param  Invoice  $invoice  The Larabill invoice
     * @return string Verifactu regime type (01=General, 02=Exportación, etc.)
     */
    private static function mapRegimeType(Invoice $invoice): string
    {
        // 01: Régimen general
        // 02: Exportación
        // 03: Régimen especial usado/oro/antigüedades
        // 04: Régimen especial agencias viaje
        // 05: Régimen especial entregas oro inversión
        // 06: Régimen especial agencias en nombre propio

        // Para este MVP, siempre régimen general
        return '01';
    }

    /**
     * Map Larabill invoice to Verifactu OperationTypeEnum.
     *
     * @param  Invoice  $invoice  The Larabill invoice
     * @return string Verifactu operation key
     */
    private static function mapOperationKey(Invoice $invoice): string
    {
        // 01: Operación de régimen general (por defecto)
        // 02: Exportación
        // 03: Operaciones a las que se aplique el régimen especial...
        // ... (muchas más, ver AEAT docs)

        if ($invoice->is_roi_taxed) {
            return '09'; // Inversión del sujeto pasivo (reverse charge)
        }

        return '01'; // Operación de régimen general
    }
}
