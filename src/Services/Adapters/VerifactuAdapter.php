<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\Adapters;

use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\LaraVerifactu\Enums\CalificacionOperacionEnum;
use Illuminate\Validation\ValidationException;

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
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class VerifactuAdapter
{
    /**
     * AEAT tax type for VAT (TaxTypeEnum::IVA). Larabill only models VAT today;
     * IGIC/IPSI/IRPF mapping will require carrying the tax type in taxes_applied.
     */
    private const TAX_TYPE_VAT = '01';

    /**
     * EU member-state ISO country codes. Used to decide intra-community treatment
     * from the immutable snapshots (issuer/customer country), never from live config.
     */
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR',
        'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
        'SE', 'SI', 'SK',
    ];

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

        // Simplified (F2) only when no recipient is identified (AEAT rule 1190)
        $isSimplified = self::isSimplifiedInvoice($customerData);

        $recipientCountry = $customerData['country_code'] ?? 'ES';

        return [
            // InvoiceSerieType is int-backed; cast to string for the verifactu
            // ?string serie contract (raw int breaks getSerie() during XML build).
            'serie'              => $invoice->serie === null ? null : (string) $invoice->serie->value,
            'number'             => (string) $invoice->series_number,
            'issue_datetime'     => $invoice->issued_at ?? $invoice->invoice_date,
            'type'               => self::mapInvoiceType($invoice, $isSimplified),
            'rectification_type' => self::getRectificationType($invoice),
            'base_amount'        => self::base100ToDecimal($invoice->taxable_amount->unscaledValue()),
            'tax_amount'         => self::base100ToDecimal($invoice->total_tax_amount?->unscaledValue() ?? 0),
            'total_amount'       => self::base100ToDecimal($invoice->total_amount->unscaledValue()),
            'currency'           => 'EUR',
            // AEAT rule 1100: <NIF> is for Spanish tax IDs only. A foreign recipient
            // carries its identifier through IDOtro (recipient_id + recipient_id_type),
            // so recipient_nif must be null for non-ES recipients.
            'recipient_nif'      => $recipientCountry === 'ES' ? ($customerData['tax_id'] ?? null) : null,
            'recipient_id_type'  => self::mapRecipientIdType($customerData),
            'recipient_id'       => $customerData['tax_id']        ?? null,
            'recipient_name'     => $customerData['customer_name'] ?? $invoice->billableUser?->display_name ?? $invoice->billableUser?->name ?? null,
            'recipient_country'  => $recipientCountry,
            'regime_type'        => self::mapRegimeType($invoice),
            'description'        => $invoice->notes ?? null,
            'metadata'           => array_filter([
                'larabill_invoice_id' => $invoice->id,
                'is_roi_taxed'        => $invoice->is_roi_taxed,
                'rectified_invoices'  => self::rectifiedInvoiceReferences($invoice),
            ], fn ($value) => $value !== []),
        ];
    }

    /**
     * Determine if invoice is simplified (factura simplificada).
     *
     * AEAT validation 1190: an F2 record must not carry a Destinatarios
     * block, so any invoice with an identified recipient is F1 regardless
     * of amount; F2 is reserved for invoices without recipient data.
     *
     * @param  array<string, mixed>  $customerData  Customer fiscal data from snapshot
     */
    private static function isSimplifiedInvoice(array $customerData): bool
    {
        return empty($customerData['tax_id']);
    }

    /**
     * Get rectification type for rectificative invoices.
     *
     * AEAT ClaveTipoRectificativaType: 'S' (sustitución) | 'I' (incremental,
     * por diferencias). Larabill rectifications are por diferencias, so 'I';
     * lara-verifactu 0.10 emits the value verbatim as TipoRectificativa.
     */
    private static function getRectificationType(Invoice $invoice): ?string
    {
        if ($invoice->rectifies_invoice_id === null) {
            return null;
        }

        return 'I';
    }

    /**
     * Build the rectified-invoice references consumed by lara-verifactu 0.10
     * (metadata['rectified_invoices'] → AEAT FacturasRectificadas).
     *
     * The number uses the same serie+series_number composition the adapter
     * gives the native model, so the reference matches the NumSerieFactura
     * the rectified invoice was registered with.
     *
     * @return array<int, array{number: string, issue_date: string}>
     */
    private static function rectifiedInvoiceReferences(Invoice $invoice): array
    {
        if ($invoice->rectifies_invoice_id === null) {
            return [];
        }

        $rectified = Invoice::query()->find($invoice->rectifies_invoice_id);

        if (! $rectified) {
            return [];
        }

        $issueDate = $rectified->issued_at ?? $rectified->invoice_date;

        return [[
            'number'     => ($rectified->serie->value ?? '').$rectified->series_number,
            'issue_date' => $issueDate->format('Y-m-d'),
        ]];
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

        // Intra-community classification is read ONLY from the immutable snapshots
        // (ADR-001); never from live config (CompanyFiscalConfig::getActive()).
        $issuer   = $invoice->getIssuerSnapshotData()   ?? [];
        $customer = $invoice->getCustomerSnapshotData() ?? [];
        $fiscal   = $invoice->getFiscalSnapshotData()   ?? [];

        $issuerCountry   = $issuer['country_code']   ?? ($fiscal['issuer_country'] ?? null);
        $customerCountry = $customer['country_code'] ?? ($fiscal['customer_country'] ?? null);
        $customerHasVat  = ($customer['is_eu_vat_registered'] ?? false) === true
            && ! empty($customer['tax_id']);

        // Non-Spain-centric: compare issuer vs customer, not vs ES.
        $crossBorderEu = self::isEuCountry($issuerCountry)
            && self::isEuCountry($customerCountry)
            && $customerCountry !== $issuerCountry;

        // Intra-EU B2B services → N2 (not subject by localisation; taxed at destination).
        if ($crossBorderEu && $customerHasVat) {
            if (self::hasGoods($invoice)) {
                throw ValidationException::withMessages([
                    'verifactu' => 'Intra-EU supply of goods (E5, art. 25) is out of scope for AID-136 (services only); refusing to emit N2.',
                ]);
            }

            // Rule 1237: an N2 line cannot carry VAT. Real tax means inconsistent input,
            // so fail loud instead of silently dropping the tax amount.
            if (self::invoiceHasRealTax($invoice)) {
                throw ValidationException::withMessages([
                    'verifactu' => 'An N2 (not subject by localisation) breakdown cannot carry VAT (rule 1237), but the invoice has a non-zero tax amount.',
                ]);
            }

            return [[
                'tax_type'         => self::TAX_TYPE_VAT,
                'tax_rate'         => 0.0,
                'base_amount'      => self::base100ToDecimal($invoice->taxable_amount->unscaledValue()),
                'tax_amount'       => 0.0,
                'exempt'           => false,
                'exemption_reason' => null,
                'calificacion'     => CalificacionOperacionEnum::N2->value,
            ]];
        }

        // OSS / IOSS (régimen 17): cross-border B2C with an OSS issuer is out of scope (post-1.0).
        // Without this guard such a sale would silently emit S1 (the adapter never produces régimen 17).
        if ($crossBorderEu
            && ($customer['is_eu_vat_registered'] ?? false) === false
            && ($issuer['is_oss'] ?? false)                 === true
        ) {
            throw ValidationException::withMessages([
                'verifactu' => 'OSS / IOSS (régimen 17) B2C sales are not supported (post-1.0); refusing to emit S1.',
            ]);
        }

        // Reverse charge signalled but the recipient is not a complete intra-EU VAT party:
        // do NOT fall through to an exempt/S1 row with the wrong reason — fail loud.
        if ($invoice->is_roi_taxed) {
            throw ValidationException::withMessages([
                'verifactu' => 'Reverse-charge invoice is incomplete: a VAT-registered intra-EU recipient (country + NIF-IVA) is required to emit N2.',
            ]);
        }

        /** @var array<int, array{base: int, tax: int}> $groups keyed by rate in base10000 */
        $groups     = [];
        $exemptBase = 0;

        foreach ($invoice->items as $item) {
            $taxes = $item->taxes_applied ?? [];

            if ($taxes === []) {
                $exemptBase += $item->taxable_amount->unscaledValue();

                continue;
            }

            foreach ($taxes as $tax) {
                $rate = (int) $tax['rate'];

                $groups[$rate]['base'] = ($groups[$rate]['base'] ?? 0) + $item->taxable_amount->unscaledValue();
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
     * @param  bool  $isSimplified  Whether the invoice has no identified recipient
     * @return string Verifactu invoice type (F1, F2, F3, R1-R5)
     */
    private static function mapInvoiceType(Invoice $invoice, bool $isSimplified): string
    {
        // F1: Factura completa
        // F2: Factura simplificada
        // F3: Factura emitida en sustitución de facturas simplificadas
        // R1-R5: Rectificativas
        if ($invoice->rectifies_invoice_id !== null) {
            return 'R1'; // Por diferencias (más común)
        }

        return $isSimplified ? 'F2' : 'F1';
    }

    /**
     * Map the recipient document type to the AEAT IdTypeEnum value (AID-136).
     *
     * Scope: ES → 02 (NIF). Foreign EU recipient with a valid NIF-IVA → 02
     * (the country is embedded in the VAT, so the builder omits CodigoPais).
     * Any other foreign recipient → 04 (official document of country of residence,
     * builder emits CodigoPais). Never 03/06/07 in this scope: 07 is a domestic
     * non-registered case and 06 needs an explicit document-type field the snapshot
     * does not carry.
     *
     * @param  array<string, mixed>  $customerData  Customer fiscal data from snapshot
     * @return string Verifactu ID type (02=NIF-IVA, 04=official document)
     */
    private static function mapRecipientIdType(array $customerData = []): string
    {
        $countryCode = $customerData['country_code'] ?? 'ES';

        if ($countryCode === 'ES') {
            return '02';
        }

        if (($customerData['is_eu_vat_registered'] ?? false) === true && ! empty($customerData['tax_id'])) {
            return '02'; // NIF-IVA
        }

        return '04'; // Official document of the country of residence
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
     * Whether a country code belongs to an EU member state.
     */
    private static function isEuCountry(?string $countryCode): bool
    {
        return $countryCode !== null && in_array($countryCode, self::EU_COUNTRIES, true);
    }

    /**
     * Whether the invoice contains any item classified as goods (not services).
     */
    private static function hasGoods(Invoice $invoice): bool
    {
        return $invoice->items->contains(
            fn ($item) => $item->item_type === ItemType::GOOD
        );
    }

    /**
     * Whether the invoice carries any real (non-zero) VAT.
     *
     * Checks the invoice total first, then per-item tax amounts. A zero-amount tax
     * snapshot (VatCalculationStrategy emits one per rate) does NOT count as real tax.
     */
    private static function invoiceHasRealTax(Invoice $invoice): bool
    {
        if (($invoice->total_tax_amount?->unscaledValue() ?? 0) > 0) {
            return true;
        }

        foreach ($invoice->items as $item) {
            foreach ($item->taxes_applied ?? [] as $tax) {
                if ((int) ($tax['amount'] ?? 0) !== 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
