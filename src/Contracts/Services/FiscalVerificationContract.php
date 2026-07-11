<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Contracts\Services;

/**
 * Fiscal Verification Contract
 *
 * Interface for digital fiscal verification systems (Verifactu, TicketBAI, etc.)
 * Larabill defines the contract but doesn't implement country-specific logic.
 *
 * Country-specific implementations should be in separate packages:
 * - Spain: aichadigital/lara-verifactu
 * - France: aichadigital/lara-chorus-pro (example)
 * - Italy: aichadigital/lara-sdi (example)
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
interface FiscalVerificationContract
{
    /**
     * Check if the verification service is available.
     */
    public function isAvailable(): bool;

    /**
     * Verify an invoice and get fiscal verification data.
     *
     * @param  array<string, mixed>  $invoiceData
     * @return array{
     *     success: bool,
     *     id: string|null,
     *     qr: string|null,
     *     hash: string|null,
     *     signature: string|null,
     *     timestamp: string|null,
     *     metadata: array<string, mixed>|null,
     *     error: string|null
     * }
     */
    public function verify(array $invoiceData): array;

    /**
     * Get verification status for a previously verified invoice.
     *
     * @param  string  $verificationId  The fiscal verification ID
     * @return array{
     *     verified: bool,
     *     status: string,
     *     timestamp: string|null,
     *     metadata: array<string, mixed>|null
     * }
     */
    public function getStatus(string $verificationId): array;

    /**
     * Cancel/invalidate a fiscal verification.
     *
     * @param  string  $verificationId  The fiscal verification ID
     * @param  string  $reason  Cancellation reason
     * @return array{
     *     success: bool,
     *     message: string|null,
     *     error: string|null
     * }
     */
    public function cancel(string $verificationId, string $reason): array;

    /**
     * Get supported countries for this verification service.
     *
     * @return array<string> Array of ISO 3166-1 alpha-2 country codes
     */
    public function getSupportedCountries(): array;

    /**
     * Get configuration for the verification service.
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array;

    /**
     * Validate invoice data before sending for verification.
     *
     * @param  array<string, mixed>  $invoiceData
     * @return array{
     *     valid: bool,
     *     errors: array<string>
     * }
     */
    public function validateInvoiceData(array $invoiceData): array;
}
