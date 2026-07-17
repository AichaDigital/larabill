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
     * Get template for invoice type
     *
     * @param  Invoice  $invoice  The invoice
     * @return string Template name
     */
    protected function getTemplateForInvoice(Invoice $invoice): string
    {
        // Check if invoice has specific template
        if ($invoice->template_name) {
            $template = InvoiceTemplate::getByName(
                $invoice->template_name,
                $invoice->getInvoiceType()
            );

            if ($template) {
                return $template->template_path;
            }
        }

        // Determine template based on invoice type and fiscal data
        if ($invoice->serie === InvoiceSerieType::PROFORMA) {
            return 'larabill::pdf.invoice.proforma';
        }

        // Check for special fiscal cases
        if ($this->isReverseCharge($invoice)) {
            return 'larabill::pdf.invoice.reverse-charge';
        }

        if ($this->isExemptInvoice($invoice)) {
            return 'larabill::pdf.invoice.exempt';
        }

        // Default fiscal invoice
        return 'larabill::pdf.invoice.fiscal';
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
        // VAT exemption comes from the recipient's immutable fiscal snapshot.
        return (bool) $invoice->userTaxProfile?->is_exempt_vat;
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
            'company'           => $this->getCompanyData(),
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
     * Get company data for template
     *
     * @return array<string, mixed> Company data
     */
    protected function getCompanyData(): array
    {
        // This should be configurable per company
        return [
            'name'        => config('app.name', 'Mi Empresa'),
            'address'     => 'Dirección de la empresa',
            'city'        => 'Ciudad',
            'postal_code' => '00000',
            'country'     => 'España',
            'tax_id'      => 'NIF: 12345678A',
            'phone'       => '+34 123 456 789',
            'email'       => 'info@empresa.com',
            'website'     => 'https://empresa.com',
        ];
    }

    /**
     * Get client data for template
     *
     * @param  Invoice  $invoice  The invoice
     * @return array<string, mixed> Client data
     */
    protected function getClientData(Invoice $invoice): array
    {
        // Recipient identity comes from the immutable UserTaxProfile snapshot.
        $profile = $invoice->userTaxProfile;

        if (! $profile) {
            return [];
        }

        return [
            'name'        => $profile->fiscal_name,
            'address'     => $profile->address,
            'city'        => $profile->city,
            'postal_code' => $profile->zip_code,
            'country'     => $profile->country_code,
            'tax_id'      => $profile->tax_id,
        ];
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
     * Get invoice totals for template
     *
     * @param  Invoice  $invoice  The invoice
     * @return array<string, mixed> Invoice totals
     */
    protected function getInvoiceTotals(Invoice $invoice): array
    {
        return [
            'subtotal'   => $invoice->subtotal,
            'tax_amount' => $invoice->tax_amount,
            'total'      => $invoice->total,
            'currency'   => 'EUR',
        ];
    }

    /**
     * Save PDF file
     *
     * @param  Invoice  $invoice  The invoice
     * @param  string  $pdfContent  PDF content
     * @return string PDF file path
     */
    protected function savePDF(Invoice $invoice, string $pdfContent): string
    {
        $filename = 'invoice_'.$invoice->id.'_'.$invoice->type.'.pdf';

        // Use temp directory for testing, storage for production (AID-391:
        // filesystem writes go through the File facade, never raw mkdir/
        // file_put_contents — fakeable and consistent with the framework).
        // The environment check and storage_path() are guaranteed framework
        // APIs (AID-508) — no defensive wrapping.
        if (! app()->environment('production')) {
            $pdfPath = sys_get_temp_dir().'/'.$filename;
        } else {
            $pdfPath = storage_path('app/invoices/'.$filename);
            File::ensureDirectoryExists(dirname($pdfPath), 0755);
        }

        File::put($pdfPath, $pdfContent);

        return $pdfPath;
    }

    /**
     * Generate PDF URL
     *
     * @param  Invoice  $invoice  The invoice
     * @return string PDF URL
     */
    protected function generatePDFUrl(Invoice $invoice): string
    {
        $filename = 'invoice_'.$invoice->id.'_'.$invoice->type.'.pdf';

        // Use temp URL for testing, real URL for production. app()->environment()
        // and url() are guaranteed framework APIs (AID-508) — no defensive wrapping.
        if (! app()->environment('production')) {
            return 'http://localhost/storage/invoices/'.$filename;
        }

        return url('storage/invoices/'.$filename);
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
            $this->convertToTemplateInvoiceType($invoice->getInvoiceType()),
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
            $this->convertToTemplateInvoiceType($invoice->getInvoiceType()),
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
        // to the frontier (AID-508).
        $template = null;

        if ($invoice->template_name) {
            $template = InvoiceTemplate::getByName(
                $invoice->template_name,
                $invoice->getInvoiceType()
            );
        }

        if (! $template) {
            $template = InvoiceTemplate::getDefaultForType(
                $invoice->getInvoiceType()
            );
        }

        return $template ? $template->settings ?? [] : [];
    }

    /**
     * Get company ID for invoice (placeholder implementation)
     *
     * @param  Invoice  $invoice  The invoice
     * @return string Company ID
     */
    protected function getCompanyId(Invoice $invoice): string
    {
        // This should be configured based on your application's needs
        // For now, return a default company ID
        return config('larabill.default_company_id', 'default');
    }

    /**
     * Convert invoice type string to TemplateInvoiceType enum
     *
     * @param  string  $invoiceType  Invoice type string (e.g., 'invoice', 'proforma')
     * @return TemplateInvoiceType Corresponding enum value
     */
    protected function convertToTemplateInvoiceType(string $invoiceType): TemplateInvoiceType
    {
        return match (strtolower($invoiceType)) {
            'proforma'                         => TemplateInvoiceType::PROFORMA,
            'reverse_charge', 'reverse-charge' => TemplateInvoiceType::REVERSE_CHARGE,
            'exempt'                           => TemplateInvoiceType::EXEMPT,
            default                            => TemplateInvoiceType::FISCAL, // invoice, simplified, rectificative -> fiscal
        };
    }

    /**
     * Render template safely
     *
     * @param  string  $template  Template name
     * @param  array<string, mixed>  $data  Template data
     * @return string Rendered HTML
     */
    protected function renderTemplate(string $template, array $data): string
    {
        try {
            return View::make($template, $data)->render();
        } catch (\Throwable $e) {
            // A real render failure must NOT be masked as a plausible-but-fake invoice.
            // Log with context and surface the error so the frontier reports failure.
            $invoice = $data['invoice'] ?? null;

            Log::error('larabill: invoice PDF template render failed; surfacing instead of falling back to a mock invoice', [
                'invoice_id'      => $invoice instanceof Invoice ? $invoice->id : null,
                'invoice_number'  => $invoice instanceof Invoice ? $invoice->fiscal_number : null,
                'template'        => $template,
                'exception_class' => $e::class,
                'exception'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
