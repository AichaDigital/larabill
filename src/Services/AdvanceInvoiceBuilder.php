<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Exceptions\CreditMovementAlreadyLinkedException;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\InvalidAdvanceInvoiceException;
use AichaDigital\Larabill\Exceptions\InvalidCreditMovementException;
use AichaDigital\Larabill\Exceptions\MissingFiscalSeriesException;
use AichaDigital\Larabill\Exceptions\ReverseChargeWithTaxException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\TaxGroup;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * AdvanceInvoiceBuilder — builds the art. 75.Dos LIVA advance-invoice DRAFT
 * from a collected credit-ledger entry (AID-1778, spec §4).
 *
 * The consumer decides WHEN an advance invoice exists (DECISION 4 of the
 * 8.3.0 handoff stands): the builder is a primitive, not a policy. It receives
 * the collected entry, the fiscal series, the description, the VAT group and
 * the reverse-charge qualification — exactly like an ordinary emission — and
 * returns an UNNUMBERED DRAFT carrying ONE frozen line with the reverse
 * base:
 *
 *     base = HalfUp(gross / (1 + Σ rates))   on exact Brick\Math\BigDecimal
 *     tax  = gross − base                    (exact residual, never re-rounded)
 *
 * so `base + tax === gross` holds by construction and the draft's
 * `total_amount` equals the entry's collected amount to the cent. Note the
 * deliberate reverse-inclusive cent: on the pinned case (100,00 € @ 21 % →
 * 82,64 + 17,36) the FORWARD calculation on the same base would give one cent
 * less (17,35); the reverse rule and the residual identity are the documented
 * rule (spec §4.3, J6) — T6 measures AEAT acceptance of the advance F1.
 *
 * The builder NEVER issues, never numbers and never links: the consumer's
 * documented sequence is build → link (`CreditMovement::linkAdvanceInvoice()`)
 * → issue (`InvoiceService::issue()`). It is deliberately NOT idempotent
 * (O5, operator decision L7): two calls on the same entry mint two drafts and
 * BOTH are valid artifacts — the one-to-one link boundary, not the builder,
 * is what stops the second draft from ever being issued as THE advance of the
 * entry. Cleaning up orphan drafts is the consumer's.
 *
 * Resolving the VAT regime from the consumer's tax profile is explicitly out
 * of 8.4 (S2 settled): the caller declares `is_roi_taxed`.
 *
 * @api Supported public surface (AID-1778; see docs/api-surface.md).
 */
class AdvanceInvoiceBuilder
{
    public function __construct(protected InvoiceService $invoiceService) {}

