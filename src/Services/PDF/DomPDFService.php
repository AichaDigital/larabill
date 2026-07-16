<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\PDF;

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\TemplateInvoiceType;
use AichaDigital\Larabill\Models\CompanyTemplateSettings;
use AichaDigital\Larabill\Models\Invoice;
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
        try {
            // Determine invoice type and template
            $template  = $this->getTemplateForInvoice($invoice);
            $includeQR = $invoice->shouldIncludeQR();

            // Prepare template data
            $templateData = $this->prepareTemplateData($invoice, $qrData, $includeQR);

            // Load HTML content
            $html = $this->renderTemplate($template, $templateData);

            // Generate PDF
            $this->dompdf->loadHtml($html);
            $this->dompdf->render();

            // Get PDF content
            $pdfContent = $this->dompdf->output();

            // Save PDF file
            $pdfPath = $this->savePDF($invoice, $pdfContent);
            $pdfUrl  = $this->generatePDFUrl($invoice);

            return [
                'success'       => true,
                'pdf_path'      => $pdfPath,
                'pdf_url'       => $pdfUrl,
                'pdf_size'      => strlen($pdfContent),
                'template_used' => $template,
                'qr_included'   => $includeQR,
                'generated_at'  => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'success'       => false,
                'error'         => $e->getMessage(),
                'template_used' => $template ?? 'unknown',
                'generated_at'  => now()->toISOString(),
            ];
        }
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
        // Check if DomPDF is available (for testing compatibility)
        if (! class_exists('Dompdf\Dompdf')) {
            // Create a mock object for testing
            $this->dompdf = new class
            {
                public function loadHtml(string $html): void {}

                public function setPaper(string $paper, string $orientation): void {}

                public function render(): void {}

                public function output(): string
                {
                    return 'mock-pdf-content';
                }
            };

            return;
        }

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
            // Only try to get from database if the model exists and we're not in testing
            if (class_exists('\AichaDigital\Larabill\Models\InvoiceTemplate') && app()->bound('db')) {
                try {
                    $template = InvoiceTemplate::getByName(
                        $invoice->template_name,
                        $invoice->getInvoiceType()
                    );

                    if ($template) {
                        return $template->template_path;
                    }
                } catch (\Exception $e) {
                    // Fall back to default templates if database access fails
                }
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
            'name'        => $this->getConfigValue('app.name', 'Mi Empresa'),
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
     * Get invoice items for template
     *
     * @param  Invoice  $invoice  The invoice
     * @return array<int, array<string, mixed>> Invoice items
     */
    protected function getInvoiceItems(Invoice $invoice): array
    {
        // This should load from invoice_items relationship
        return [
            [
                'description' => 'Servicio 1',
                'quantity'    => 1,
                'unit_price'  => $invoice->subtotal,
                'tax_rate'    => 21,
                'tax_amount'  => $invoice->tax_amount,
                'total'       => $invoice->total,
            ],
        ];
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
        if (! $this->isProductionEnvironment() || ! function_exists('storage_path')) {
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

        // Use temp URL for testing, real URL for production
        if (! $this->isProductionEnvironment() || ! function_exists('url')) {
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
        // Priority: individual -> client -> global
        if ($invoice->notes) {
            return $invoice->notes;
        }

        // Only try to get from database if the model exists and we're not in testing
        if (class_exists('\AichaDigital\Larabill\Models\CompanyTemplateSettings') && app()->bound('db')) {
            try {
                $companyId = $this->getCompanyId($invoice);

                return CompanyTemplateSettings::getDefaultNotes(
                    $companyId,
                    $this->convertToTemplateInvoiceType($invoice->getInvoiceType()),
                    (string) $invoice->user_id
                );
            } catch (\Exception $e) {
                // Fall back to null if database access fails
            }
        }

        return null;
    }

    /**
     * Get payment terms (individual, client-specific, or global)
     *
     * @param  Invoice  $invoice  The invoice
     * @return string|null Payment terms
     */
    protected function getPaymentTerms(Invoice $invoice): ?string
    {
        // Priority: individual -> client -> global
        if ($invoice->payment_terms) {
            return $invoice->payment_terms;
        }

        // Only try to get from database if the model exists and we're not in testing
        if (class_exists('\AichaDigital\Larabill\Models\CompanyTemplateSettings') && app()->bound('db')) {
            try {
                $companyId = $this->getCompanyId($invoice);

                return CompanyTemplateSettings::getPaymentTerms(
                    $companyId,
                    $this->convertToTemplateInvoiceType($invoice->getInvoiceType()),
                    (string) $invoice->user_id
                );
            } catch (\Exception $e) {
                // Fall back to null if database access fails
            }
        }

        return null;
    }

    /**
     * Get template settings for invoice
     *
     * @param  Invoice  $invoice  The invoice
     * @return array<string, mixed> Template settings
     */
    protected function getTemplateSettings(Invoice $invoice): array
    {
        // Only try to get from database if the model exists and we're not in testing
        if (class_exists('\AichaDigital\Larabill\Models\InvoiceTemplate') && app()->bound('db')) {
            try {
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
            } catch (\Exception $e) {
                // Fall back to empty array if database access fails
            }
        }

        return [];
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
        return $this->getConfigValue('larabill.default_company_id', 'default');
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
     * Safely get config value with fallback
     *
     * @param  string  $key  Config key
     * @param  mixed  $default  Default value
     * @return mixed Config value or default
     */
    protected function getConfigValue(string $key, $default = null)
    {
        try {
            if (function_exists('config') && app()->bound('config')) {
                return config($key, $default);
            }
        } catch (\Exception $e) {
            // Fall back to default if config access fails
        }

        return $default;
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
        // Pure-unit context without a booted view layer: return a clearly-marked mock.
        if (! class_exists('\Illuminate\Support\Facades\View') || ! app()->bound('view')) {
            return $this->generateMockHTML($template, $data);
        }

        try {
            return View::make($template, $data)->render();
        } catch (\Throwable $e) {
            // A real render failure must NOT be masked as a plausible-but-fake invoice.
            // Log with context and surface the error so the caller reports failure.
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

    /**
     * Generate mock HTML for testing
     *
     * @param  string  $template  Template name
     * @param  array<string, mixed>  $data  Template data
     * @return string Mock HTML
     */
    protected function generateMockHTML(string $template, array $data): string
    {
        $invoice       = $data['invoice']            ?? null;
        $invoiceNumber = $invoice ? $invoice->number ?? 'TEST-001' : 'TEST-001';
        $total         = $invoice ? $invoice->total  ?? '100.00' : '100.00';

        return "
        <!DOCTYPE html>
        <html>
        <head><title>Invoice {$invoiceNumber}</title></head>
        <body>
            <h1>Invoice {$invoiceNumber}</h1>
            <p>Total: €{$total}</p>
            <p>Template: {$template}</p>
        </body>
        </html>
        ";
    }

    /**
     * Check if we're in a production environment
     *
     * @return bool True if production
     */
    protected function isProductionEnvironment(): bool
    {
        try {
            if (function_exists('app') && app()->bound('env')) {
                return app()->environment('production');
            }
        } catch (\Exception $e) {
            // Fall back to false if environment check fails
        }

        // Assume testing/development if we can't determine environment
        return false;
    }
}
