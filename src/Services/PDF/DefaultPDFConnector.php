<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\PDF;

use AichaDigital\Larabill\Contracts\PDFConnectorInterface;
use AichaDigital\Larabill\Models\Invoice;

// use Illuminate\Support\Facades\Log;

/**
 * Default PDF connector that generates QR codes locally
 *
 * This connector provides basic QR generation without external dependencies.
 * It's suitable for internal use, testing, and as a fallback when external
 * connectors are not available.
 */
class DefaultPDFConnector implements PDFConnectorInterface
{
    /**
     * Configuration for the connector
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Create a new default PDF connector instance
     *
     * @param  array<string, mixed>  $config  Configuration array
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'qr_base_url'             => 'http://localhost',
            'qr_include_invoice_data' => true,
            'qr_include_tax_data'     => true,
            'qr_include_company_data' => true,
            'enable_local_validation' => true,
        ], $config);
    }

    /**
     * Generate QR code data for an invoice
     *
     * @param  Invoice  $invoice  The invoice to generate QR for
     * @return array<string, mixed> QR data including code, url, and metadata
     */
    public function generateQR(Invoice $invoice): array
    {
        try {
            // Validate invoice first
            if (! $this->validateInvoice($invoice)) {
                throw new \InvalidArgumentException('Invoice validation failed: missing required fields');
            }

            // Prepare QR data
            $qrData = $this->prepareQRData($invoice);

            // Generate QR code (using a simple hash for now, can be enhanced with actual QR library)
            $qrCode = $this->generateQRCode($qrData);

            // Create QR URL
            $qrUrl = $this->createQRUrl($invoice, $qrCode);

            return [
                'success'        => true,
                'qr_code'        => $qrCode,
                'qr_url'         => $qrUrl,
                'qr_data'        => $qrData,
                'connector_type' => $this->getConnectorType(),
                'generated_at'   => now()->toISOString(),
                'metadata'       => [
                    'invoice_id'     => $invoice->id,
                    'invoice_number' => $invoice->fiscal_number,
                    'total_amount'   => $invoice->total_amount->unscaledValue(),
                    'currency'       => 'EUR', // Default currency
                ],
            ];
        } catch (\Exception $e) {
            // Error occurred during QR generation

            return [
                'success'        => false,
                'error'          => $e->getMessage(),
                'connector_type' => $this->getConnectorType(),
                'generated_at'   => now()->toISOString(),
            ];
        }
    }

    /**
     * Validate invoice data against connector requirements
     *
     * @param  Invoice  $invoice  The invoice to validate
     * @return bool True if invoice is valid for this connector
     */
    public function validateInvoice(Invoice $invoice): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        // Basic validation for local connector
        $requiredFields = $this->getRequiredFields();

        foreach ($requiredFields as $field) {
            $value = $invoice->{$field} ?? null;
            if ($value === null || $value === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Get required fields for this connector
     *
     * @return array<int, string> List of required field names
     */
    public function getRequiredFields(): array
    {
        return [
            'id',
            'fiscal_number',
            'total_amount',
            'status',
        ];
    }

    /**
     * Get the endpoint URL for external connectors
     *
     * @return string|null Endpoint URL or null for local connectors
     */
    public function getEndpoint(): ?string
    {
        return null; // Local connector doesn't have an endpoint
    }

    /**
     * Get authentication data for external connectors
     *
     * @return array<string, mixed> Authentication data (keys, tokens, etc.)
     */
    public function getAuthentication(): array
    {
        return []; // Local connector doesn't require authentication
    }

    /**
     * Check if this connector is available
     *
     * @return bool True if connector is available and configured
     */
    public function isAvailable(): bool
    {
        return $this->config['enable_local_validation'] ?? true;
    }

    /**
     * Get the connector type identifier
     *
     * @return string Connector type (e.g., 'local', 'spain_aeat', 'france_dgfip')
     */
    public function getConnectorType(): string
    {
        return 'local';
    }

    /**
     * Get connector configuration
     *
     * @return array<string, mixed> Configuration data for this connector
     */
    public function getConfiguration(): array
    {
        return $this->config;
    }

    /**
     * Get supported countries/regions for this connector
     *
     * @return array<int, string> List of supported country codes
     */
    public function getSupportedCountries(): array
    {
        return ['*']; // Local connector supports all countries
    }

    /**
     * Get connector metadata
     *
     * @return array<string, mixed> Metadata including name, version, description
     */
    public function getMetadata(): array
    {
        return [
            'name'                         => 'Default PDF Connector',
            'version'                      => '1.0.0',
            'description'                  => 'Local QR generation without external dependencies',
            'type'                         => 'local',
            'supports_external_validation' => false,
            'supports_digital_signature'   => false,
            'requires_internet'            => false,
        ];
    }

    /**
     * Prepare QR data from invoice
     *
     * @param  Invoice  $invoice  The invoice to prepare data for
     * @return array<string, mixed> Prepared QR data
     */
    protected function prepareQRData(Invoice $invoice): array
    {
        $data = [
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->fiscal_number,
            'total_amount'   => $invoice->total_amount->unscaledValue(),
            'status'         => $invoice->status,
            'created_at'     => $invoice->created_at?->toISOString(),
        ];

        // Include invoice data if configured
        if ($this->config['qr_include_invoice_data']) {
            $data['invoice_data'] = [
                'taxable_amount' => $invoice->taxable_amount->unscaledValue(),
                'tax_amount'     => $invoice->tax_amount,
                'due_date'       => $invoice->due_date?->toISOString(),
            ];
        }

        // Include tax data if configured
        if ($this->config['qr_include_tax_data']) {
            $data['tax_data'] = [
                'fiscal_data'      => $invoice->fiscal_data,
                'vat_verification' => $invoice->vat_verification,
            ];
        }

        // Include company data if configured
        if ($this->config['qr_include_company_data']) {
            $data['company_data'] = [
                'user_id'                 => $invoice->user_id,
                'user_tax_info_encrypted' => $invoice->user_tax_info_encrypted,
            ];
        }

        return $data;
    }

    /**
     * Generate QR code from data
     *
     * @param  array<string, mixed>  $data  The data to encode
     * @return string Generated QR code
     */
    protected function generateQRCode(array $data): string
    {
        // For now, generate a simple hash-based QR code
        // In a real implementation, you would use a QR library like SimpleSoftwareIO/simple-qrcode
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $hash     = hash('sha256', $jsonData);

        // Create a simple QR-like string (this is a placeholder)
        return 'QR:'.substr($hash, 0, 16).':'.base64_encode(substr($jsonData, 0, 100));
    }

    /**
     * Create QR URL for the invoice
     *
     * @param  Invoice  $invoice  The invoice
     * @param  string  $qrCode  The QR code
     * @return string QR URL
     */
    protected function createQRUrl(Invoice $invoice, string $qrCode): string
    {
        $baseUrl = $this->config['qr_base_url'];

        return $baseUrl.'/invoice/'.$invoice->id.'/qr/'.$qrCode;
    }
}
