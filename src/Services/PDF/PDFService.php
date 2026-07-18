<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\PDF;

use AichaDigital\Larabill\Contracts\PDFConnectorInterface;
use AichaDigital\Larabill\Exceptions\MissingFiscalVerificationQrException;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Support\FiscalQrImage;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Main PDF service that manages PDF generation with QR codes
 *
 * This service handles the selection of appropriate PDF connectors,
 * fallback mechanisms, and PDF generation with QR codes.
 *
 * @internal Implementation detail — may change without a major version (AID-413).
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
     * The rendering engine is injectable (AID-391): tests and consumers can
     * substitute a DomPDFService double instead of being locked to a
     * hard-wired instance. When omitted, one is built with this service's
     * merged config (the engine shares the caller's configuration).
     *
     * @param  array<string, mixed>  $config  Configuration array
     */
    public function __construct(array $config = [], ?CacheRepository $cache = null, ?DomPDFService $dompdfService = null)
    {
        $this->config = array_merge([
            'default_connector' => 'local',
            'cache_pdfs'        => true,
            'cache_ttl'         => 3600, // 1 hour
            'enable_logging'    => true,
        ], $config);

        $this->cache         = $cache;
        $this->dompdfService = $dompdfService ?? new DomPDFService($this->config);
        $this->initializeConnectors();
    }

    /**
     * Generate PDF for an invoice with QR code
     *
     * @param  Invoice  $invoice  The invoice to generate PDF for
     * @param  string|null  $connectorType  Specific connector type to use
     * @return array<string, mixed> PDF generation result
     */
    public function generatePDF(Invoice $invoice, ?string $connectorType = null): array
    {
        try {
            // Get appropriate connector
            $connector = $this->getConnector($connectorType);

            if (! $connector) {
                throw new \Exception('No suitable PDF connector found');
            }

            // The QR is an effect of the fiscal record; larabill never fabricates it
            // (AID-508). Two facts must hold, not one: a coherent registration AND a
            // usable image. A valid SVG with no registration ids is a lost datum.
            $isFiscal     = $invoice->serie->isFiscal();
            $isRegistered = $invoice->isFiscallyVerified();
            $qrResult     = $this->fiscalVerificationQrResult($invoice);
            $strict       = (bool) config('larabill.pdf.require_fiscal_verification_qr', false);

            if ($isFiscal && $strict && (! $isRegistered || $qrResult === null)) {
                throw MissingFiscalVerificationQrException::forInvoice($invoice);
            }

            $qrData = $isFiscal && $isRegistered && $qrResult !== null
                ? $qrResult
                : ['success' => true, 'qr_code' => null, 'qr_url' => null, 'qr_data' => []];

            // Generate PDF with QR code using DomPDF
            $pdfResult = $this->dompdfService->generatePDF($invoice, $qrData);

            // Cache result if enabled
            if ($this->config['cache_pdfs']) {
                $this->cachePDFResult($invoice, $pdfResult);
            }

            return [
                'success'        => true,
                'pdf_path'       => $pdfResult['pdf_path'],
                'pdf_url'        => $pdfResult['pdf_url'],
                'qr_data'        => $qrData,
                'connector_used' => $connector->getConnectorType(),
                'generated_at'   => now()->toISOString(),
            ];

        } catch (\Throwable $e) {
            // The frontier: the subsystem's SINGLE logger and translator
            // (AID-508/AID-535). \Throwable, not \Exception — a raw
            // \Error/TypeError must reach the consumer as an explicit failure,
            // never as an uncaught throwable. Inner layers no longer log; the
            // exception itself carries their context (e.g. the failing view's
            // name). No fallback: retrying with another connector after a
            // failure only fabricated a plausible result and buried the cause.
            Log::error('larabill: invoice PDF generation failed', [
                'invoice_id'         => $invoice->id,
                'invoice_number'     => $invoice->fiscal_number,
                'connector_type'     => $connectorType,
                'exception_class'    => $e::class,
                'exception'          => $e->getMessage(),
                'exception_location' => $e->getFile().':'.$e->getLine(),
            ]);

            return [
                'success'        => false,
                'error'          => $e->getMessage(),
                'connector_used' => $connectorType,
                'generated_at'   => now()->toISOString(),
            ];
        }
    }

    /**
     * Build QR data from the fiscal verification persisted by lara-verifactu.
     *
     * @return array<string, mixed>|null
     */
    protected function fiscalVerificationQrResult(Invoice $invoice): ?array
    {
        $qr   = $invoice->fiscal_verification_qr;
        $kind = FiscalQrImage::classify(is_string($qr) ? $qr : null);

        if ($kind === null) {
            // Absent, a bare cotejo URL, an unknown format, invalid base64 or
            // malformed XML: larabill does not render QR codes, so it cannot use it.
            return null;
        }

        $metadata = $invoice->fiscal_verification_metadata ?? [];
        $qrUrl    = $metadata['qr_url']                    ?? null;

        $result = [
            'success'        => true,
            'source'         => 'fiscal_verification',
            'qr_code'        => $qr,
            'qr_url'         => is_string($qrUrl) ? $qrUrl : null,
            'qr_data'        => [],
            'connector_type' => 'fiscal_verification',
            'generated_at'   => now()->toISOString(),
            'metadata'       => $metadata,
        ];

        $result[$kind === 'svg' ? 'qr_svg' : 'qr_png'] = $qr;

        return $result;
    }

    /**
     * Get available connectors
     *
     * @return array<string, mixed> List of available connectors
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
     * @param  array<string, mixed>  $result  PDF generation result
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
     * @return array<string, mixed>|null Cached result or null
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