    /**
     * Build the unnumbered DRAFT that documents the money collected by an
     * entry of the credit ledger.
     *
     * All validation happens BEFORE any write: a refusal leaves no invoice, no
     * item and no draft transaction at all.
     *
     * @param  string|null  $series  The fiscal series for the INVOICE type
     *                               (RD 1619/2012 art. 6). `null` falls
     *                               through to the consumer's
     *                               configuration; when nothing resolves
     *                               `MissingFiscalSeriesException` fails
     *                               loud (AID-589 rule).
     * @param  string  $description  The single line's description.
     * @param  int|null  $taxGroupId  The VAT group; `null` (or an id that
     *                                does not resolve) builds a tax-free
     *                                advance — a DIFFERENT fiscal
     *                                statement from an explicit zero-rate
     *                                group (G8): the empty group carries
     *                                `taxes_applied: []`, an explicit
     *                                zero-rate group carries the
     *                                strategy's own zero entry.
     * @param  bool  $isRoiTaxed  The consumer-declared reverse-charge
     *                            qualification; `true` with a group
     *                            carrying real tax is refused before
     *                            any write (never a silent zero).
     *
     * @throws InvalidCreditMovementException When the movement is an exit or a reversed entry
     * @throws CreditMovementAlreadyLinkedException When the entry already documents an advance
     * @throws GroupedPaymentValidationException When the entry's currency differs from the active fiscal configuration
     * @throws InvalidAdvanceInvoiceException When the tax group carries more than one rate
     * @throws ReverseChargeWithTaxException When `is_roi_taxed` meets a group carrying real tax
     * @throws MissingFiscalSeriesException When neither the passed series nor the configuration resolves one
     */
    public function forCreditEntry(
        CreditMovement $entry,
        ?string $series,
        string $description,
        ?int $taxGroupId,
        bool $isRoiTaxed,
    ): Invoice {
        // §4.2 — entry validation, fail-loud, zero writes.
        if (! $entry->isEntry()) {
            throw InvalidCreditMovementException::notAnEntry((string) $entry->id);
        }

        if ($entry->isReversed()) {
            throw InvalidCreditMovementException::reversed((string) $entry->id);
        }

        if ($entry->advance_invoice_id !== null) {
            throw CreditMovementAlreadyLinkedException::forMovement(
                (string) $entry->id,
                (string) $entry->advance_invoice_id,
            );
        }

        // §4.2 — D3 parity: the collected currency must match the active
        // fiscal configuration, exactly like an invoice-side payment. The
        // check only fires when a configuration with a currency exists; the
        // missing-config case stays createInvoice()'s own refusal.
        $config = CompanyFiscalConfig::getActive();

        if ($config?->currency !== null && $config->currency !== $entry->currency) {
            throw GroupedPaymentValidationException::creditCurrencyMismatch(
                (string) $entry->id,
                $entry->currency,
                $config->currency,
            );
        }

        // §4.3 — group resolution and validation, still zero writes.
        /** @var TaxGroup|null $taxGroup */
        $taxGroup = $taxGroupId !== null
            ? TaxGroup::query()->with('taxRates')->find($taxGroupId)
            : null;

        if ($taxGroup !== null && $taxGroup->taxRates->count() > 1) {
            throw InvalidAdvanceInvoiceException::multiRateTaxGroup($taxGroup);
        }

        $rateSum = (int) ($taxGroup?->taxRates->sum('rate') ?? 0);

        if ($isRoiTaxed && $rateSum > 0) {
            throw ReverseChargeWithTaxException::forTaxGroup($taxGroup, $rateSum);
        }

        // §4.3 — the reverse base. Exact BigDecimal arithmetic (AID-1527
        // §3-bis discipline, precedent SettlementService::frozenRateTaxAmount());
        // the divisor is 1 + Σ rates expressed over the base-100 scale, so the
        // quotient is gross·10000 / (10000 + Σ rates). `tax` is the exact
        // residual, never an independent rounding.
        $gross = $entry->amount->unscaledValue();

        if ($rateSum === 0) {
            $base = $gross;
            $tax  = 0;
        } else {
            $exact = BigDecimal::of($gross)
                ->multipliedBy(BigDecimal::of(10000))
                ->dividedBy(10000 + $rateSum, 0, RoundingMode::HalfUp);

            $base = (int) (string) $exact;
            $tax  = $gross - $base;
        }

        // §4.3 — the per-rate emission mirrors VatCalculationStrategy::calculate()
        // (one entry per group rate): a null group carries `[]` (G8 — an
        // ABSENT group is a different fiscal statement from a zero-rate one),
        // an explicit zero-rate group carries its single zero entry (G3).
        $taxesApplied = [];

        if ($taxGroup !== null) {
            $rate = $taxGroup->taxRates->sole();

            $taxesApplied[] = [
                'source_rate_id' => $rate->id,
                'name'           => $rate->name,
                'rate'           => (int) $rate->rate,
                'amount'         => $tax,
            ];
        }

        // §4.4 — ONE line, no article, built on the FROZEN path (the
        // `total_amount` key present → persisted verbatim, same precedent as
        // the proforma clone and AdvanceDeductionLineTest): the emitted
        // document can never re-derive against a live catalog that changed
        // between build and issue. `status: draft` is the explicit-status
        // contract that skips issuance — the builder NEVER issues.
        return $this->invoiceService->createInvoice([
            'billable_user_id' => $entry->billable_user_id,
            'type'             => 'invoice',
            'series'           => $series,
            'status'           => 'draft',
            'is_roi_taxed'     => $isRoiTaxed,
            'items'            => [
                [
                    'description'        => $description,
                    'quantity'           => 100,
                    'base_price'         => $base,
                    'taxable_amount'     => $base,
                    'total_tax_amount'   => $tax,
                    'taxes_applied'      => $taxesApplied,
                    'total_amount'       => $gross,
                    'deducts_invoice_id' => null,
                ],
            ],
        ]);
    }

