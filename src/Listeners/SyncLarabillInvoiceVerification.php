<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Listeners;

use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;

/**
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class SyncLarabillInvoiceVerification
{
    public function handle(InvoiceRegisteredEvent $event): void
    {
        $metadata  = $event->invoice->getMetadata();
        $invoiceId = $metadata['larabill_invoice_id'] ?? null;

        if (! is_string($invoiceId) || $invoiceId === '') {
            return;
        }

        $invoice = Invoice::query()->find($invoiceId);

        if (! $invoice) {
            return;
        }

        $registry = $event->registry;

        $invoice->update([
            'fiscal_verification_id'       => $registry->getRegistryNumber(),
            'fiscal_verification_qr'       => $registry->getQrSvg() ?? $registry->getQrPng() ?? $registry->getQrUrl(),
            'fiscal_verification_hash'     => $registry->getHash(),
            'fiscal_verified_at'           => $registry->getRegistryDate(),
            'fiscal_verification_metadata' => [
                'provider'             => 'lara-verifactu',
                'verifactu_invoice'    => $event->invoice->getId(),
                'qr_url'               => $registry->getQrUrl(),
                'registry_status'      => $registry->getStatus()->value,
                'submitted_to_aeat'    => $event->submittedToAeat,
                'registry_date'        => $registry->getRegistryDate()->toIso8601String(),
                'metadata'             => $metadata,
            ],
        ]);
    }
}
