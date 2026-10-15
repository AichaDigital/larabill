<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use InvalidArgumentException;

/**
 * An invoice row violates the ADR-016 §8.2 document-axis coherence clause set.
 *
 * The clause set (amended by V1/V9/V10/V11/V12/V13/V17/S4-r/S5 of the spec's
 * gate, §16) binds the document axis (`invoice_document_status`), the five
 * number/issuance fields, the submission axis (`fiscal_submission_status`)
 * and the cancellation metadata (`document_cancelled_at/by`) into one system.
 * It is database-enforced on MySQL (named CHECK
 * `larabill_invoice_document_axis_coherence`) and application-enforced on
 * every engine by the `Invoice` saving guard — this exception is what the
 * guard throws.
 *
 * Extends `InvalidArgumentException` on purpose: consumers already catching
 * that keep working, while anyone who wants to tell this apart from other bad
 * arguments can catch the specific type.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class InvoiceDocumentCoherenceException extends InvalidArgumentException
{
    /**
     * The §8.2 clause names this row violates (C1, C2, C4, V9, V11, S4-r, S5,
     * V13 — as adjudicated in the spec's §16 gate tables).
     *
     * @var array<int, string>
     */
    private array $violatedClauses = [];

    /**
     * @param  array<int, array{id: string, description: string}>  $violations
     * @param  array<string, mixed>  $row
     */
    public static function violating(array $violations, array $row): self
    {
        $exception = new self(sprintf(
            'Invoice row violates the ADR-016 §8.2 document-axis coherence clauses (%s). Row shape: %s',
            implode('; ', array_map(
                static fn (array $violation): string => $violation['id'].': '.$violation['description'],
                array_values($violations),
            )),
            self::describeShape($row),
        ));
        $exception->violatedClauses = array_map(
            static fn (array $violation): string => $violation['id'],
            array_values($violations),
        );

        return $exception;
    }

    /**
     * @return array<int, string>
     */
    public function violatedClauses(): array
    {
        return $this->violatedClauses;
    }

    /**
     * Structural description of the offending row — presence/absence of the
     * governed fields, never their values (no fiscal numbers in messages).
     *
     * @param  array<string, mixed>  $row
     */
    private static function describeShape(array $row): string
    {
        $shape = [];
        foreach (['serie', 'invoice_document_status', 'fiscal_submission_status'] as $field) {
            $shape[$field] = array_key_exists($field, $row) ? $row[$field] : '(absent)';
        }
        foreach (['fiscal_number', 'series_number', 'fiscal_year', 'invoice_date', 'issued_at', 'document_cancelled_at', 'document_cancelled_by'] as $field) {
            $shape[$field] = ($row[$field] ?? null) === null ? 'null' : '[set]';
        }

        return json_encode($shape, JSON_THROW_ON_ERROR);
    }
}
