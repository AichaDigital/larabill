<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\DataTransferObjects;

/**
 * InvoiceItemMetadata DTO
 *
 * Main metadata structure for invoice items.
 * This DTO aggregates all other metadata DTOs and provides a unified
 * interface for accessing invoice item metadata.
 */
final readonly class InvoiceItemMetadata
{
    public function __construct(
        public ?SourceReference $sourceReference = null,
        public ?PricingDetails $pricingDetails = null,
        public ?BillingDetails $billingDetails = null,
        public ?array $auditTrail = null,
        public ?CustomData $customData = null,
    ) {}

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceReference: isset($data['source_reference'])
                ? SourceReference::fromArray($data['source_reference'])
                : null,
            pricingDetails: isset($data['pricing_details'])
                ? PricingDetails::fromArray($data['pricing_details'])
                : null,
            billingDetails: isset($data['billing_details'])
                ? BillingDetails::fromArray($data['billing_details'])
                : null,
            auditTrail: isset($data['audit_trail'])
                ? array_map(fn (array $entry) => AuditEntry::fromArray($entry), $data['audit_trail'])
                : null,
            customData: isset($data['custom_data'])
                ? CustomData::fromArray($data['custom_data'])
                : null,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'source_reference' => $this->sourceReference?->toArray(),
            'pricing_details'  => $this->pricingDetails?->toArray(),
            'billing_details'  => $this->billingDetails?->toArray(),
            'audit_trail'      => $this->auditTrail
                ? array_map(fn (AuditEntry $entry) => $entry->toArray(), $this->auditTrail)
                : null,
            'custom_data' => $this->customData?->toArray(),
        ];
    }

    /**
     * Add an audit entry.
     */
    public function withAuditEntry(AuditEntry $entry): self
    {
        $trail   = $this->auditTrail ?? [];
        $trail[] = $entry;

        return new self(
            sourceReference: $this->sourceReference,
            pricingDetails: $this->pricingDetails,
            billingDetails: $this->billingDetails,
            auditTrail: $trail,
            customData: $this->customData,
        );
    }
}
