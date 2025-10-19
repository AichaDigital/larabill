<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\DataTransferObjects;

/**
 * SourceReference DTO
 *
 * Represents the source reference information for an invoice item.
 * This DTO stores the origin of the invoice item (article, service, etc.).
 */
final readonly class SourceReference
{
    public function __construct(
        public string $type,
        public ?int $articleId = null,
        public ?int $serviceStatusId = null,
        public ?string $instanceIdentifier = null,
        public ?array $additional = null,
    ) {}

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'],
            articleId: $data['article_id']                   ?? null,
            serviceStatusId: $data['service_status_id']      ?? null,
            instanceIdentifier: $data['instance_identifier'] ?? null,
            additional: $data['additional']                  ?? null,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'type'                => $this->type,
            'article_id'          => $this->articleId,
            'service_status_id'   => $this->serviceStatusId,
            'instance_identifier' => $this->instanceIdentifier,
            'additional'          => $this->additional,
        ];
    }
}
