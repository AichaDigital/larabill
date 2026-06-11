<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\Adapters;

use AichaDigital\Larabill\Models\Invoice;

/**
 * VerifactuAdapter
 *
 * Adapter to convert Larabill Invoice (base100 integers) to Lara-Verifactu format (decimal:2).
 * This adapter ensures proper conversion of monetary values for AEAT XML generation.
 *
 * Targets lara-verifactu >= 0.9: consolidated `issue_datetime` column and
 * per-tax-rate breakdowns (AEAT Desglose), not per-line-item rows.
 *
 * @see https://github.com/AichaDigital/lara-verifactu
 */
class VerifactuAdapter
{
    /**
     * AEAT tax type for VAT (TaxTypeEnum::IVA). Larabill only models VAT today;
     * IGIC/IPSI/IRPF mapping will require carrying the tax type in taxes_applied.
     */
    private const TAX_TYPE_VAT = '01';

    /**
     * Convert a Larabill Invoice to Verifactu-compatible array.
     *
     * Converts base100 integers to decimal(2) format for AEAT XML.
     * Uses encrypted snapshots for fiscal data (ADR-001 architecture).
     *
     * @param  Invoice  $invoice  The Larabill invoice to convert
     * @return array<string, mixed> Verifactu-compatible invoice data
     */
    public static function toVerifactuInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('billableUser');

        // Get customer fiscal data from the encrypted snapshot (ADR-001)
        $customerData = $invoice->getCustomerSnapshotData() ?? [];

        // Determine if invoice is simplified (based on amount threshold or explicit flag)
        $isSimplified = self::isSimplifiedInvoice($invoice);

        return [
            'serie'              => $invoice->serie->value ?? null,
            'number'             => (string) $invoice->series_number,
            'issue_datetime'     => $invoice->issued_at ?? $invoice->invoice_date,
            'type'               => self::mapInvoiceType($invoice),
            'simplified'         => $isSimplified,
            'rectification_type' => self::getRectificationType($invoice),
            'base_amount'        => self::base100ToDecimal((int) $invoice->taxable_amount),
            'tax_amount'         => self::base100ToDecimal((int) ($invoice->total_tax_amount ?? 0)),
            'total_amount'       => self::base100ToDecimal((int) $invoice->total_amount),
            'currency'           => 'EUR',
            'recipient_nif'      => $customerData['tax_id'] ?? null,
            'recipient_id_type'  => self::mapRecipientIdType($invoice, $customerData),
            'recipient_id'       => $customerData['tax_id']        ?? null,
            'recipient_name'     => $customerData['customer_name'] ?? $invoice->billableUser?->display_name ?? $invoice->billableUser?->name ?? null,
            'recipient_country'  => $customerData['country_code']  ?? 'ES',
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
     * Determine if invoice is simplified (factura simplificada).
     *
     * In Spain, simplified invoices are allowed for amounts under 400€ (or 3000€ in some cases).
     */
    private static function isSimplifiedInvoice(Invoice $invoice): bool
    {
        // Check if total amount is under 400€ (40000 in base100)
        $simplifiedThreshold = 40000;

        return (int) $invoice->total_amount < $simplifiedThreshold;
    }

    /**
     * Get rectification type for rectificative invoices.
     */
    private static function getRectificationType(Invoice $invoice): ?string
    {
        if ($invoice->rectifies_invoice_id === null) {
            return null;
        }

        // R1: Por diferencias (most common)
        // R2: Por sustitución
        // R3: Por corrección de errores registrales
        // R4: Por corrección de errores de facturación
        // R5: Por devolución de productos

        return 'R1';
    }

    /**
     * Group invoice items into AEAT tax breakdowns (one row per tax rate).
     *
     * AEAT Desglose aggregates by tax type and rate; items without any tax
     * applied are merged into a single exempt row.
     *
     * @param  Invoice  $invoice  The Larabill invoice
     * @return array<int, array{tax_type: string, tax_rate: float, base_amount: float, tax_amount: float, exempt: bool, exemption_reason: string|null}>
     */
    public static function toVerifactuBreakdowns(Invoice $invoice): array
    {
        $invoice->loadMissing('items');

        /** @var array<int, array{base: int, tax: int}> $groups keyed by rate in base10000 */
        $groups     = [];
        $exemptBase = 0;

        foreach ($invoice->items as $item) {
            $taxes = $item->taxes_applied ?? [];

            if ($taxes === []) {
                $exemptBase += (int) $item->taxable_amount;

                continue;
            }

            foreach ($taxes as $tax) {
                $rate = (int) $tax['rate'];

                $groups[$rate]['base'] = ($groups[$rate]['base'] ?? 0) + (int) $item->taxable_amount;
                $groups[$rate]['tax']  = ($groups[$rate]['tax'] ?? 0)  + (int) ($tax['amount'] ?? 0);
            }
        }

        $breakdowns = [];

        foreach ($groups as $rate => $group) {
            $breakdowns[] = [
                'tax_type'         => self::TAX_TYPE_VAT,
                'tax_rate'         => self::base10000ToDecimal($rate),
                'base_amount'      => self::base100ToDecimal($group['base']),
                'tax_amount'       => self::base100ToDecimal($group['tax']),
                'exempt'           => false,
                'exemption_reason' => null,
            ];
        }

        if ($exemptBase > 0) {
            $breakdowns[] = [
                'tax_type'         => self::TAX_TYPE_VAT,
                'tax_rate'         => 0.0,
                'base_amount'      => self::base100ToDecimal($exemptBase),
                'tax_amount'       => 0.0,
                'exempt'           => true,
                'exemption_reason' => null, // XmlBuilder defaults to E1; refine per-invoice when needed
            ];
        }

        return $breakdowns;
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

        return self::isSimplifiedInvoice($invoice) ? 'F2' : 'F1';
    }

    /**
     * Map Larabill customer ID type to Verifactu IdTypeEnum.
     *
     * @param  Invoice  $invoice  The Larabill invoice
     * @param  array<string, mixed>  $customerData  Customer fiscal data from snapshot
     * @return string Verifactu ID type (02=NIF, 03=Pasaporte, etc.)
     */
    private static function mapRecipientIdType(Invoice $invoice, array $customerData = []): string
    {
        $countryCode = $customerData['country_code'] ?? 'ES';

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
            return '04'; // OperationTypeEnum::REVERSE_CHARGE (inversión del sujeto pasivo)
        }

        return '01'; // Operación de régimen general
    }
}