    /**
     * The frozen deduction line that liquidates an APPLIED credit exit on
     * the LATER invoice (AID-1778 spec §5.1, S4). The consumer merges this
     * line into the later emission's items — the frozen path persists it
     * verbatim, with `deducts_invoice_id` naming the advance.
     *
     * The pro-rata is a PURE read of the exit's STORED band
     * (`credit_movements.band_start`, written at exit creation under the
     * source-payment lock): tax = f(b) − f(a), base = (b − a) − tax, with
     * f(c) = HalfUp(c·T/G) for 0 ≤ c < G and f(G) = T exactly, against the
     * advance's frozen totals (spec §5.2). Call timing is irrelevant — a
     * repeated call reads the same stored column — and the sum of every
     * deduction of an entry equals the advance to the cent (the telescoping
     * proof of spec §5.4).
     *
     * Requires an ACTIVE exit of kind APPLIED whose consumed ENTRY is linked
     * to an issued advance invoice; every violation refuses loud with zero
     * writes (spec §5.1).
     *
     * @return array<string, mixed> the frozen line for `createInvoice()`
     *
     * @throws InvalidCreditMovementException When the movement is not an active APPLIED exit
     * @throws InvalidAdvanceInvoiceException When the consumed entry is not the advance's entry, its position is unresolved, or the advance's frozen snapshot is broken/multi-rate
     */
    public function deductionLine(CreditMovement $exit): array
    {
        $proRata = $this->proRataForExit($exit, CreditMovementKind::APPLIED);

        return [
            'description'        => $this->advanceReference($proRata['advance']),
            'quantity'           => 100,
            'base_price'         => -$proRata['base'],
            'taxable_amount'     => -$proRata['base'],
            'total_tax_amount'   => -$proRata['tax'],
            'taxes_applied'      => $proRata['taxesApplied'],
            'total_amount'       => -($proRata['base'] + $proRata['tax']),
            'deducts_invoice_id' => (string) $proRata['advance']->id,
        ];
    }

    /**
     * The frozen rectificative lines that refund a REFUNDED credit exit —
     * the supply of `SettlementService::rectify(…, items:)` for a PARTIAL
     * rectificative of the advance (AID-1778 spec §5.1, S4; no new artifact
     * type). Concatenate the outputs of several exits into one rectify call
     * when one refund settles multiple exits.
     *
     * Same pure stored-band pro-rata as `deductionLine()` (spec §5.2), with
     * the lines shaped for `rectify($advance, null, null, $lines)`: negative
     * totals, the advance's frozen `(source_rate_id, rate)` pair negated. The
     * Σ |total_amount| = |exit.amount| identity is asserted defensively
     * (`itemsDoNotSumToExit`) — it holds by construction, so a breach is a
     * bug in this helper's own arithmetic, never a caller error (H8).
     *
     * @return list<array<string, mixed>> the frozen lines for `rectify(items:)`
     *
     * @throws InvalidCreditMovementException When the movement is not an active REFUNDED exit
     * @throws InvalidAdvanceInvoiceException When the consumed entry is not the advance's entry, its position is unresolved, or the advance's frozen snapshot is broken/multi-rate
     */
    public function rectificativeLines(CreditMovement $exit): array
    {
        $proRata = $this->proRataForExit($exit, CreditMovementKind::REFUNDED);

        $lines = [[
            'description'       => 'Rectification of advance invoice '.$this->advanceFiscalNumber($proRata['advance']),
            'quantity'          => 100,
            'unit_price'        => -$proRata['base'],
            'taxable_amount'    => -$proRata['base'],
            'total_tax_amount'  => -$proRata['tax'],
            'taxes_applied'     => $proRata['taxesApplied'],
            'total_amount'      => -($proRata['base'] + $proRata['tax']),
        ]];

        // H8 defensive identity (spec §5.1): the lines a REFUNDED exit
        // produces must sum to the exit's take exactly. Holds by
        // construction (base + tax = band length = |amount|); a breach is
        // this helper's own bug.
        $grossSum = array_sum(array_map(
            static fn (array $line): int => abs((int) $line['total_amount']),
            $lines,
        ));
        $takeCents = abs($exit->amount->unscaledValue());

        if ($grossSum !== $takeCents) {
            throw InvalidAdvanceInvoiceException::itemsDoNotSumToExit(
                (string) $exit->id,
                $takeCents,
                $grossSum,
            );
        }

        return $lines;
    }

