<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\DataTransferObjects;

/**
 * CustomData DTO
 *
 * Represents custom metadata for flexible extension.
 * Can be used to store any additional information specific to the application.
 */
final readonly class CustomData
{
    public function __construct(
        public array $data = [],
    ) {}

    /**
     * Create from array.
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
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
