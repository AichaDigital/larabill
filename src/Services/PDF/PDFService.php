<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\PDF;

use AichaDigital\Larabill\Contracts\PDFConnectorInterface;
use AichaDigital\Larabill\Models\Invoice;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\{Cache, Log};

/**
 * Main PDF service that manages PDF generation with QR codes
 *
 * This service handles the selection of appropriate PDF connectors,
 * fallback mechanisms, and PDF generation with QR codes.
 */
class PDFService
{
    /**
     * Available PDF connectors
     *
     * @var array<string, PDFConnectorInterface>
     */
    protected array $connectors = [];

    /**
     * Default connector
     */
    protected ?PDFConnectorInterface $defaultConnector = null;

    /**
     * Configuration
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Cache repository
     */
    protected ?CacheRepository $cache = null;

    /**
     * DomPDF service
     */
    protected DomPDFService $dompdfService;

    /**
     * Create a new PDF service instance
     *
     * @param  array  $config  Configuration array
     */
    public function __construct(array $config = [], ?CacheRepository $cache = null)
    {
        $this->config = array_merge([
            'default_connector' => 'local',
            'fallback_to_local' => true,
            'cache_pdfs'        => true,
            'cache_ttl'         => 3600, // 1 hour
            'enable_logging'    => true,
        ], $config);

        $this->cache         = $cache;
        $this->dompdfService = new DomPDFService($this->config);
        $this->initializeConnectors();
    }

    /**
     * Generate PDF for an invoice with QR code
     *
     * @param  Invoice  $invoice  The invoice to generate PDF for
     * @param  string|null  $connectorType  Specific connector type to use
     * @return array PDF generation result
     */
    public function generatePDF(Invoice $invoice, ?string $connectorType = null): array
    {
        try {
            // Get appropriate connector
            $connector = $this->getConnector($connectorType);

            if (! $connector) {
                throw new \Exception('No suitable PDF connector found');
            }

            // Generate QR code if the invoice type requires it
            $qrResult = ['success' => true, 'qr_code' => null, 'qr_url' => null, 'qr_data' => []];
            if ($invoice->shouldIncludeQR()) {
                // Validate invoice for QR generation
                if (! $connector->validateInvoice($invoice)) {
                    throw new \Exception('Invoice validation failed for connector: '.$connector->getConnectorType());
                }

                // Generate QR code
                $qrResult = $connector->generateQR($invoice);

                if (! $qrResult['success']) {
                    throw new \Exception('QR generation failed: '.($qrResult['error'] ?? 'Unknown error'));
                }
            }

            // Generate PDF with QR code using DomPDF
            $pdfResult = $this->dompdfService->generatePDF($invoice, $qrResult);

            // Cache result if enabled
            if ($this->config['cache_pdfs']) {
                $this->cachePDFResult($invoice, $pdfResult);
            }

            // Log success (disabled for testing)
            // if ($this->config['enable_logging'] && class_exists('Illuminate\Support\Facades\Log')) {
            //     Log::info('PDF generated successfully', [
            //         'invoice_id' => $invoice->id,
            //         'connector_type' => $connector->getConnectorType(),
            //         'qr_generated' => true,
            //     ]);
            // }

            return [
                'success'        => true,
                'pdf_path'       => $pdfResult['pdf_path'],
                'pdf_url'        => $pdfResult['pdf_url'],
                'qr_data'        => $qrResult,
                'connector_used' => $connector->getConnectorType(),
                'generated_at'   => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            // Log error (disabled for testing)
            // if ($this->config['enable_logging'] && class_exists('Illuminate\Support\Facades\Log')) {
            //     Log::error('PDF generation failed', [
            //         'invoice_id' => $invoice->id,
            //         'connector_type' => $connectorType,
            //         'error' => $e->getMessage(),
            //     ]);
            // }

            // Try fallback if enabled
            if ($this->config['fallback_to_local'] && $connectorType !== 'local') {
                return $this->generatePDF($invoice, 'local');
            }

            return [
                'success'        => false,
                'error'          => $e->getMessage(),
                'connector_used' => $connectorType,
                'generated_at'   => now()->toISOString(),
            ];
        }
    }

    /**
     * Get available connectors
     *
     * @return array List of available connectors
     */
    public function getAvailableConnectors(): array
    {
        $available = [];

        foreach ($this->connectors as $type => $connector) {
            if ($connector->isAvailable()) {
                $available[$type] = [
                    'type'                => $type,
                    'metadata'            => $connector->getMetadata(),
                    'supported_countries' => $connector->getSupportedCountries(),
                ];
            }
        }

        return $available;
    }

    /**
     * Get connector by type
     *
     * @param  string|null  $type  Connector type
     */
    public function getConnector(?string $type = null): ?PDFConnectorInterface
    {
        $type = $type ?? $this->config['default_connector'];

        if (isset($this->connectors[$type]) && $this->connectors[$type]->isAvailable()) {
            return $this->connectors[$type];
        }

        // Return null if requested type is not available and no default
        return null;
    }

    /**
     * Register a new connector
     *
     * @param  string  $type  Connector type
     * @param  PDFConnectorInterface  $connector  Connector instance
     */
    public function registerConnector(string $type, PDFConnectorInterface $connector): void
    {
        $this->connectors[$type] = $connector;
    }

    /**
     * Get connector configuration
     *
     * @return array Configuration array
     */
    public function getConfiguration(): array
    {
        return $this->config;
    }

    /**
     * Update configuration
     *
     * @param  array  $config  New configuration
     */
    public function updateConfiguration(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Initialize default connectors
     */
    protected function initializeConnectors(): void
    {
        // Register default local connector
        $this->defaultConnector    = new DefaultPDFConnector;
        $this->connectors['local'] = $this->defaultConnector;
    }

    /**
     * Generate PDF with QR code
     *
     * @param  Invoice  $invoice  The invoice
     * @param  array  $qrResult  QR generation result
     * @return array PDF generation result
     */
    /**
     * Get DomPDF service
     */
    public function getDomPDFService(): DomPDFService
    {
        return $this->dompdfService;
    }

    /**
     * Cache PDF result
     *
     * @param  Invoice  $invoice  The invoice
     * @param  array  $result  PDF generation result
     */
    protected function cachePDFResult(Invoice $invoice, array $result): void
    {
        if ($this->cache) {
            $cacheKey = 'pdf_result_'.$invoice->id;
            $this->cache->put($cacheKey, $result, $this->config['cache_ttl']);
        }
    }

    /**
     * Get cached PDF result
     *
     * @param  Invoice  $invoice  The invoice
     * @return array|null Cached result or null
     */
    public function getCachedPDFResult(Invoice $invoice): ?array
    {
        if (! $this->config['cache_pdfs'] || ! $this->cache) {
            return null;
        }

        $cacheKey = 'pdf_result_'.$invoice->id;

        return $this->cache->get($cacheKey);
    }

    /**
     * Clear PDF cache
     *
     * @param  Invoice  $invoice  The invoice
     */
    public function clearPDFCache(Invoice $invoice): void
    {
        if ($this->cache) {
            $cacheKey = 'pdf_result_'.$invoice->id;
            $this->cache->forget($cacheKey);
        }
    }
}
