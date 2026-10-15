<?php

declare(strict_types=1);

/*
 * ADR-016 §6.1/§13 (rounds 12/13, V17 + V17-residual) — the numbering
 * call-site contract, pinned at the source level.
 *
 * The ONLY numbering call sites in the package are the PROFORMA birth inside
 * `createInvoice()`'s shared row builder (§3.1, guarded at runtime by
 * `$numberAtBirth`) and `issue()`'s ambient-transaction path (§6). The
 * FISCAL branch must have NO numbering call — an absolute "the service is
 * unreachable" claim would break the proforma exception, so this pin asserts
 * the call-SHAPE: one birth call behind the runtime guard, one in-transaction
 * issuance call, and nothing else.
 */

it('keeps exactly one numbering call site in InvoiceService — the guarded proforma birth — and one in-transaction issuance call', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Services/InvoiceService.php');

    // One direct wrapper call: the proforma birth inside createInvoiceRow(),
    // guarded at runtime by $numberAtBirth (the branches never mix, §7.2).
    preg_match_all('/->generateNumber\(/', (string) $source, $direct);
    expect($direct[0])->toHaveCount(1);

    // One internal call: the issuance path inside issue(), joining the
    // ambient transaction (§6.1, S1).
    preg_match_all('/->generateNumberInTransaction\(/', (string) $source, $internal);
    expect($internal[0])->toHaveCount(1);

    // The birth call sits BEHIND the runtime guard — the fiscal branch can
    // never reach it (createInvoiceRow numbers only when $numberAtBirth).
    expect((bool) preg_match('/\$number = \$numberAtBirth\s*\n\s*\?\s*\$this->invoiceNumbering->generateNumber\(/', (string) $source))->toBeTrue();
});

it('keeps the issuance call on the internal ambient-transaction path, never the public wrapper', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Services/InvoiceNumberingService.php');

    // The wrapper delegates to the internal path; the internal path is the
    // only place the counter work lives.
    expect((bool) preg_match('/generateNumberInTransaction\(\$prefix, \$serie, \$userId, \$issuedAt\)/', (string) $source))->toBeTrue()
        ->and(substr_count((string) $source, 'public function generateNumber'))->toBe(2);
});
