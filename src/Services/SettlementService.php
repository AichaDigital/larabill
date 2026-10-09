<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\InvalidRectificationAmountException;
use AichaDigital\Larabill\Exceptions\InvalidSettlementAmountException;
use AichaDigital\Larabill\Exceptions\InvoiceNotAnnullableException;
use AichaDigital\Larabill\Exceptions\InvoiceNotRectifiableException;
use AichaDigital\Larabill\Exceptions\UnresolvableRectificationRateException;
use AichaDigital\Larabill\Exceptions\UnsettleableInvoiceException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;

/**
 * Settlement artifacts service (ADR-014, AID-971).
 *
 * Issues the corrective artifacts of ADR-014 eje 1. Boundary (ADR-014 §2):
 * larabill emits the fiscally correct document; it never decides WHETHER a
 * refund happens, its amount policy, or its channel — that is consumer
 * policy. It also never chooses WHICH artifact applies: this service only
 * knows how to EXPRESS each artifact correctly.
 *
 * The two ADR-014 axes are orthogonal:
 * - Artifact (eje 1): what document results — discriminated by
 *   `rectifies_invoice_id`, never by the sign of the amounts.
 * - Amount source (eje 2): where the amount comes from — full, proportional
 *   to unconsumed time (the only arithmetic larabill offers, measured on the
 *   EMITTED line per ADR-013), a consumer-supplied arbitrary amount, or the
 *   art. 80.Cuatro LIVA outstanding debt (whose conditions the consumer
 *   owns). A rectificative accepts any of them as its explicit difference
 *   base; null means the full original.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class SettlementService
{
    public function __construct(
        protected InvoiceService $invoiceService
    ) {}

    /**
     * Issue a rectificative ("por diferencias", AEAT `R1` + `I`) correcting a
     * previously emitted invoice.
     *
     * The rectificative is linked to the original via `rectifies_invoice_id` —
     * the ADR-014 discriminant that also makes VerifactuAdapter declare
     * TipoFactura R1 with a FacturasRectificadas reference. It reaches the
     * original's FROZEN fiscal context: taxes come from the original lines'
     * immutable `taxes_applied` snapshot, never from the live catalogue
     * (AID-956 doctrine).
     *
     * The recipient is FROZEN too: the rectificative reuses the original's
     * encrypted `customer_snapshot` (R1 corrects the operation with the
     * original recipient even if the profile changed since emission), and
     * `user_tax_profile_id` references the profile that priced the original.
     * The ISSUER snapshot is resolved live (same legal entity). The reverse-
     * charge qualification of the operation travels from the original as
     * well: a rectificative corrects the SAME operation (AID-929 doctrine).
     *
     * The resulting document is born sealed (status SENT, immutable), exactly
     * like the recurring engine's emissions (AID-836).
     *
     * @param  Invoice  $original  an emitted (non-draft, non-proforma) invoice
     * @param  int|null  $amount  base-100 unscaled difference base; strictly
     *                            negative (e.g. -5000 = correct €-50.00 of base).
     *                            Null rectifies the FULL original: every line
     *                            is cloned verbatim, sign-flipped, one line per
     *                            rate. An explicit amount on a multi-rate
     *                            original is refused — the rate distribution
     *                            would be invented (AID-589 doctrine).
     */
    public function rectify(Invoice $original, ?int $amount = null): Invoice
    {
        $this->assertRectifiable($original);

        if ($amount !== null && $amount >= 0) {
            throw InvalidRectificationAmountException::forAmount($amount);
        }

        $items = $amount === null
            ? $this->clonedVerbatimItems($original)
            : $this->differenceItems($original, $amount);

        $billableUserId = $original->billable_user_id;

        if (! is_string($billableUserId) || $billableUserId === '') {
            throw new \RuntimeException(
                "Invoice [{$original->fiscal_number}] has no billable user; nothing to rectify against."
            );
        }

        return $this->invoiceService->createInvoice([
            'billable_user_id'     => $billableUserId,
            'type'                 => 'rectificative',
            'rectifies_invoice_id' => $original->id,
            'status'               => InvoiceStatus::SENT->value,
            'items'                => $items,
            // Same operation, same qualification (AID-929): a reverse-charge
            // original must produce a reverse-charge rectificative.
            'is_roi_taxed'         => (bool) $original->is_roi_taxed,
            // FROZEN recipient (ADR-014): the original's encrypted customer
            // snapshot travels verbatim — the issuer side is resolved live.
            'customer_snapshot'    => $original->customer_snapshot,
            'user_tax_profile_id'  => $original->user_tax_profile_id !== null
                ? (string) $original->user_tax_profile_id
                : null,
        ], ['make_immutable' => true]);
    }

    /**
     * Annul an emitted invoice (ADR-014 eje 1): the invoice should never have
     * existed (duplicate, wrong receptor, mistaken issue). The fiscal artifact
     * is an AEAT `RegistroAnulacion` — NO new document is created; the only
     * state change is the original's status moving to CANCELLED.
     *
     * Boundary: the annulment REASON (duplicate, mistaken issue, …) is
     * consumer/registration policy. It travels with the consumer's
     * lara-verifactu registration call (`RegistryManager::createCancellation`,
     * one cancellation per invoice — AID-726), never with larabill's state:
     * this service does not accept it, does not store it and larabill never
     * registers the annulment to the AEAT itself.
     *
     * Fiscal content (totals, snapshots, items, paid_at) is untouched. The
     * status write follows the `Invoice::markAsPaidViaGroupedPayment()`
     * precedent: lifecycle/collection state is NOT fiscal content, so it is
     * written with save() deliberately, bypassing the update() immutability
     * guard that protects fiscal content — not lifecycle state.
     *
     * @param  Invoice  $original  an emitted (non-draft, non-proforma) invoice
     * @return Invoice the annulled original (same document, refreshed)
     */
    public function annul(Invoice $original): Invoice
    {
        $this->assertAnnullable($original);

        $original->status = InvoiceStatus::CANCELLED;
        $original->save();

        return $original;
    }

    /**
     * Issue a NEW ordinary invoice carrying a credit line that liquidates the
     * unconsumed value of a previous contract, optionally followed by
     * additional ordinary charge lines (ADR-014 eje 1, "factura ordinaria con
     * línea de abono" — e.g. the cambio-de-plan case: abono of the old plan
     * plus the new plan's charge, on one document).
     *
     * The artifact is a NEW ordinary invoice: `rectifies_invoice_id` stays
     * NULL — the ADR-014 discriminant that separates it from a rectificative
     * and makes VerifactuAdapter declare TipoFactura F1 even though the credit
     * line is negative. The provenance of what is liquidated lives in the
     * credit LINE's metadata (ADR-014 §5): in a rectificative the FK carries
     * it; in a new invoice there is no FK, so the trace travels with the line.
     *
     * The credit line is taxed in THIS emission, with ITS OWN fiscal context
     * (ADR-014 §3): it goes through the normal tax calculation path and
     * resolves the CURRENT rate from the original's first line's article. If
     * the VAT rate changed since the original, the credit line bills the
     * current one — never the frozen context, which is the rectificative's
     * concern.
     *
     * The reverse-charge qualification is the CURRENT one too: if the receptor
     * is now intra-community reverse-charge (EU NIF-IVA), pass `$isRoiTaxed`
     * and the settlement declares it — AID-929 doctrine, declared by the
     * consumer, never inferred. An roi settlement whose credit line would
     * carry real tax is refused loud by the AID-929 guard inside
     * createInvoice.
     *
     * The resulting document is born sealed (status SENT, immutable), exactly
     * like the recurring engine's emissions (AID-836).
     *
     * @param  Invoice  $original  an emitted (non-draft, non-proforma, never-annulled) invoice
     * @param  int  $amount  base-100 unscaled unconsumed value to liquidate,
     *                       strictly positive (e.g. 5000 = €50.00)
     * @param  list<array<string, mixed>>  $items  additional ordinary charge
     *                                             lines (e.g. a new plan's charge),
     *                                             emitted with the same normal
     *                                             tax path
     * @param  bool  $isRoiTaxed  the CURRENT reverse-charge qualification of this
     *                            emission (AID-929: consumer-declared, never inferred)
     * @param  int|null  $creditArticleId  explicit article for the credit line.
     *                                     Must be one of the original's lines'
     *                                     articles. When null, the original must
     *                                     resolve to a single current tax group
     *                                     (or no items at all) — larabill does
     *                                     not invent the distribution (AID-589).
     */
    public function settleUnconsumed(Invoice $original, int $amount, array $items = [], bool $isRoiTaxed = false, ?int $creditArticleId = null): Invoice
    {
        $this->assertSettleable($original);

        if ($amount <= 0) {
            throw InvalidSettlementAmountException::forAmount($amount);
        }

        $billableUserId = $original->billable_user_id;

        if (! is_string($billableUserId) || $billableUserId === '') {
            throw new \RuntimeException(
                "Invoice [{$original->fiscal_number}] has no billable user; nothing to settle against."
            );
        }

        return $this->invoiceService->createInvoice([
            'billable_user_id' => $billableUserId,
            // An ordinary invoice: type 'invoice' → INVOICE serie, prefix from
            // the config series, own correlative numbering. rectifies_invoice_id
            // is deliberately ABSENT — it must stay NULL (the artifact discriminant).
            'type'             => 'invoice',
            'status'           => InvoiceStatus::SENT->value,
            'items'            => [$this->creditLine($original, $amount, $creditArticleId), ...$items],
            // AID-929: the CURRENT qualification of the operation, declared by
            // the consumer. NOT frozen from the original — the credit line
            // bills the current context by design.
            'is_roi_taxed'     => $isRoiTaxed,
        ], ['make_immutable' => true]);
    }

    /**
     * Guard: only an emitted, never-annulled fiscal invoice may be settled
     * with a credit line in a new invoice.
     */
    protected function assertSettleable(Invoice $original): void
    {
        if ($original->serie === InvoiceSerieType::PROFORMA) {
            throw UnsettleableInvoiceException::forProforma($original);
        }

        if ($original->status === InvoiceStatus::DRAFT) {
            throw UnsettleableInvoiceException::forDraft($original);
        }

        if ($original->status === InvoiceStatus::CANCELLED) {
            throw UnsettleableInvoiceException::forAnnulled($original);
        }
    }

    /**
     * The credit line: a negative charge taxed with the CURRENT context of
     * the invoice that carries it (ADR-014 §3). No frozen amounts travel with
     * it — the line goes through the normal tax calculation path at emission,
     * resolving the live tax group from the credit line's article.
     *
     * @return array<string, mixed>
     */
    protected function creditLine(Invoice $original, int $amount, ?int $creditArticleId = null): array
    {
        return [
            // The credit line's article: its LIVE tax group resolves the
            // CURRENT rate at emission. A tax-free original (no items) yields
            // a tax-free credit line.
            'article_id'  => $this->resolveCreditArticleId($original, $creditArticleId),
            'quantity'    => 100, // 1.00 unit in base-100; the price carries the sign
            'unit_price'  => -$amount,
            'description' => "Credit for unconsumed service of invoice {$original->fiscal_number}",
            'metadata'    => [
                'settles_invoice_id'    => $original->id,
                'settles_fiscal_number' => $original->fiscal_number,
            ],
        ];
    }

    /**
     * Resolve the article that prices the credit line.
     *
     * An explicit `$creditArticleId` must be one of the original's lines'
     * articles — the credit line's tax context cannot come from an article
     * the settled invoice never carried. Without one, the original must
     * resolve to a SINGLE current tax group across its lines' articles (a
     * null group counts as its own bucket); more than one distinct bucket
     * means attributing the whole abono to one of them would be an invented
     * distribution (AID-589 doctrine) — the consumer chooses, loud.
     */
    protected function resolveCreditArticleId(Invoice $original, ?int $creditArticleId): ?int
    {
        $lineArticleIds = $original->items
            ->pluck('article_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($creditArticleId !== null) {
            if (! $lineArticleIds->contains($creditArticleId)) {
                throw UnsettleableInvoiceException::creditArticleNotInOriginal($original, $creditArticleId);
            }

            return $creditArticleId;
        }

        // Unchanged heuristic fallback: a single-group original (or an
        // original with no items) keeps pricing the credit line with its
        // first item's article.
        if ($lineArticleIds->isEmpty()) {
            return null;
        }

        $groupIds = Article::query()
            ->whereIn('id', $lineArticleIds->all())
            ->pluck('tax_group_id')
            ->map(fn (mixed $groupId): ?int => $groupId === null ? null : (int) $groupId)
            ->unique()
            ->values()
            ->all();

        if (count($groupIds) > 1) {
            throw UnsettleableInvoiceException::ambiguousCreditArticle($original, $groupIds);
        }

        return (int) $lineArticleIds->first();
    }

    /**
     * Guard: only an emitted, never-annulled fiscal invoice with no issued
     * rectificatives may be annulled.
     */
    protected function assertAnnullable(Invoice $original): void
    {
        if ($original->serie === InvoiceSerieType::PROFORMA) {
            throw InvoiceNotAnnullableException::forProforma($original);
        }

        if ($original->status === InvoiceStatus::DRAFT) {
            throw InvoiceNotAnnullableException::forDraft($original);
        }

        if ($original->status === InvoiceStatus::CANCELLED) {
            throw InvoiceNotAnnullableException::alreadyAnnulled($original);
        }

        $rectificativeCount = $original->rectificatives()->count();

        if ($rectificativeCount > 0) {
            throw InvoiceNotAnnullableException::hasRectificatives($original, $rectificativeCount);
        }
    }

    /**
     * Guard: only emitted fiscal invoices may carry a rectificative.
     */
    protected function assertRectifiable(Invoice $original): void
    {
        if ($original->serie === InvoiceSerieType::PROFORMA) {
            throw InvoiceNotRectifiableException::forProforma($original);
        }

        if ($original->status === InvoiceStatus::DRAFT) {
            throw InvoiceNotRectifiableException::forDraft($original);
        }

        // Symmetric with annul()'s refusal of invoices carrying
        // rectificatives: an annulled document was declared never to have
        // existed (RegistroAnulacion) — it cannot be corrected either.
        if ($original->status === InvoiceStatus::CANCELLED) {
            throw InvoiceNotRectifiableException::forAnnulled($original);
        }
    }

    /**
     * Full rectification: clone every original line verbatim, sign-flipped,
     * preserving each line's frozen `taxes_applied` snapshot (amounts
     * sign-flipped, rate untouched). One line per rate — no rate arithmetic.
     *
     * The original line's metadata is NOT carried over: the rectificative is a
     * new document whose provenance lives in `rectifies_invoice_id`, and
     * original metadata keys (e.g. the recurring engine's idempotency
     * fingerprints) must not leak into it.
     *
     * @return list<array<string, mixed>>
     */
    protected function clonedVerbatimItems(Invoice $original): array
    {
        $items = $original->items
            ->map(fn (InvoiceItem $item): array => [
                'article_id'        => $item->article_id,
                'item_type'         => $item->item_type,
                'description'       => $item->description,
                'internal_code'     => $item->internal_code,
                'unit_measure_id'   => $item->unit_measure_id,
                'quantity'          => $item->quantity->unscaledValue(),
                'unit_price'        => -$item->unit_price->unscaledValue(),
                'taxable_amount'    => -$item->taxable_amount->unscaledValue(),
                'total_tax_amount'  => -$item->total_tax_amount->unscaledValue(),
                'taxes_applied'     => $this->signFlippedTaxesApplied($item),
                'total_amount'      => -$item->total_amount->unscaledValue(),
                'service_date_from' => $item->service_date_from?->format('Y-m-d'),
                'service_date_to'   => $item->service_date_to?->format('Y-m-d'),
            ])
            ->all();

        return array_values($items);
    }

    /**
     * Difference rectification: a single negative line taxed with the
     * original's frozen rate. Refuses a multi-rate original — attributing the
     * difference to one rate would be an invented distribution.
     *
     * @return list<array<string, mixed>>
     */
    protected function differenceItems(Invoice $original, int $amount): array
    {
        $entries = [];

        foreach ($original->items as $item) {
            foreach (is_array($item->taxes_applied) ? $item->taxes_applied : [] as $entry) {
                if (is_array($entry)) {
                    $entries[] = $entry;
                }
            }
        }

        $sourceRateIds = array_values(array_unique(array_map(
            fn (array $entry): int => (int) ($entry['source_rate_id'] ?? 0),
            $entries
        )));

        if (count($sourceRateIds) > 1) {
            throw UnresolvableRectificationRateException::forInvoice($original, $sourceRateIds);
        }

        // Frozen rate (absent on a tax-free original → zero tax). The magnitude
        // is rounded HalfUp exactly like the VAT strategy, then negated as a
        // whole so the sign arithmetic never rounds a negative base directly.
        $frozen     = $entries[0] ?? null;
        $taxAmount  = $frozen === null
            ? 0
            : (int) round(abs($amount) * ((int) ($frozen['rate'] ?? 0) / 10000));

        $taxableAmount  = $amount;
        $totalTaxAmount = -$taxAmount;

        return [[
            'item_type'         => $original->items->first()?->item_type,
            'description'       => "Rectification of invoice {$original->fiscal_number} (differences)",
            'quantity'          => 100, // 1.00 unit in base-100; the amount itself is negative
            'unit_price'        => $taxableAmount,
            'taxable_amount'    => $taxableAmount,
            'total_tax_amount'  => $totalTaxAmount,
            'taxes_applied'     => $frozen === null ? [] : [
                [
                    'source_rate_id' => $frozen['source_rate_id'] ?? null,
                    'name'           => $frozen['name']           ?? null,
                    'rate'           => $frozen['rate']           ?? 0,
                    'amount'         => $totalTaxAmount,
                ],
            ],
            'total_amount'      => $taxableAmount + $totalTaxAmount,
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function signFlippedTaxesApplied(InvoiceItem $item): array
    {
        $entries = is_array($item->taxes_applied) ? $item->taxes_applied : [];

        return array_values(array_map(
            fn (array $entry): array => ['amount' => -($entry['amount'] ?? 0)] + $entry,
            array_filter($entries, is_array(...))
        ));
    }
}
