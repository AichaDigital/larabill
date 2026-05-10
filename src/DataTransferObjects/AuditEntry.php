<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\DataTransferObjects;

use Carbon\Carbon;

/**
 * AuditEntry DTO
 *
 * Represents a single audit trail entry.
 * Used to track changes and actions performed on invoice items.
 */
final readonly class AuditEntry
{
    public function __construct(
        public Carbon $timestamp,
        public string $action,
        public ?string $userId = null,
        public ?string $userName = null,
        public ?array $changes = null,
        public ?string $reason = null,
        public ?array $metadata = null,
    ) {}

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            timestamp: isset($data['timestamp']) ? Carbon::parse($data['timestamp']) : now(),
            action: $data['action'],
            userId: $data['user_id']     ?? null,
            userName: $data['user_name'] ?? null,
            changes: $data['changes']    ?? null,
            reason: $data['reason']      ?? null,
            metadata: $data['metadata']  ?? null,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp->toIso8601String(),
            'action'    => $this->action,
            'user_id'   => $this->userId,
            'user_name' => $this->userName,
            'changes'   => $this->changes,
            'reason'    => $this->reason,
            'metadata'  => $this->metadata,
        ];
    }
}
