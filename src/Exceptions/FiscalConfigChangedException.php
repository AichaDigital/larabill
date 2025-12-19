<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\Invoice;
use Exception;

/**
 * Exception thrown when attempting to convert a proforma to invoice
 * but critical fiscal configuration has changed since proforma creation.
 *
 * @see ADR-001 Gestión de proformas con cambio fiscal
 */
class FiscalConfigChangedException extends Exception
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        protected Invoice $proforma,
        protected array $changes,
        string $message = ''
    ) {
        $message = $message ?: $this->buildMessage();
        parent::__construct($message);
    }

    /**
     * Get the proforma that triggered this exception.
     */
    public function getProforma(): Invoice
    {
        return $this->proforma;
    }

    /**
     * Get the detected changes.
     *
     * @return array<string, mixed>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * Check if company config changed.
     */
    public function hasCompanyChanges(): bool
    {
        return $this->changes['company']['changed'] ?? false;
    }

    /**
     * Check if user profile changed.
     */
    public function hasUserChanges(): bool
    {
        return $this->changes['user']['changed'] ?? false;
    }

    /**
     * Check if any critical fields changed.
     */
    public function hasCriticalChanges(): bool
    {
        return $this->changes['has_critical_changes'] ?? false;
    }

    /**
     * Get list of critical fields that changed.
     *
     * @return array<string>
     */
    public function getCriticalFields(): array
    {
        $critical = [];

        foreach (['company', 'user'] as $type) {
            if (! isset($this->changes[$type]['changes'])) {
                continue;
            }

            foreach ($this->changes[$type]['changes'] as $field => $change) {
                if ($change['level'] === 'critical') {
                    $critical[] = "{$type}.{$field}";
                }
            }
        }

        return $critical;
    }

    /**
     * Build the exception message.
     */
    protected function buildMessage(): string
    {
        $proformaId     = $this->proforma->id;
        $criticalFields = $this->getCriticalFields();

        if (empty($criticalFields)) {
            return sprintf(
                'Fiscal configuration changed since proforma %s was created.',
                $proformaId
            );
        }

        return sprintf(
            'Critical fiscal configuration changed since proforma %s was created. Changed fields: %s. Use force=true to convert anyway.',
            $proformaId,
            implode(', ', $criticalFields)
        );
    }

    /**
     * Create exception from detected changes.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function fromChanges(Invoice $proforma, array $changes): self
    {
        return new self($proforma, $changes);
    }
}
