<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use Illuminate\Database\QueryException;

/**
 * Sanitise a database-failure message before it leaves the package.
 *
 * Database exceptions interpolate binding values into their message:
 * QueryException renders the SQL with the bindings substituted, and the
 * wrapped PDOException's message (with the driver's own text) rides along
 * as part of it. Consumers copy failure strings into their own logs and
 * into the operations mail, so no query value, raw SQL or driver message
 * may leave the package (AID-1442 and its follow-up in the recurring
 * billing flow). Database failures report the caught exception's class,
 * the SQLSTATE and the driver code only; anything else
 * (FiscalContentMissingException, render failures, raw Errors) keeps its
 * own message, which is package-controlled.
 *
 * Shared by every frontier that reports a \Throwable outward: PDFService
 * (AID-1442) and RecurringBillingService.
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
final class DatabaseFailureSanitiser
{
    /**
     * Sanitise a failure message. Never returns the message of a database
     * exception — no raw SQL, no bindings, no errorInfo[2].
     */
    public static function message(\Throwable $e): string
    {
        // A QueryException anywhere in the chain wins over the ENTIRE chain,
        // even when a plain PDOException wraps it (a PDOException whose
        // previous is a QueryException): the query carries the real SQLSTATE
        // and driver code, while the outer driver failure reports a useless
        // SQLSTATE 0. Two passes — the first pass must not stop at an outer
        // PDOException before reaching the query underneath it.
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof QueryException) {
                $sqlState   = $current->errorInfo[0] ?? (string) $current->getCode();
                $driverCode = $current->errorInfo[1] ?? null;

                return sprintf('%s: query failed (SQLSTATE %s, driver code %s)', $e::class, $sqlState, $driverCode ?? 'n/a');
            }
        }

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \PDOException) {
                $sqlState   = $current->errorInfo[0] ?? (string) $current->getCode();
                $driverCode = $current->errorInfo[1] ?? null;

                return sprintf('%s: database driver failure (SQLSTATE %s, driver code %s)', $e::class, $sqlState, $driverCode ?? 'n/a');
            }
        }

        return $e->getMessage();
    }
}
