<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\DataTransferObjects;

/**
 * CustomData DTO
 *
 * Represents custom metadata for flexible extension.
 * Can be used to store any additional information specific to the application.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final readonly class CustomData
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data = [],
    ) {}

    /**
     * Create from array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(data: $data);
    }

    /**
     * Get a value from the custom data.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * Check if a key exists.
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
