<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\DataTransferObjects;

/**
 * SourceReference DTO
 *
 * Represents the source reference information for an invoice item.
 * This DTO stores the origin of the invoice item (article, service, etc.).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final readonly class SourceReference
{
    /**
     * @param  array<string, mixed>|null  $additional
     */
    public function __construct(
        public string $type,
        public ?int $articleId = null,
        public ?int $serviceStatusId = null,
        public ?string $instanceIdentifier = null,
        public ?array $additional = null,
    ) {}

    /**
     * Create from array.
     *
     * @param  array<string, mixed>  $data
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
     *
     * @return array{type: string, article_id: int|null, service_status_id: int|null, instance_identifier: string|null, additional: array<string, mixed>|null}
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