    /**
     * The stored-band pro-rata shared by both exits (spec §5.1–§5.2):
     * validates the exit and the advance's frozen snapshot with ZERO
     * writes, reads the band, and computes tax = f(b) − f(a),
     * base = (b − a) − tax on exact integer arithmetic.
     *
     * @param  CreditMovementKind  $expectedKind  the exit kind the calling artifact needs
     * @return array{advance: Invoice, base: int, tax: int, taxesApplied: list<array<string, mixed>>}
     */
    private function proRataForExit(CreditMovement $exit, CreditMovementKind $expectedKind): array
    {
        // §5.1 — the received movement must be an ACTIVE exit of the kind
        // the calling artifact needs.
        if (! $exit->isExit()) {
            throw InvalidCreditMovementException::notAnEntry((string) $exit->id);
        }

        if ($exit->isReversed()) {
            throw InvalidCreditMovementException::reversed((string) $exit->id);
        }

        if ($exit->kind !== $expectedKind) {
            throw new InvalidCreditMovementException(sprintf(
                'Credit movement [%s] is a %s exit; this pro-rata builds a %s artifact and needs a %s exit.',
                (string) $exit->id,
                $exit->kind->name,
                $expectedKind === CreditMovementKind::APPLIED ? 'deduction' : 'rectificative',
                $expectedKind->name,
            ));
        }

        // §5.1 — the consumed row must be an ENTRY linked to an advance.
        $entry = $exit->consumes()->first();

        if ($entry === null || ! $entry->isEntry()) {
            throw InvalidAdvanceInvoiceException::notTheAdvanceEntry((string) $exit->id);
        }

        if ($entry->advance_invoice_id === null) {
            throw InvalidAdvanceInvoiceException::notTheAdvanceEntry((string) $exit->id);
        }

        /** @var Invoice|null $advance */
        $advance = Invoice::query()->find($entry->advance_invoice_id);

        if ($advance === null) {
            throw InvalidAdvanceInvoiceException::notTheAdvanceEntry((string) $exit->id);
        }

        // §5.7 — an ACTIVE exit carrying a NULL band IS the declaration of
        // an unresolvable entry: refuse loud, never invent a split.
        if ($exit->band_start === null) {
            throw InvalidAdvanceInvoiceException::unresolvedEntryPosition((string) $entry->id);
        }

        // §5.2 — the advance's frozen totals give the rounding function f.
        $gross    = $advance->total_amount->unscaledValue();
        $totalTax = $advance->total_tax_amount->unscaledValue();

        if ($gross <= 0 || $totalTax < 0 || $totalTax > $gross) {
            throw InvalidAdvanceInvoiceException::brokenAdvanceSnapshot(
                (string) $advance->id,
                sprintf('its frozen totals (gross %d, tax %d) are not a valid 0 ≤ T ≤ G pair.', $gross, $totalTax),
            );
        }

        $taxesApplied = $this->validatedAdvanceSnapshot($advance);

        // §5.3 — the band lives on the exit row: [band_start, band_start + |amount|).
        $bandStart = (int) $exit->band_start;
        $length    = abs($exit->amount->unscaledValue());
        $bandEnd   = $bandStart + $length;

        if ($bandStart < 0 || $bandEnd > $gross) {
            throw new InvalidAdvanceInvoiceException(sprintf(
                'Credit exit [%s]: its stored band [%d, %d) leaves its entry\'s cent tape [0, %d) — broken ledger arithmetic. Nothing was written.',
                (string) $exit->id,
                $bandStart,
                $bandEnd,
                $gross,
            ));
        }

        // §5.2 — tax = f(b) − f(a); base = (b − a) − tax (exact residual).
        $tax  = $this->proRataTaxOf($bandEnd, $gross, $totalTax) - $this->proRataTaxOf($bandStart, $gross, $totalTax);
        $base = $length                                          - $tax;

        // The line's taxes_applied mirrors the advance's frozen entry with
        // the band's tax negated — key order preserved (the builder's own
        // emission order), the amount overwritten in place.
        if ($taxesApplied !== null) {
            $taxesApplied['amount'] = -$tax;
            $taxesApplied           = [$taxesApplied];
        }

        return [
            'advance'      => $advance,
            'base'         => $base,
            'tax'          => $tax,
            'taxesApplied' => $taxesApplied ?? [],
        ];
    }

