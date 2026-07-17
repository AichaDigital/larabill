<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\PDF;

use AichaDigital\Larabill\Contracts\PDFConnectorInterface;
use AichaDigital\Larabill\Models\Invoice;
use LogicException;

// use Illuminate\Support\Facades\Log;

/**
 * Default PDF connector: metadata and validation, no fiscal QR generation.
 *
 * This is the built-in connector used when no external one is registered. It
 * validates invoice data and reports connector metadata. It does NOT generate
 * fiscal QR codes: the tax QR is an effect of the fiscal billing record
 * (`fiscal_verification_qr`), built by the registrar (lara-verifactu) from the
 * AEAT cotejo URL — never fabricated by the PDF pipeline. `generateQR()`
 * refuses by design (AID-508); see its own docblock.
 *
 * @internal Implementation detail — may change without a major version (AID-413).
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
     * larabill does not generate fiscal QR codes.
     *
     * The tax QR is an effect of the fiscal billing record, and it is the registrar
     * (lara-verifactu) who builds it from the AEAT cotejo URL — nif, numserie, fecha,
     * importe — per the AEAT QR spec v0.4.7. This connector used to fabricate a
     * placeholder that was not a QR at all: plain text, unscannable, with the JSON
     * truncated to 100 bytes. Presenting a code without the mandatory cotejo URL is
     * the breach; fabricating it locally is not (the registrar does exactly that).
     *
     * The interface stays (@api): only this implementation refuses (AID-508).
     */
    public function generateQR(Invoice $invoice): array
    {
        throw new LogicException(
            'larabill does not generate fiscal QR codes: the tax QR comes from the fiscal '
            .'billing record (fiscal_verification_qr), never from the PDF pipeline.'
        );
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
