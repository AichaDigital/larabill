<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Actions;

use Aichadigital\Lararoi\Contracts\VatVerificationServiceInterface;
use Aichadigital\Lararoi\Exceptions\VatVerificationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Thin bridge to the lararoi intra-community VAT/NIF verification service.
 *
 * larabill does not own VAT verification: the domain lives in the sibling
 * `aichadigital/lararoi` package. This action is the single, named seam a
 * consumer app calls to verify a VAT number through larabill's namespace,
 * delegating verbatim to lararoi's canonical contract.
 *
 * Deliberately minimal: no tracking context, no input normalization, no
 * output mapping. It returns lararoi's canonical result array unchanged.
 * Invoice issuance is NOT wired to this — reverse charge is decided by the
 * `is_roi_taxed` input flag on the invoice, not by a live VIES lookup.
 *
 * Runs as: direct call — `VerifyVatNumber::run($vatNumber, $countryCode)`.
 *
 * **Queue warning (AID-1464):** because of `AsAction`, `VerifyVatNumber::dispatch()`
 * is available and wraps this action in a `JobDecorator` that serializes its
 * arguments verbatim — the VAT/NIF number would travel in plaintext into the
 * `jobs` (and `failed_jobs`) payloads, with no encryption by default. Prefer the
 * direct `::run()` call (the current consumer path). Adding `ShouldBeEncrypted`
 * to this action is NOT sufficient protection: Laravel inspects the queued
 * `JobDecorator`, not the wrapped action, when deciding to encrypt a payload.
 * The async path (payload encryption or argument minimisation) is evaluated in
 * its own ticket — do not assume dispatch is safe because the sync path is.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class VerifyVatNumber
{
    use AsAction;

    public function __construct(
        private readonly VatVerificationServiceInterface $vatVerification,
    ) {}

    /**
     * Verify a VAT/NIF number, delegating to lararoi.
     *
     * @param  string  $vatNumber  VAT number WITHOUT the country prefix (e.g. "B12345678")
     * @param  string  $countryCode  Two-letter country code (e.g. "ES")
     * @return array{
     *     is_valid: bool,
     *     vat_code: string,
     *     country_code: string,
     *     company_name: string|null,
     *     company_address: string|null,
     *     api_source: string,
     *     cached: bool,
     *     request_date: string|null,
     *     response_data?: array<string, mixed>
     * }
     *
     * @throws VatVerificationException
     */
    public function handle(string $vatNumber, string $countryCode): array
    {
        return $this->vatVerification->verifyVatNumber($vatNumber, $countryCode);
    }
}
