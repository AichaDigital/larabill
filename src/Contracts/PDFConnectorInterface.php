<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Contracts;

use AichaDigital\Larabill\Models\Invoice;

/**
 * Interface for PDF connectors that generate QR codes and validate invoices
 *
 * This interface provides a standard way to integrate with external tax agencies
 * or use local QR generation for invoices.
 */
interface PDFConnectorInterface
{
    /**
     * Generate QR code data for an invoice
     *
     * @param  Invoice  $invoice  The invoice to generate QR for
     * @return array<string,mixed> QR data including code, url, and metadata
     */
    public function generateQR(Invoice $invoice): array;

    /**
     * Validate invoice data against connector requirements
     *
     * @param  Invoice  $invoice  The invoice to validate
     * @return bool True if invoice is valid for this connector
     */
    public function validateInvoice(Invoice $invoice): bool;

    /**
     * Get required fields for this connector
     *
     * @return array<int,string> List of required field names
     */
    public function getRequiredFields(): array;

    /**
     * Get the endpoint URL for external connectors
     *
     * @return string|null Endpoint URL or null for local connectors
     */
    public function getEndpoint(): ?string;

    /**
     * Get authentication data for external connectors
     *
     * @return array<string,mixed> Authentication data (keys, tokens, etc.)
     */
    public function getAuthentication(): array;

    /**
     * Check if this connector is available
     *
     * @return bool True if connector is available and configured
     */
    public function isAvailable(): bool;

    /**
     * Get the connector type identifier
     *
     * @return string Connector type (e.g., 'local', 'spain_aeat', 'france_dgfip')
     */
    public function getConnectorType(): string;

    /**
     * Get connector configuration
     *
     * @return array<string,mixed> Configuration data for this connector
     */
    public function getConfiguration(): array;

    /**
     * Get supported countries/regions for this connector
     *
     * @return array<int,string> List of supported country codes
     */
    public function getSupportedCountries(): array;

    /**
     * Get connector metadata
     *
     * @return array<string,mixed> Metadata including name, version, description
     */
    public function getMetadata(): array;
}
