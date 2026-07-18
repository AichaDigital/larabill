<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\PDF;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\TemplateInvoiceType;
use AichaDigital\Larabill\Models\CompanyTemplateSettings;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\InvoiceTemplate;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * DomPDF service for generating PDF invoices
 *
 * This service handles PDF generation using DomPDF with different templates
 * for various invoice types (fiscal, proforma, etc.)
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class DomPDFService
{
    /**
     * DomPDF instance (or mock for testing)
     */
    protected mixed $dompdf;

    /**
     * Configuration
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Create a new DomPDF service instance
     *
     * @param  array<string, mixed>  $config  Configuration array
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'paper_size'              => 'A4',
            'paper_orientation'       => 'portrait',
            'font_cache'              => true,
            'is_remote_enabled'       => true,
            'is_html5_parser_enabled' => true,
            'default_font'            => 'DejaVu Sans',
        ], $config);

        $this->initializeDomPDF();
    }

    /**
     * Generate PDF for an invoice
     *
     * @param  Invoice  $invoice  The invoice to generate PDF for
     * @param  array<string, mixed>|null  $qrData  QR data (only for fiscal invoices)
     * @return array<string, mixed> PDF generation result
     */
    public function generatePDF(Invoice $invoice, ?array $qrData = null): array
    {
        // No catch here (AID-508): this method used to translate any exception into
        // ['success' => false], keeping only getMessage() and dropping the class,
        // the stack trace and the previous — while PDFService rebuilt a generic
        // RuntimeException from that string. Exception → array → exception → array,
        // losing the original. The frontier (PDFService) is the only translator.
        $template  = $this->getTemplateForInvoice($invoice);
        $includeQR = $invoice->shouldIncludeQR();

        $templateData = $this->prepareTemplateData($invoice, $qrData, $includeQR);
        $html         = $this->renderTemplate($template, $templateData);

        // The safe-restyle guarantee (ADR-011, AID-502): a template — the
        // consumer's included — may not silently drop mandatory fiscal
        // content. Fiscal series only; a proforma is not a fiscal document.
        // The exception propagates raw to the frontier (AID-535).
        if ($invoice->serie->isFiscal() && (bool) config('larabill.pdf.validate_fiscal_content', true)) {
            (new FiscalContentValidator)->validate($invoice, $templateData, $html);
        }

        $this->dompdf->loadHtml($html);
        $this->dompdf->render();

        $pdfContent = $this->dompdf->output();
        $pdfPath    = $this->savePDF($invoice, $pdfContent);

        return [
            'success'       => true,
            'pdf_path'      => $pdfPath,
            'pdf_url'       => null,
            'pdf_size'      => strlen($pdfContent),
            'template_used' => $template,
            'qr_included'   => $includeQR,
            'generated_at'  => now()->toISOString(),
        ];
    }

    /**
     * Get available templates
     *
     * @return array<string, string> List of available templates
     */
    public function getAvailableTemplates(): array
    {
        return [
            'fiscal'         => 'larabill::pdf.invoice.fiscal',
            'proforma'       => 'larabill::pdf.invoice.proforma',
            'reverse_charge' => 'larabill::pdf.invoice.reverse-charge',
            'exempt'         => 'larabill::pdf.invoice.exempt',
        ];
    }

    /**
     * Get configuration
     *
     * @return array<string, mixed> Configuration array
     */
    public function getConfiguration(): array
    {
        return $this->config;
    }

    /**
     * Update configuration
     *
     * @param  array<string, mixed>  $config  New configuration
     */
    public function updateConfiguration(array $config): void
    {
        $this->config = array_merge($this->config, $config);
        $this->initializeDomPDF();
    }

    /**
     * Initialize DomPDF with configuration
     */
    protected function initializeDomPDF(): void
    {
        $options = new Options;
        $options->set('defaultFont', $this->config['default_font']);
        $options->set('isRemoteEnabled', $this->config['is_remote_enabled']);
        $options->set('isHtml5ParserEnabled', $this->config['is_html5_parser_enabled']);
        $options->set('fontCache', $this->config['font_cache']);

        $this->dompdf = new Dompdf($options);
        $this->dompdf->setPaper($this->config['paper_size'], $this->config['paper_orientation']);
    }

    /**
     * Get the view for the invoice's template.
     *
     * Resolution chain (ADR-011, AID-502): the requested `template_name` in
     * the registry → the registry's default row for the template type → the
     * package blade (unseeded installations). All registry lookups speak
     * TemplateInvoiceType::registryKey() — the serie label never reaches the
     * registry, whose vocabulary mismatch ('invoice' vs 'fiscal') meant no
     * fiscal invoice ever resolved a registry row before this.
     *
     * @param  Invoice  $invoice  The invoice
     * @return string Template name
     */
    protected function getTemplateForInvoice(Invoice $invoice): string
    {
        $type = $this->resolveTemplateType($invoice);

        if ($invoice->template_name) {
            $template = InvoiceTemplate::getByName(
                $invoice->template_name,
                $type->registryKey()
            );

            if ($template) {
                return $template->template_path;
            }

            // The consumer asked for a template and did not get it. That deserves a
            // trace, not a hard failure: it is a presentation preference, not a
            // fiscal blocker — the document is still valid with the default. With
            // the vocabulary settled (ADR-011), a miss here means exactly one
            // thing: no active row with that name for this template type.
            Log::warning('Configured invoice PDF template does not exist (or is inactive) for this template type; using the default template.', [
                'requested_template' => $invoice->template_name,
                'lookup_type'        => $type->registryKey(),
                'invoice_id'         => $invoice->id,
            ]);
        }

        // The registry's default row governs the view; before ADR-011 it only
        // ever contributed settings, never the view itself.
        $default = InvoiceTemplate::getDefaultForType($type->registryKey());

        if ($default) {
            return $default->template_path;
        }

        return $this->defaultViewFor($type);
    }

    /**
     * The package's own blade for a template type — the last-resort fallback
     * for installations that never seeded the template registry.
     */
    protected function defaultViewFor(TemplateInvoiceType $type): string
    {
        return match ($type) {
            TemplateInvoiceType::PROFORMA       => 'larabill::pdf.invoice.proforma',
            TemplateInvoiceType::REVERSE_CHARGE => 'larabill::pdf.invoice.reverse-charge',
            TemplateInvoiceType::EXEMPT         => 'larabill::pdf.invoice.exempt',
            TemplateInvoiceType::FISCAL         => 'larabill::pdf.invoice.fiscal',
        };
    }

    /**
     * The invoice's template type — the SINGLE derivation (ADR-011, AID-502).
     *
     * The fiscal serie (`InvoiceSerieType`) is AEAT vocabulary; the template
     * registry speaks presentation vocabulary (`TemplateInvoiceType`). This is
     * the only place one becomes the other: proforma keeps its own family,
     * reverse charge (ROI) takes precedence over VAT exemption, and every
     * other fiscal serie (invoice, simplified, rectificative) presents as
     * FISCAL.
     */
    protected function resolveTemplateType(Invoice $invoice): TemplateInvoiceType
    {
        return match (true) {
            $invoice->serie === InvoiceSerieType::PROFORMA => TemplateInvoiceType::PROFORMA,
            $this->isReverseCharge($invoice)               => TemplateInvoiceType::REVERSE_CHARGE,
            $this->isExemptInvoice($invoice)               => TemplateInvoiceType::EXEMPT,
            default                                        => TemplateInvoiceType::FISCAL,
        };
    }

    /**
     * Check if invoice is reverse charge
     *
     * @param  Invoice  $invoice  The invoice
     * @return bool True if reverse charge
     */
    protected function isReverseCharge(Invoice $invoice): bool
    {
        // Reverse charge (inversión del sujeto pasivo) is the immutable ROI flag.
        // Cast: an in-memory invoice with the attribute unset yields null (Eloquent
        // skips the boolean cast on an absent key), which would violate `: bool`.
        return (bool) $invoice->is_roi_taxed;
    }

    /**
     * Check if invoice is exempt
     *
     * @param  Invoice  $invoice  The invoice
     * @return bool True if exempt
     */
    protected function isExemptInvoice(Invoice $invoice): bool
    {
        // VAT exemption is frozen with the invoice (AID-546): the recipient's
        // customer_snapshot, never the live profile — which can be edited after
        // issuance. Legacy invoices without a snapshot fall back best-effort.
        $snapshot = $invoice->getCustomerSnapshotData() ?? $this->legacyCustomerData($invoice);

        return (bool) ($snapshot['is_exempt_vat'] ?? false);
    }

    /**
     * Prepare template data
     *
     * @param  Invoice  $invoice  The invoice
     * @param  array<string, mixed>|null  $qrData  QR data
     * @param  bool  $includeQR  Whether to include QR
     * @return array<string, mixed> Template data
     */
    protected function prepareTemplateData(Invoice $invoice, ?array $qrData, bool $includeQR): array
    {
        $data = [
            'invoice'           => $invoice,
            'company'           => $this->getCompanyData($invoice),
            'client'            => $this->getClientData($invoice),
            'items'             => $this->getInvoiceItems($invoice),
            'totals'            => $this->getInvoiceTotals($invoice),
            'fiscal_data'       => [
                'reverse_charge' => $this->isReverseCharge($invoice),
                'exempt'         => $this->isExemptInvoice($invoice),
            ],
            'include_qr'        => $includeQR,
            'generated_at'      => now(),
            'notes'             => $this->getInvoiceNotes($invoice),
            'payment_terms'     => $this->getPaymentTerms($invoice),
            'template_settings' => $this->getTemplateSettings($invoice),
            'operation_date'    => $this->getOperationDate($invoice),
        ];

        // Add QR data if included
        if ($includeQR && $qrData) {
            $data['qr_data'] = $qrData;
        }

        return $data;
    }

    /**
     * Get the operation date to print, or null when the row must not appear.
     *
     * RD 1619/2012 art. 6.1.f (AID-442): the invoice must show the date the
     * documented operations were carried out ONLY when it differs from the
     * expedition date (`invoice_date`, AID-439). The visibility rule lives
     * here so the templates stay dumb — with equal or missing dates they
     * render exactly as before.
     *
     * @param  Invoice  $invoice  The invoice
     * @return string|null Formatted operation date, or null to omit the row
     */
    protected function getOperationDate(Invoice $invoice): ?string
    {
        if ($invoice->service_date === null || $invoice->invoice_date === null) {
            return null;
        }

        return $invoice->service_date->isSameDay($invoice->invoice_date)
            ? null
            : $invoice->service_date->format('d/m/Y');
    }

    /**
     * Get the issuer's data for the template, from the invoice's frozen snapshot.
     *
     * The issuer's identity is the one AT ISSUANCE, not today's configuration
     * (ADR-001; AID-328 established that the frozen side is the persisted snapshot,
     * never the live row — which can be edited in place).
     *
     * Legacy invoices with no snapshot fall back to the referenced config row. That
     * row may have been edited since, so the identity is best-effort: a documented
     * limitation, not a guarantee.
     *
     * No phone/email/website: those fields do not exist in CompanyFiscalConfig, and
     * the old stub simply invented them (AID-508).
     *
     * @param  Invoice  $invoice  The invoice
     * @return array<string, mixed> Issuer data
     */
    protected function getCompanyData(Invoice $invoice): array
    {
        $snapshot = $invoice->getIssuerSnapshotData() ?? $this->legacyIssuerData($invoice);

        if ($snapshot === null) {
            return [];
        }

        return [
            'name'        => $snapshot['business_name'] ?? null,
            'address'     => $snapshot['address']       ?? null,
            'city'        => $snapshot['city']          ?? null,
            'postal_code' => $snapshot['zip_code']      ?? null,
            'country'     => $snapshot['country_code']  ?? null,
            'tax_id'      => $snapshot['tax_id']        ?? null,
        ];
    }

    /**
     * Best-effort issuer data for invoices frozen before the snapshot existed.
     *
     * @return array<string, mixed>|null
     */
    protected function legacyIssuerData(Invoice $invoice): ?array
    {
        $config = $invoice->companyFiscalConfig;

        return $config === null ? null : $config->only([
            'business_name', 'tax_id', 'address', 'city', 'zip_code', 'country_code',
        ]);
    }

    /**
     * Get the recipient's data for the template, from the invoice's frozen snapshot.
     *
     * The recipient's identity is the one AT ISSUANCE, not today's profile
     * (ADR-001; AID-546 — the customer half of the identity defect AID-508 fixed
     * on the issuer side). The frozen side is the persisted customer_snapshot,
     * never the live row, which can be edited in place (AID-328).
     *
     * Legacy invoices with no snapshot fall back to the referenced profile row.
     * That row may have been edited since, so the identity is best-effort: a
     * documented limitation, not a guarantee.
     *
     * @param  Invoice  $invoice  The invoice
     * @return array<string, mixed> Client data
     */
    protected function getClientData(Invoice $invoice): array
    {
        $snapshot = $invoice->getCustomerSnapshotData() ?? $this->legacyCustomerData($invoice);

        if ($snapshot === null) {
            return [];
        }

        return [
            'name'        => $snapshot['fiscal_name']  ?? null,
            'address'     => $snapshot['address']      ?? null,
            'city'        => $snapshot['city']         ?? null,
            'postal_code' => $snapshot['zip_code']     ?? null,
            'country'     => $snapshot['country_code'] ?? null,
            'tax_id'      => $snapshot['tax_id']       ?? null,
        ];
    }

    /**
     * Best-effort recipient data for invoices frozen before the snapshot existed.
     *
     * Mirror of legacyIssuerData(): the live profile is all there is.
     *
     * @return array<string, mixed>|null
     */
    protected function legacyCustomerData(Invoice $invoice): ?array
    {
        $profile = $invoice->userTaxProfile;

        return $profile === null ? null : $profile->only([
            'fiscal_name', 'tax_id', 'address', 'city', 'zip_code', 'country_code', 'is_exempt_vat',
        ]);
    }

    /**
     * Get the invoice's real lines for the template.
     *
     * Amounts are handed over as EXACT STRINGS (AID-508): money never travels to a
     * blade as a number to be divided there. The scale belongs to each value —
     * `quantity` is 1.50 units, `unit_price` is €100.00 — and FixedDecimal knows it,
     * so the template does no arithmetic at all.
     *
     * @param  Invoice  $invoice  The invoice
     * @return array<int, array<string, mixed>> Invoice lines
     */
    protected function getInvoiceItems(Invoice $invoice): array
    {
        return $invoice->items->map(fn (InvoiceItem $item): array => [
            'description'    => $item->description,
            'quantity'       => $item->quantity->toDecimalString(),
            'unit_price'     => $item->unit_price->toDecimalString(),
            'taxable_amount' => $item->taxable_amount->toDecimalString(),
            'tax_amount'     => $item->total_tax_amount->toDecimalString(),
            'total'          => $item->total_amount->toDecimalString(),
            'taxes'          => $this->formatLineTaxes($item),
        ])->all();
    }

    /**
     * Format a line's immutable tax snapshot (`invoice_items.taxes_applied`).
     *
     * Shape persisted by VatCalculationStrategy:
     * ['source_rate_id' => int, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100]
     * where `rate` is base-100 of the percentage (2100 = 21%) and `amount` base-100
     * euros. The rate is a string too: an int cannot carry a 5.2% equivalence surcharge.
     *
     * @return array<int, array<string, string>>
     */
    protected function formatLineTaxes(InvoiceItem $item): array
    {
        return array_map(fn (array $tax): array => [
            'name'   => (string) ($tax['name'] ?? ''),
            'rate'   => FixedDecimal::ofUnscaled((int) ($tax['rate'] ?? 0), 2)->toDecimalString(),
            'amount' => FixedDecimal::ofUnscaled((int) ($tax['amount'] ?? 0), 2)->toDecimalString(),
        ], $item->taxes_applied ?? []);
    }

    /**
     * Get the invoice totals for the template.
     *
     * Reads the REAL columns (AID-508): the old code read $invoice->subtotal,
     * ->tax_amount and ->total — none of which exist. Eloquent returned null and
     * the blade's number_format(null / 100, 2) printed "0.00" with no error and no
     * warning, on an invoice of €121.00.
     *
     * @param  Invoice  $invoice  The invoice
     * @return array<string, mixed> Totals, as exact strings
     */
    protected function getInvoiceTotals(Invoice $invoice): array
    {
        return [
            'subtotal'      => $invoice->taxable_amount->toDecimalString(),
            'tax_amount'    => $invoice->total_tax_amount->toDecimalString(),
            'total'         => $invoice->total_amount->toDecimalString(),
            'currency'      => 'EUR',
            'tax_breakdown' => $this->taxBreakdown($invoice),
        ];
    }

    /**
     * Aggregate the per-rate tax breakdown from the lines' immutable snapshots.
     *
     * RD 1619/2012 art. 6.1.g/h requires the taxable base and the rate, broken down
     * when the invoice comprises several. The old templates printed
     * `$items[0]['tax_rate'] ?? 21` — the first line's rate for the whole invoice,
     * with an invented fallback.
     *
     * Grouped by `source_rate_id` (the rate's identity, not its percentage: the
     * snapshot keeps whatever was in force at issuance). A line bearing two taxes on
     * the same base contributes its base to both, which is correct.
     *
     * @return array<int, array<string, string>>
     */
    protected function taxBreakdown(Invoice $invoice): array
    {
        $groups = [];

        foreach ($invoice->items as $item) {
            foreach ($item->taxes_applied ?? [] as $tax) {
                $key = (string) ($tax['source_rate_id'] ?? $tax['rate'] ?? 'unknown');

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'name'   => (string) ($tax['name'] ?? ''),
                        'rate'   => (int) ($tax['rate'] ?? 0),
                        'base'   => 0,
                        'amount' => 0,
                    ];
                }

                $groups[$key]['base']   += $item->taxable_amount->unscaledValue();
                $groups[$key]['amount'] += (int) ($tax['amount'] ?? 0);
            }
        }

        // A fiscal document must print its rates in a stable order: relation-iteration
        // order is an implementation detail, not something a printed PDF may vary on.
        uasort($groups, fn (array $a, array $b): int => $b['rate'] <=> $a['rate'] ?: $a['name'] <=> $b['name']);

        return array_values(array_map(fn (array $group): array => [
            'name'   => $group['name'],
            'rate'   => FixedDecimal::ofUnscaled($group['rate'], 2)->toDecimalString(),
            'base'   => FixedDecimal::ofUnscaled($group['base'], 2)->toDecimalString(),
            'amount' => FixedDecimal::ofUnscaled($group['amount'], 2)->toDecimalString(),
        ], $groups));
    }

    /**
     * Save the PDF under the single, private root.
     *
     * One root and one name (AID-508). The name used to be composed from
     * $invoice->type — a column that does not exist — so it never matched what
     * Invoice::getPDFPath() looked for, and the consumer regenerated the PDF on
     * every single download. The temp/storage fork died with isProductionEnvironment().
     *
     * @param  Invoice  $invoice  The invoice
     * @param  string  $pdfContent  PDF content
     * @return string PDF file path
     */
    protected function savePDF(Invoice $invoice, string $pdfContent): string
    {
        $pdfPath = storage_path('app/invoices/'.$invoice->pdfFilename());

        File::ensureDirectoryExists(dirname($pdfPath), 0755);
        File::put($pdfPath, $pdfContent);

        return $pdfPath;
    }

    /**
     * Get invoice notes (individual, client-specific, or global)
     *
     * @param  Invoice  $invoice  The invoice
     * @return string|null Notes
     */
    protected function getInvoiceNotes(Invoice $invoice): ?string
    {
        // Priority: individual -> client -> global. A null from getDefaultNotes()
        // is a legitimate absence (no notes configured) and stays null; a database
        // exception is NOT an absence and now propagates to the frontier (AID-508).
        if ($invoice->notes) {
            return $invoice->notes;
        }

        return CompanyTemplateSettings::getDefaultNotes(
            $this->getCompanyId($invoice),
            $this->resolveTemplateType($invoice),
            (string) $invoice->user_id
        );
    }

    /**
     * Get payment terms (individual, client-specific, or global)
     *
     * @param  Invoice  $invoice  The invoice
     * @return string|null Payment terms
     */
    protected function getPaymentTerms(Invoice $invoice): ?string
    {
        // Priority: individual -> client -> global. A null from getPaymentTerms()
        // is a legitimate absence (no terms configured) and stays null; a database
        // exception is NOT an absence and now propagates to the frontier (AID-508).
        if ($invoice->payment_terms) {
            return $invoice->payment_terms;
        }

        return CompanyTemplateSettings::getPaymentTerms(
            $this->getCompanyId($invoice),
            $this->resolveTemplateType($invoice),
            (string) $invoice->user_id
        );
    }

    /**
     * Get template settings for invoice
     *
     * @param  Invoice  $invoice  The invoice
     * @return array<string, mixed> Template settings
     */
    protected function getTemplateSettings(Invoice $invoice): array
    {
        // A missing template row is a legitimate absence (empty settings) and
        // stays []; a database exception is NOT an absence and now propagates
        // to the frontier (AID-508). Lookups speak the registry vocabulary
        // (ADR-011) — with the serie label they never resolved a row.
        $type     = $this->resolveTemplateType($invoice);
        $template = null;

        if ($invoice->template_name) {
            $template = InvoiceTemplate::getByName(
                $invoice->template_name,
                $type->registryKey()
            );
        }

        if (! $template) {
            $template = InvoiceTemplate::getDefaultForType(
                $type->registryKey()
            );
        }

        return $template ? $template->settings ?? [] : [];
    }

    /**
     * The issuer whose template settings apply: the invoice's own fiscal config.
     *
     * The old stub returned config('larabill.default_company_id', 'default') — a key
     * that does not exist — so notes and payment terms silently resolved against a
     * company that was never there (AID-508).
     */
    protected function getCompanyId(Invoice $invoice): string
    {
        return (string) $invoice->company_fiscal_config_id;
    }

    /**
     * Render the template.
     *
     * A render failure surfaces RAW to the frontier (PDFService), the
     * subsystem's single logger and translator (AID-535): no mock-invoice
     * fallback (AID-508) and no double logging. The failing view's name
     * travels inside the exception Blade throws, so no context is lost by
     * not logging here.
     *
     * @param  string  $template  Template name
     * @param  array<string, mixed>  $data  Template data
     * @return string Rendered HTML
     */
    protected function renderTemplate(string $template, array $data): string
    {
        return View::make($template, $data)->render();
    }
}