    /**
     * G6 snapshot validation (spec §5.2): the advance built by §4 carries
     * exactly one rate (or a tax-free line). Returns the single frozen
     * `(source_rate_id, name, rate)` template for the line's `taxes_applied`
     * — null for a tax-free advance, whose lines carry `taxes_applied: []`.
     *
     * @return array{source_rate_id: mixed, name: mixed, rate: mixed}|null
     */
    private function validatedAdvanceSnapshot(Invoice $advance): ?array
    {
        $template  = null;
        $seenLines = [];

        foreach ($advance->items as $item) {
            $entries = is_array($item->taxes_applied) ? $item->taxes_applied : [];
            $sum     = 0;

            foreach ($entries as $taxEntry) {
                if (! is_array($taxEntry)) {
                    throw InvalidAdvanceInvoiceException::brokenAdvanceSnapshot(
                        (string) $advance->id,
                        'its taxes_applied carries a non-array entry.',
                    );
                }

                $amount = (int) ($taxEntry['amount'] ?? 0);
                $sum   += $amount;

                if (! array_key_exists('source_rate_id', $taxEntry) || $taxEntry['source_rate_id'] === null) {
                    throw InvalidAdvanceInvoiceException::brokenAdvanceSnapshot(
                        (string) $advance->id,
                        'a taxes_applied entry is missing its source_rate_id.',
                    );
                }

                $rateId = (string) $taxEntry['source_rate_id'];

                if (isset($seenLines[$rateId])) {
                    throw InvalidAdvanceInvoiceException::brokenAdvanceSnapshot(
                        (string) $advance->id,
                        "the source_rate_id [{$rateId}] is repeated across lines.",
                    );
                }

                $seenLines[$rateId] = true;
                $template           = [
                    'source_rate_id' => $taxEntry['source_rate_id'],
                    'name'           => $taxEntry['name'] ?? null,
                    'rate'           => $taxEntry['rate'] ?? 0,
                ];
            }

            if ($sum !== $item->total_tax_amount->unscaledValue()) {
                throw InvalidAdvanceInvoiceException::brokenAdvanceSnapshot(
                    (string) $advance->id,
                    "the taxes_applied amounts of one line sum to {$sum}, not the line's total_tax_amount.",
                );
            }
        }

        if (count($seenLines) > 1) {
            throw InvalidAdvanceInvoiceException::multiRateTaxGroupFromSnapshot(
                (string) $advance->id,
                array_keys($seenLines),
            );
        }

        return $template;
    }

    /**
     * The rounding function f (spec §5.2): f(c) = HalfUp(c·T/G) on exact
     * BigInteger arithmetic for 0 ≤ c < G, and f(G) = T exactly. Same
     * discipline as the migration's E3 cross-check and the AID-1527 §3-bis
     * precedent — never a float intermediate.
     */
    private function proRataTaxOf(int $cents, int $gross, int $totalTax): int
    {
        if ($cents >= $gross) {
            return $totalTax; // f(G) = T exactly
        }

        return BigInteger::of($cents)->multipliedBy($totalTax)
            ->dividedBy($gross, RoundingMode::HalfUp)
            ->toInt();
    }

    /**
     * The deduction line's description names the advance's fiscal number;
     * an unnumbered advance (still a draft) falls back to its internal id.
     */
    private function advanceReference(Invoice $advance): string
    {
        return sprintf('Deduction of advance invoice %s', $this->advanceFiscalNumber($advance));
    }

    private function advanceFiscalNumber(Invoice $advance): string
    {
        return $advance->fiscal_number ?? (string) $advance->id;
    }
}
