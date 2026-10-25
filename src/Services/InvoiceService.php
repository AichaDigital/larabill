<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Contracts\Services\FiscalVerificationContract;
use AichaDigital\Larabill\DTO\ProformaConversionResult;
use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Exceptions\FiscalConfigChangedException;
use AichaDigital\Larabill\Exceptions\InvalidInvoiceStatusException;
use AichaDigital\Larabill\Exceptions\InvoiceNotCancellableException;
use AichaDigital\Larabill\Exceptions\InvoiceNotIssuableException;
use AichaDigital\Larabill\Exceptions\ReverseChargeWithTaxException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\UserTaxProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Invoice Service
 *
 * Modern service with encrypted snapshots and fiscal verification integration.
 * Uses CompanyFiscalConfig for issuer data and UserTaxProfile for customer data (ADR-003).
 *
 * ADR-016 (major 8.0.0): the invoice numbering LIFECYCLE. A fiscal document
 * is born an unnumbered DRAFT (§7.1); the correlative is consumed exactly
 * once, inside `issue()` (§6), from ONE issuance instant. Proformas keep the
 * v6 birth numbering on their own serie (§3.1) — the serie-type branch runs
 * FIRST and the branches never mix (§7.2). Conversion returns a PREPARED
 * document (§9); `cancelPreparedInvoice()` releases it audited (§10);
 * `convertAndIssue()` is the resumable compatibility vehicle (§11).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 *
 * @phpstan-type InvoiceItemData array{
 *     article_id?: int|null,
 *     tax_group_id?: int|null,
 *     quantity?: int,
 *     base_price?: int|float,
 *     unit_price?: int|float,
 *     description?: string,
 *     item_type?: ItemType|int|null,
 *     internal_code?: string|null,
 *     unit_measure_id?: int|null,
 *     service_date_from?: string|null,
 *     service_date_to?: string|null,
 *     metadata?: array<string, mixed>|null,
 *     taxable_amount?: int,
 *     total_tax_amount?: int,
 *     taxes_applied?: array<int|string, mixed>|null,
 *     total_amount?: int,
 *     deducts_invoice_id?: string|null
 * }
 */
class InvoiceService
{
    protected FiscalChangeDetector $fiscalChangeDetector;

    protected InvoiceNumberingService $invoiceNumbering;

    protected InvoiceSeriesResolver $seriesResolver;

    public function __construct(
        protected TaxCalculationService $taxCalculationService,
        protected ?FiscalVerificationContract $fiscalVerification = null,
        ?FiscalChangeDetector $fiscalChangeDetector = null,
        ?InvoiceNumberingService $invoiceNumbering = null,
        ?InvoiceSeriesResolver $seriesResolver = null
    ) {
        $this->fiscalChangeDetector = $fiscalChangeDetector ?? app(FiscalChangeDetector::class);
        $this->invoiceNumbering     = $invoiceNumbering     ?? app(InvoiceNumberingService::class);
        $this->seriesResolver       = $seriesResolver       ?? app(InvoiceSeriesResolver::class);
    }

    /**
     * Create a new invoice with encrypted snapshots.
     *
     * ADR-003: Uses billable_user_id (User) instead of customer_id.
     *
     * ADR-016 §7: the serie-type branch runs FIRST (§7.2) and the two
     * contracts never mix:
     *
     * - **PROFORMA** keeps the v6 behavior verbatim — numbered at birth on its
     *   own serie (§3.1; the WHMCS zero-invoice import route depends on it),
     *   document axis NULL, no issuance, in one transaction.
     * - **FISCAL types** (INVOICE, SIMPLIFIED, RECTIFICATIVE) run the
     *   two-phase contract (§7.1): phase 1 commits the durable UNNUMBERED
     *   DRAFT (no correlative, the five number/issuance fields NULL); phase 2
     *   is `issue()` as a second transaction. An explicit `status: draft`
     *   skips phase 2; collection statuses (SENT/PAID/OVERDUE) are stamped
     *   post-issuance. Lifecycle outcomes (PENDING/CONVERTED/CANCELLED/
     *   CONVERTING) are rejected as birth inputs on the NORMALIZED state,
     *   string or int form (§7.2, rounds 9/10 V14/V15).
     *
     * NOT behavior-compatible on failures (§7.1): a phase-2 failure leaves a
     * durable unnumbered DRAFT — resumable by calling `issue()` on it, or
     * cancellable. The durability scoping per route (outermost callers vs the
     * all-or-nothing recurring path) is documented in UPGRADE-8.0.md.
     *
     * @param  array{
     *     billable_user_id: string,
     *     user_id?: string,
     *     items: array<int, InvoiceItemData>,
     *     type?: string,
     *     series?: string|null,
     *     status?: string|int,
     *     service_date?: string|null,
     *     due_date?: string|null,
     *     payment_terms?: int|null,
     *     template_name?: string|null,
     *     proforma_id?: string|null,
     *     rectifies_invoice_id?: string|null,
     *     settlement_operation_key?: string|null,
     *     is_historical?: bool,
     *     is_roi_taxed?: bool,
     *     customer_snapshot?: string|null,
     *     user_tax_profile_id?: string|null
     * }  $invoiceData
     * @param  array{
     *     make_immutable?: bool,
     *     verify_fiscally?: bool
     * }  $options
     */
    public function createInvoice(array $invoiceData, array $options = []): Invoice
    {
        // §7.2 (round 11, V16): resolve the serie type BEFORE any phase runs.
        // 'rectificative' (AID-971, ADR-014) selects the RECTIFICATIVE fiscal
        // type — the artifact is linked via rectifies_invoice_id, which
        // travels untouched to the created model and is what VerifactuAdapter
        // maps to R1.
        $invoiceType = $invoiceData['type'] ?? 'invoice';
        $serieType   = match ($invoiceType) {
            'proforma'      => InvoiceSerieType::PROFORMA,
            'rectificative' => InvoiceSerieType::RECTIFICATIVE,
            default         => InvoiceSerieType::INVOICE,
        };

        // §7.2 (rounds 9/10, V14/V15): lifecycle outcomes are never birth
        // inputs. Rejected on the NORMALIZED state — the mapper accepts both
        // string case names and integer values, so both forms land here —
        // and BEFORE any branch runs, so a proforma request cannot birth a
        // row carrying a lifecycle outcome either. Absent status is NOT a
        // requested DRAFT: implementation-gate follow-up (2026-10-13) — the
        // §7.1 result compatibility makes the bare call return an ISSUED
        // invoice, and only an EXPLICIT `status: draft` skips phase 2.
        $requestedStatus = isset($invoiceData['status'])
            ? InvoiceStatus::from($this->mapStatusToEnum($invoiceData['status']))
            : null;
        $this->assertStatusIsBirthEligible($requestedStatus ?? InvoiceStatus::DRAFT);

        // §7.2: the branches never mix. A proforma can never reach phase 2; a
        // fiscal row can never be born numbered.
        if ($serieType === InvoiceSerieType::PROFORMA) {
            return $this->createNumberedProformaRow($invoiceData, $options, $requestedStatus ?? InvoiceStatus::DRAFT);
        }

        return $this->createFiscalInvoice($invoiceData, $options, $serieType, $requestedStatus);
    }

    /**
     * The PROFORMA birth contract (ADR-016 §7.2/§3.1): v6 behavior VERBATIM —
     * numbered at birth on its own serie, document axis NULL, no issuance.
     *
     * attempts: 3 (AID-570) — the numbering first-use protection of AID-390
     * runs NESTED here (a savepoint), so an InnoDB deadlock on a virgin
     * series aborts THIS transaction and only a retry at this outermost
     * boundary can recover. CONTRACT: the closure must stay side-effect-free
     * outside the database — a retry re-runs it in full.
     */
    /**
     * @param  array{
     *     billable_user_id: string,
     *     user_id?: string,
     *     items: array<int, InvoiceItemData>,
     *     series?: string|null,
     *     service_date?: string|null,
     *     due_date?: string|null,
     *     payment_terms?: int|null,
     *     template_name?: string|null,
     *     is_roi_taxed?: bool
     * }  $invoiceData
     * @param  array{
     *     make_immutable?: bool,
     *     verify_fiscally?: bool
     * }  $options
     */
    protected function createNumberedProformaRow(
        array $invoiceData,
        array $options,
        InvoiceStatus $requestedStatus
    ): Invoice {
        return DB::transaction(function () use ($invoiceData, $options, $requestedStatus): Invoice {
            return $this->createInvoiceRow(
                $invoiceData,
                $options,
                InvoiceSerieType::PROFORMA,
                $requestedStatus,
                true,
            );
        }, 3);
    }

    /**
     * The FISCAL two-phase contract (ADR-016 §7.1).
     *
     * Durability is scoped to outermost callers (round 1, V2): phase 1's
     * durable promise holds only when `createInvoice()` runs outside any
     * caller-owned transaction. The recurring-emission path calls it INSIDE
     * its own outer transaction — Laravel nests as savepoints, so a phase-2
     * failure there rolls the draft back together with the caller's unit.
     * That is CORRECT for the recurring path (its v6.10 contract is
     * all-or-nothing) and is documented per route in UPGRADE-8.0.md.
     */
    /**
     * @param  array{
     *     billable_user_id: string,
     *     user_id?: string,
     *     items: array<int, InvoiceItemData>,
     *     series?: string|null,
     *     service_date?: string|null,
     *     due_date?: string|null,
     *     payment_terms?: int|null,
     *     template_name?: string|null,
     *     rectifies_invoice_id?: string|null,
     *     settlement_operation_key?: string|null,
     *     is_historical?: bool,
     *     is_roi_taxed?: bool,
     *     customer_snapshot?: string|null,
     *     user_tax_profile_id?: string|null
     * }  $invoiceData
     * @param  array{
     *     make_immutable?: bool,
     *     verify_fiscally?: bool
     * }  $options
     */
    protected function createFiscalInvoice(
        array $invoiceData,
        array $options,
        InvoiceSerieType $serieType,
        ?InvoiceStatus $requestedStatus
    ): Invoice {
        // Phase 1 (§7.1): the durable unnumbered DRAFT. attempts: 3 — the
        // AID-570 outermost-boundary contract, same reason as the proforma
        // branch (the retry re-runs the phase in full).
        $draft = DB::transaction(
            fn (): Invoice => $this->createInvoiceRow(
                $invoiceData,
                $options,
                $serieType,
                InvoiceStatus::DRAFT,
                false,
            ),
            3,
        );

        // §7.2: an EXPLICIT `status: draft` request → phase 2 does not run;
        // the call returns an unnumbered DRAFT (the AID-1628 seam). An ABSENT
        // status never lands here — the bare call is result-compatible (§7.1)
        // and reaches the issuance phase below.
        if ($requestedStatus === InvoiceStatus::DRAFT) {
            return $draft;
        }

        // Phase 2 (§7.1): issuance as a second transaction.
        $issued = $this->issue($draft);

        // §7.2: collection statuses are the post-issuance collection stamp
        // (the §5.2 axes stay separate; issuance leaves the collection axis
        // at DRAFT and the consumer moves it as today).
        if ($requestedStatus !== null) {
            $issued->status = $requestedStatus;
            $issued->save();
        }

        if ($options['verify_fiscally'] ?? false) {
            $this->verifyInvoiceFiscally($issued);
        }

        return $issued->fresh() ?? $issued;
    }

    /**
     * Issue a fiscal document: consume its correlative, stamp the issuance
     * fields from ONE instant and close it fiscally (ADR-016 §6).
     *
     * Eligible: a DRAFT (direct) or PREPARED (conversion-born) document. An
     * already-ISSUED invoice is returned unchanged — idempotency comes FIRST
     * (§6 step 1, round 3 finding S3), before eligibility, so a retry after a
     * committed issuance is a no-op.
     *
     * Step order (§6): proforma-first locking (§6.3) → idempotency →
     * eligibility → fiscal-coherence validation → conversion-born identity
     * re-run (FiscalChangeDetector, the §14 seam) → ONE issuance instant
     * through the ambient-transaction numbering path (§6.1) → the five
     * issuance fields from that instant (`fiscal_year` is the CONFIGURED
     * fiscal year of the instant via `RegionalContext`, round 1 finding V4 —
     * never the calendar year) → fiscally closed by the §6.5 guards →
     * submission stamp (config-derived, §6 step 7) → ISSUED (conversion-born:
     * proforma `CONVERTING → CONVERTED`; collection axis stays DRAFT, §5.2).
     *
     * Retry ownership is scoped exactly like the §7.1 durability (round 2,
     * B2): the whole-unit deadlock retry is owned HERE only when `issue()` is
     * the OUTERMOST transaction — nested inside a caller's transaction,
     * Laravel raises DeadlockException instead of retrying and the caller
     * owns its unit.
     *
     * Failure taxonomy (§6): transient (lock/deadlock) → rollback, the
     * document stays DRAFT/PREPARED, retryable; domain-invalid → typed
     * exceptions naming the remedy.
     *
     * @api New surface of the ADR-016 lifecycle (§11.1).
     *
     * @throws InvoiceNotIssuableException On a document that can never be issued
     * @throws FiscalConfigChangedException When a conversion-born document's identity no longer holds
     */
    public function issue(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $conversionBorn = $invoice->proforma_id !== null;

            // Lock order (§6.3): proforma → invoice. Round 1 finding V3.
            $proforma = null;
            if ($conversionBorn) {
                $proforma = Invoice::query()->whereKey($invoice->proforma_id)->lockForUpdate()->firstOrFail();
            }
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            // Idempotency BEFORE eligibility (round 3, S3): an already-ISSUED
            // invoice returns itself unchanged.
            if ($invoice->invoice_document_status === InvoiceDocumentStatus::ISSUED) {
                return $invoice;
            }

            // Eligibility (§6 step 1): DRAFT (direct) or PREPARED
            // (conversion-born); never cancelled, never a proforma row.
            if ($invoice->invoice_document_status === InvoiceDocumentStatus::CANCELLED) {
                throw InvoiceNotIssuableException::cancelled($invoice);
            }

            if (! in_array(
                $invoice->invoice_document_status,
                [InvoiceDocumentStatus::DRAFT, InvoiceDocumentStatus::PREPARED],
                true,
            )) {
                throw InvoiceNotIssuableException::forDocumentStatus($invoice, $invoice->invoice_document_status);
            }

            // §6 step 2: validate fiscal coherence — the emissions
            // requirements that exist today. The determination-validation step
            // of v7 §6.3 has no counterpart yet: the seam is named (§14) and
            // the §5 revival inserts it here without moving the rest.
            $this->assertIssuanceCoherence($invoice);

            // §6 step 3: conversion-born only — re-run FiscalChangeDetector
            // (ADR-001, the identity seam; the v7 §5.6 matrix arrives with the
            // §5 revival). Critical changes block issuance — identity is not
            // optional at the point the correlative is consumed; non-critical
            // ones do not re-litigate the policy the caller chose at
            // conversion (they were surfaced there).
            if ($conversionBorn && $proforma !== null) {
                $changes = $this->fiscalChangeDetector->detectChanges($proforma);

                if ($changes['has_critical_changes']) {
                    throw FiscalConfigChangedException::fromChanges($proforma, $changes);
                }
            }

            // §6 steps 4-5: ONE issuance instant governs the correlative AND
            // the four stamped values — no straddling across three
            // independent now() reads (§6.2). The numbering service joins the
            // AMBIENT transaction (§6.1, round 1 finding S1): no nested
            // DB::transaction; the first-use deadlock-recovery contract of
            // AID-390 is covered by THIS outermost retry.
            $instant = now();
            $number  = $this->invoiceNumbering->generateNumberInTransaction(
                $invoice->prefix,
                $invoice->serie->value,
                null, // the v6 issuance scope: the global issuer series (AID-390)
                $instant,
            );

            // The five from the instant (§6 step 5): prefix is NOT among them
            // (round 2, B6) — it is the series identity selected at creation.
            // fiscal_year is the CONFIGURED fiscal year of the instant
            // (RegionalContext, V4) — the numbering service derives it from
            // the same instant, so both sides agree at any boundary.
            $invoice->fiscal_number = $number->formatted;
            $invoice->series_number = $number->seriesNumber;
            $invoice->fiscal_year   = $number->fiscalYear;
            $invoice->invoice_date  = $instant->copy()->startOfDay();
            $invoice->issued_at     = $instant;

            // §6 steps 6-7: the row becomes fiscally immutable through the
            // §6.5 guards from here on (the guard's issuance transition is
            // this write); the submission stamp is the config-derived
            // decision of the PR-1 finding — auditable NOT_REQUIRED until R2
            // ships the audited decision operation (AID-1629).
            // AID-1649: the birth snapshot froze the DRAFT's fiscal context
            // (null number, the draft's fiscal year and date). The ISSUED row
            // must own a snapshot consistent with itself — regenerated from
            // the SAME instant that governs the correlative, with the SAME
            // generator the birth used (AID-328: one vocabulary per snapshot).
            // AID-1649 gate round 3, findings 2-3: the regeneration reads the
            // ROW's referenced config/profile with withTrashed() — soft
            // deletion is retained fiscal history (the coherence contract
            // accepts it), so ordinary findOrFail would reject rows the
            // coherence check accepts. All THREE snapshots regenerate from
            // the same rows at the issuance instant: the issued document
            // freezes its context as of issuance (ADR-001) — a stale birth
            // issuer/customer snapshot next to a fresh fiscal snapshot would
            // contradict the row it claims to describe.
            $companyConfig   = CompanyFiscalConfig::withTrashed()->findOrFail((int) $invoice->company_fiscal_config_id);
            $customerProfile = $invoice->user_tax_profile_id
                ? UserTaxProfile::withTrashed()->findOrFail((int) $invoice->user_tax_profile_id)
                : null;

            Invoice::withIssuanceAuthorization(function () use ($invoice, $companyConfig, $customerProfile): void {
                $invoice->invoice_document_status  = InvoiceDocumentStatus::ISSUED;
                $invoice->fiscal_submission_status = $this->resolveFiscalSubmissionStatus();
                $invoice->issuer_snapshot          = $invoice->generateIssuerSnapshot($companyConfig);
                if ($customerProfile !== null) {
                    // AID-1649 (CI phpstan): billableUser is a nullable
                    // relation — resolve the issuer model explicitly and fail
                    // loud (the coherence check guarantees the row's context
                    // resolves).
                    $issuerModel = $invoice->billableUser ?? $invoice->user;
                    $issuerModel ??= $customerProfile->owner()->firstOrFail();
                    $invoice->customer_snapshot = $invoice->generateBillableUserSnapshot($issuerModel, $customerProfile);
                }
                $invoice->fiscal_snapshot = $invoice->generateFiscalContextSnapshot($companyConfig, $customerProfile);
                $invoice->save();
            });

            // §6 step 8: document → ISSUED; conversion-born: the proforma's
            // collection axis moves CONVERTING → CONVERTED. The collection
            // axis of THIS document stays DRAFT (§5.2).
            if ($proforma !== null) {
                $proforma->update(['status' => InvoiceStatus::CONVERTED->value]);
            }

            return $invoice->fresh() ?? $invoice;
        }, 3);
    }

    /**
     * Release a conversion-born PREPARED document with an audit trail
     * (ADR-016 §10): the aborted preparation keeps its `proforma_id`
     * (documentary trace, never numbered), `document_cancelled_at/by` are
     * stamped with the actor, and the proforma returns to DRAFT —
     * `is_immutable` reverted, the conversion mirror cleared — so
     * re-conversion is possible. No determination to reuse (§5 revival).
     *
     * Direct DRAFT cancellation keeps the existing consumer path (legacy
     * `status = CANCELLED` + document CANCELLED, `document_cancelled_at`
     * stamped with actor NULL, §10 last paragraph).
     *
     * Retry ownership: same outermost-boundary scoping as `issue()`.
     *
     * @api New surface of the ADR-016 lifecycle (§11.1).
     *
     * @param  string  $actor  who ordered the release — the audit trail's
     *                         `document_cancelled_by` value
     *
     * @throws InvoiceNotCancellableException On anything but a conversion-born PREPARED document
     */
    public function cancelPreparedInvoice(Invoice $invoice, string $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor): Invoice {
            // Lock order (§6.3): proforma → invoice.
            $proforma = $invoice->proforma_id !== null
                ? Invoice::query()->whereKey($invoice->proforma_id)->lockForUpdate()->firstOrFail()
                : null;
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            // §10: conversion-born PREPARED only.
            if ($invoice->invoice_document_status !== InvoiceDocumentStatus::PREPARED) {
                throw InvoiceNotCancellableException::forDocumentStatus($invoice, $invoice->invoice_document_status);
            }

            if ($proforma === null) {
                throw InvoiceNotCancellableException::notConversionBorn($invoice);
            }

            // The document: CANCELLED, retaining proforma_id, never numbered.
            $invoice->invoice_document_status = InvoiceDocumentStatus::CANCELLED;
            $invoice->document_cancelled_at   = now();
            $invoice->document_cancelled_by   = $actor;
            $invoice->save();

            // The proforma: back to DRAFT, mirror cleared, re-convertible.
            $proforma->update([
                'status'               => InvoiceStatus::DRAFT->value,
                'is_immutable'         => false,
                'converted_invoice_id' => null,
                'converted_at'         => null,
            ]);

            return $invoice->fresh() ?? $invoice;
        }, 3);
    }

    /**
     * Convert a proforma AND issue the resulting document — the §3.3
     * compatibility vehicle for callers that want the v6 one-shot
     * conversion-and-issue shape, as TWO resumable transactions (ADR-016 §11).
     *
     * The conversion transaction commits first (proforma CONVERTING, document
     * PREPARED); the issuance transaction runs second. Issuance failure
     * leaves CONVERTING + PREPARED — legal and resumable: a retry converges
     * because the conversion is mirror-idempotent (§9) and `issue()` is
     * idempotent on an already-ISSUED document (§6 step 1) — never a second
     * invoice. Failure after the issuance commit leaves ISSUED with the
     * submission axis pending.
     *
     * @api New surface of the ADR-016 lifecycle (§11.1).
     *
     * @param  array{
     *     verify_fiscally?: bool,
     *     force?: bool,
     *     on_changes?: 'throw'|'warn'|'ignore',
     *     series?: string|null
     * }  $options  forwarded to the conversion; `verify_fiscally` runs
     *             post-issuance (verification requires the correlative)
     */
    public function convertAndIssue(Invoice $proforma, array $options = []): ProformaConversionResult
    {
        // Transaction 1: the conversion (§9).
        $result = $this->convertProformaToInvoice($proforma, $options);

        // Transaction 2: the issuance (§6). Resumable on every outcome.
        $invoice = $this->issue($result->invoice);

        if ($options['verify_fiscally'] ?? false) {
            $this->verifyInvoiceFiscally($invoice);
        }

        return new ProformaConversionResult($invoice->fresh() ?? $invoice, $result->warnings);
    }

    /**
     * Convert proforma to a PREPARED final invoice with locking (ADR-016 §9).
     *
     * The conversion NO LONGER issues (the breaking change of this major —
     * §3.3): it returns the PREPARED document — the verbatim contractual
     * clone retained (AID-555/556/559/576/929), the target series resolved
     * per operation (`prefix` stamped), NO correlative consumed, the five
     * number/issuance fields NULL, the submission axis NULL. The caller
     * decides: `issue()`, `convertAndIssue()`, or leave it prepared (the
     * stalled-preparations scope makes an abandoned one visible, §11.3).
     *
     * The proforma freezes at conversion: `CONVERTING` (the new case, §5.2),
     * `is_immutable = true` (content frozen — the v7 FROZEN stand-in), the
     * `proforma_id` canonical link + `converted_invoice_id` mirror written at
     * conversion (unchanged, AID-555), `converted_at` = the conversion
     * instant (unchanged).
     *
     * Typed command in / typed result out: the `Invoice|array` union is gone —
     * the `warnings` channel (`on_changes: warn`) travels in the typed result.
     *
     * attempts: 3 (AID-570) — same outermost-boundary contract as v6.
     * CONTRACT: the closure must stay side-effect-free outside the database.
     *
     * @param  array{
     *     force?: bool,
     *     on_changes?: 'throw'|'warn'|'ignore',
     *     series?: string|null
     * }  $options  `verify_fiscally` is accepted for call-shape compatibility
     *              but defers to the issuance: verification requires the
     *              correlative, so it runs in `convertAndIssue()` (or
     *              post-`issue()`); the plain PREPARED return does not verify.
     *
     * @throws FiscalConfigChangedException When critical changes detected and force=false
     */
    public function convertProformaToInvoice(Invoice $proforma, array $options = []): ProformaConversionResult
    {
        return DB::transaction(function () use ($proforma, $options): ProformaConversionResult {
            // AID-554 (D1): re-read the proforma under an exclusive row lock.
            // The idempotency check below must not race a concurrent conversion
            // of the same proforma — without the lock, N callers could all
            // observe converted_invoice_id = NULL and each mint a final invoice.
            $proforma = Invoice::whereKey($proforma->getKey())->lockForUpdate()->firstOrFail();

            if ($proforma->serie !== InvoiceSerieType::PROFORMA) {
                throw new \InvalidArgumentException('Invoice is not a proforma');
            }

            // Idempotency check (§3.2, unchanged): return the already-converted
            // document (PREPARED, or ISSUED when the caller went on to issue).
            if ($proforma->converted_invoice_id) {
                $existingInvoice = Invoice::find($proforma->converted_invoice_id);
                if ($existingInvoice) {
                    return new ProformaConversionResult($existingInvoice);
                }
                throw new \InvalidArgumentException('Proforma already converted to invoice');
            }

            // ADR-001: Detect fiscal configuration changes (policy consumed
            // HERE, verbatim v6 — the conversion is where the caller chose
            // force/on_changes; issue() re-runs the detector for critical
            // changes only, §6 step 3).
            $force     = $options['force']          ?? false;
            $onChanges = $options['on_changes']     ?? 'throw';
            $warnings  = [];

            $changes = $this->fiscalChangeDetector->detectChanges($proforma);

            if ($changes['has_changes']) {
                $summary = $this->fiscalChangeDetector->getChangeSummary($proforma);

                if ($changes['has_critical_changes'] && ! $force) {
                    // Critical changes - block conversion unless forced
                    throw FiscalConfigChangedException::fromChanges($proforma, $changes);
                }

                // Non-critical changes or forced - handle according to on_changes option
                if ($onChanges === 'throw' && ! $force) {
                    throw FiscalConfigChangedException::fromChanges($proforma, $changes);
                }

                if ($onChanges === 'warn') {
                    $warnings = array_values($summary);
                }
                // 'ignore' - just proceed
            }

            // §9: create the invoice as PREPARED — verbatim contractual clone
            // (AID-556/557), target series resolved per operation (AID-576),
            // NO correlative, five fields NULL, submission NULL. The
            // collection axis births at DRAFT (§5.2: the old `pending` encoded
            // the collapsed lifecycle that dies).
            $invoice = $this->createInvoiceRow([
                'billable_user_id' => (string) $proforma->billable_user_id,
                'user_id'          => (string) $proforma->user_id,
                // AID-555 (D2): canonical invoice→proforma link; the inverse
                // mirror (converted_invoice_id) alone left proforma() unreadable.
                'proforma_id'      => (string) $proforma->id,
                // AID-556 (D3) + AID-557 (D4): clone the proforma line VERBATIM.
                // The old 4-field rebuild dropped item_type, internal_code,
                // unit_measure_id, service dates and metadata, and re-derived
                // the amounts against the LIVE tax catalog — silently diverging
                // from the figures the customer accepted with the proforma.
                'items' => $proforma->items->map(fn (InvoiceItem $item): array => [
                    'article_id'        => $item->article_id,
                    'item_type'         => $item->item_type,
                    'description'       => $item->description,
                    'internal_code'     => $item->internal_code,
                    'quantity'          => $item->quantity->unscaledValue(),
                    'unit_measure_id'   => $item->unit_measure_id,
                    'base_price'        => $item->unit_price->unscaledValue(),
                    'service_date_from' => $item->service_date_from?->toDateString(),
                    'service_date_to'   => $item->service_date_to?->toDateString(),
                    'metadata'          => $item->metadata,
                    // Frozen amounts (ADR-001): copied, never recalculated.
                    'taxable_amount'    => $item->taxable_amount->unscaledValue(),
                    'total_tax_amount'  => $item->total_tax_amount->unscaledValue(),
                    'taxes_applied'     => $item->taxes_applied,
                    'total_amount'      => $item->total_amount->unscaledValue(),
                ])->toArray(),
                'type'          => 'invoice',
                // AID-559 (D10): the operation date the proforma declared
                // (RD 1619/2012 art. 6.1.f/i) survives the conversion. No
                // datum → null, documented — never an invented date.
                'service_date'  => $proforma->service_date?->toDateString(),
                // AID-929: the fiscal qualification of the operation travels
                // VERBATIM, exactly like service_date. Dropping it produced an
                // invoice that silently contradicted the proforma the customer
                // accepted — and no consumer could restore it afterwards.
                'is_roi_taxed'  => (bool) $proforma->is_roi_taxed,
                'due_date'      => $proforma->due_date?->toDateString(),
                'payment_terms' => $proforma->payment_terms ? (int) $proforma->payment_terms : null,
                'template_name' => $proforma->template_name,
                // AID-576: forward the per-call fiscal series a consumer opted
                // into (RD 1619/2012 art. 6 — multiple series for the same
                // type). null → InvoiceSeriesResolver resolves the default.
                'series'        => $options['series'] ?? null,
            ], [], InvoiceSerieType::INVOICE, InvoiceStatus::DRAFT, false, InvoiceDocumentStatus::PREPARED);

            // §9: the proforma freezes at conversion — CONVERTING, immutable,
            // mirror written at conversion (unchanged), conversion instant.
            $proforma->update([
                'is_immutable'         => true,
                'converted_invoice_id' => $invoice->id,
                'converted_at'         => now(),
                'status'               => InvoiceStatus::CONVERTING->value,
            ]);

            return new ProformaConversionResult($invoice, $warnings);
        }, 3);
    }

    /**
     * Create a proforma invoice.
     *
     * ADR-003: Uses billable_user_id (User) instead of customer_id.
     * ADR-016 §3.1/§7.2: this rides the PROFORMA branch of `createInvoice()` —
     * the v6 numbered-at-birth behavior on the PRO serie, verbatim.
     *
     * @param  array{
     *     billable_user_id: string,
     *     user_id?: string,
     *     items: array<int, InvoiceItemData>,
     *     service_date?: string|null,
     *     due_date?: string|null,
     *     payment_terms?: int|null,
     *     template_name?: string|null,
     *     is_roi_taxed?: bool
     * }  $invoiceData
     * @param  array{make_immutable?: bool}  $options
     */
    public function createProforma(array $invoiceData, array $options = []): Invoice
    {
        $invoiceData['type']   = 'proforma';
        $invoiceData['status'] = 'draft';

        // Proformas are never fiscally verified at creation
        $options['verify_fiscally'] = false;

        return $this->createInvoice($invoiceData, $options);
    }

    /**
     * Check if a proforma can be safely converted without fiscal changes.
     */
    public function canConvertProformaSafely(Invoice $proforma): bool
    {
        return $this->fiscalChangeDetector->canConvertSafely($proforma);
    }

    /**
     * Get fiscal changes summary for a proforma.
     *
     * @return array<string, mixed>
     */
    public function getProformaFiscalChanges(Invoice $proforma): array
    {
        return $this->fiscalChangeDetector->detectChanges($proforma);
    }

    /**
     * §7.2: the lifecycle outcomes are never birth inputs. Cancellation is a
     * transition on a DRAFT/PREPARED row (§5.1, §10), never a birth state; a
     * caller wanting a cancelled document creates the DRAFT and cancels it.
     * With this list the InvoiceStatus enum is exhausted at birth: DRAFT
     * accepted (unnumbered), SENT/PAID/OVERDUE accepted as post-issuance
     * collection stamps, the four lifecycle outcomes rejected.
     */
    protected function assertStatusIsBirthEligible(InvoiceStatus $status): void
    {
        if (in_array($status, [
            InvoiceStatus::PENDING,
            InvoiceStatus::CONVERTED,
            InvoiceStatus::CANCELLED,
            InvoiceStatus::CONVERTING,
            InvoiceStatus::PARTIALLY_PAID,
        ], true)) {
            throw InvalidInvoiceStatusException::forbiddenBirthState($status);
        }
    }

    /**
     * §6 step 2: validate the fiscal coherence the emissions requirements of
     * today demand — active issuer config, resolved fiscal content on the
     * row, and the AID-971 frozen-snapshot rule (a referenced profile must
     * still resolve). The series is resolvable by construction: `prefix` was
     * stamped at creation (§8.1) and the numbering service fails loud on an
     * unresolvable serie (AID-589).
     *
     * @throws InvoiceNotIssuableException naming the missing piece
     */
    protected function assertIssuanceCoherence(Invoice $invoice): void
    {
        if ($invoice->company_fiscal_config_id === null) {
            throw InvoiceNotIssuableException::missingFiscalContext($invoice, 'the issuer fiscal config (company_fiscal_config_id)');
        }

        if (CompanyFiscalConfig::getActive() === null) {
            throw InvoiceNotIssuableException::missingFiscalContext($invoice, 'an active CompanyFiscalConfig');
        }

        if ($invoice->issuer_snapshot === null || $invoice->customer_snapshot === null) {
            throw InvoiceNotIssuableException::missingFiscalContext($invoice, 'the encrypted fiscal content (issuer/customer snapshots)');
        }

        // AID-971 frozen-snapshot rule preserved: a referenced profile must
        // still resolve (withTrashed — a soft-deleted profile is fiscal
        // history closure, not erasure, AID-222).
        if ($invoice->user_tax_profile_id !== null
            && UserTaxProfile::withTrashed()->find($invoice->user_tax_profile_id) === null) {
            throw InvoiceNotIssuableException::missingFiscalContext($invoice, 'the referenced user tax profile (user_tax_profile_id)');
        }
    }

    /**
     * §6 step 7: the submission stamp at issuance — a config-derived,
     * auditable decision recorded on the row. The PR-1 config finding holds
     * (config/larabill.php ships NO Verifactu-compliance key today — only the
     * generic CEE compliance rules and the QR document contract, neither of
     * which declares Verifactu operation), so the auditable NOT_REQUIRED is
     * stamped; the audited decision operation ships with R2 (AID-1629)
     * together with the outbox.
     */
    protected function resolveFiscalSubmissionStatus(): FiscalSubmissionStatus
    {
        return FiscalSubmissionStatus::NOT_REQUIRED;
    }

    /**
     * Build and persist one invoice row with its items, totals and snapshots —
     * the shared core of BOTH birth contracts (ADR-016 §7.2): the caller
     * decides the numbering and the document state, the branches never mix.
     *
     * - `$numberAtBirth = true` (the PROFORMA contract, §3.1): the correlative
     *   is consumed here, on the caller's transaction, and the five
     *   number/issuance fields are stamped from `now()` exactly as v6 did.
     * - `$numberAtBirth = false` (the FISCAL contract, §7.1/§9): NO numbering
     *   call happens — the row births unnumbered (five fields NULL) at
     *   DRAFT (direct) or PREPARED (conversion).
     *
     * The transitional stamping of PR-1 (§8.5) DIES here: fiscal births carry
     * `invoice_document_status = DRAFT`/`PREPARED` and
     * `fiscal_submission_status = NULL`.
     *
     * @param  array{
     *     billable_user_id: string,
     *     user_id?: string,
     *     items: array<int, InvoiceItemData>,
     *     series?: string|null,
     *     service_date?: string|null,
     *     due_date?: string|null,
     *     payment_terms?: int|null,
     *     template_name?: string|null,
     *     proforma_id?: string|null,
     *     rectifies_invoice_id?: string|null,
     *     settlement_operation_key?: string|null,
     *     is_historical?: bool,
     *     is_roi_taxed?: bool,
     *     customer_snapshot?: string|null,
     *     user_tax_profile_id?: string|null
     * }  $invoiceData
     * @param  array{
     *     make_immutable?: bool,
     *     verify_fiscally?: bool
     * }  $options
     */
    protected function createInvoiceRow(
        array $invoiceData,
        array $options,
        InvoiceSerieType $serieType,
        InvoiceStatus $status,
        bool $numberAtBirth,
        InvoiceDocumentStatus $documentStatus = InvoiceDocumentStatus::DRAFT
    ): Invoice {
        // Get billable user (ADR-003)
        $userModel    = ModelMappingService::getModelClass('user');
        $billableUser = $userModel::findOrFail($invoiceData['billable_user_id']);

        $companyConfig = CompanyFiscalConfig::getActive();

        if (! $companyConfig) {
            throw new \RuntimeException('No valid CompanyFiscalConfig found for invoice creation');
        }

        // Get user tax profile for billable user. AID-971: when a FROZEN
        // customer snapshot is supplied (the rectificative path — ADR-014),
        // the CURRENT profile is deliberately NOT resolved: the recipient
        // is the original's. The referenced profile must still resolve —
        // findOrFail fails loud, never a silent fallback (AID-589).
        $frozenCustomerSnapshot = $invoiceData['customer_snapshot'] ?? null;

        if ($frozenCustomerSnapshot !== null) {
            $userTaxProfile = ($invoiceData['user_tax_profile_id'] ?? null) !== null
                ? UserTaxProfile::query()->findOrFail($invoiceData['user_tax_profile_id'])
                : null;
        } else {
            $userTaxProfile = UserTaxProfile::getValidForOwnerAt(
                $billableUser->id,
                now()
            );
        }

        $serie = $serieType->value;

        // Resolve the real fiscal series (AID-307): the default for this
        // fiscal type, or an explicit series the caller opted into to run
        // multiple series for the same type (RD 1619/2012 art. 6). The
        // prefix is stamped AT CREATION for every row (§8.1) — only the
        // number waits for issue().
        $series = $this->seriesResolver->resolve($serieType, $invoiceData['series'] ?? null);

        // ADR-016 §6.1 (round 12, V17): the ONLY numbering call sites in the
        // package are the proforma birth (here, §3.1 verbatim) and issue().
        // A fiscal row is never born numbered.
        $number = $numberAtBirth
            ? $this->invoiceNumbering->generateNumber($series, $serie)
            : null;

        $invoice = Invoice::create([
            'fiscal_number'             => $number?->formatted,
            'prefix'                    => $series,
            'serie'                     => $serie,
            'series_number'             => $number?->seriesNumber,
            'fiscal_year'               => $number?->fiscalYear,
            'invoice_date'              => $number !== null ? now()->toDateString() : null,
            'issued_at'                 => $number !== null ? now() : null,
            // ADR-016 §5.1/§7.1: the document axis exists only for fiscal rows
            // (proforma rows stamp NULL, §8.2 C2); fiscal rows birth at their
            // document state — DRAFT (direct) or PREPARED (conversion-born).
            'invoice_document_status'   => $serieType === InvoiceSerieType::PROFORMA
                ? null
                : $documentStatus,
            // ADR-016 §6 step 7: the submission axis is set at issuance ONLY
            // (§8.2 C6) — every birth carries NULL on it.
            'fiscal_submission_status'  => null,
            'service_date'              => $invoiceData['service_date'] ?? null,
            'status'                    => $status,
            'billable_user_id'          => $billableUser->id,
            'user_id'                   => $invoiceData['user_id'] ?? $billableUser->parent_user_id ?? $billableUser->id,
            'company_fiscal_config_id'  => $companyConfig->id,
            'user_tax_profile_id'       => $userTaxProfile?->id,
            'proforma_id'               => $invoiceData['proforma_id']          ?? null,
            'rectifies_invoice_id'      => $invoiceData['rectifies_invoice_id'] ?? null,
            // ADR-015 §3.4 (guarantee settlement): the deterministic
            // operation key of an idempotent settlement claim, stamped at
            // creation — the UNIQUE index on this column is what serialises
            // concurrent issuances (collision-and-adoption). Absent key =>
            // null, so nothing changes for callers that ignore it.
            'settlement_operation_key'  => $invoiceData['settlement_operation_key'] ?? null,
            // AID-895 (ADR-015 §2.5, import support): the importer sets
            // this on EVERY document migrated from the legacy system.
            // Absent key => false (the additive-input pattern), so
            // nothing changes for callers that ignore it.
            'is_historical'             => $invoiceData['is_historical'] ?? false,
            // AID-929: the consumer DECLARES the reverse-charge
            // qualification (AID-309 doctrine — the package consumes the
            // flag, it never infers it from a live VIES lookup). Absent
            // key => false, so nothing changes for callers that ignore it.
            'is_roi_taxed'              => $invoiceData['is_roi_taxed'] ?? false,
            'is_immutable'              => false,
            'due_date'                  => $invoiceData['due_date']      ?? null,
            'payment_terms'             => $invoiceData['payment_terms'] ?? null,
            'template_name'             => $invoiceData['template_name'] ?? null,
        ]);

        // Create invoice items
        $items = $invoiceData['items'] ?? [];
        foreach ($items as $itemData) {
            $this->createInvoiceItem($invoice, $itemData);
        }

        // Calculate totals
        $invoice->taxable_amount   = $invoice->items->reduce(fn (FixedDecimal $c, InvoiceItem $i) => $c->plus($i->taxable_amount), FixedDecimal::zero(2));
        $invoice->total_tax_amount = $invoice->items->reduce(fn (FixedDecimal $c, InvoiceItem $i) => $c->plus($i->total_tax_amount), FixedDecimal::zero(2));
        $invoice->total_amount     = $invoice->items->reduce(fn (FixedDecimal $c, InvoiceItem $i) => $c->plus($i->total_amount), FixedDecimal::zero(2));

        // AID-929 (D4): reverse charge and real tax cannot coexist (AEAT
        // rule 1237). Checked HERE — after the lines exist and the totals
        // are known, before any snapshot is frozen and before sealing —
        // and inside this transaction, so a refusal leaves no invoice, no
        // lines and no consumed fiscal number. VerifactuAdapter enforces
        // the same rule, but only at registration time: by then the
        // document has already been born and sealed.
        if ($invoice->is_roi_taxed && $invoice->hasRealTax()) {
            throw ReverseChargeWithTaxException::forInvoice($invoice);
        }

        // Generate encrypted snapshots
        $invoice->issuer_snapshot   = $this->generateIssuerSnapshot($companyConfig);
        // AID-971 (ADR-014): a supplied customer snapshot travels VERBATIM —
        // the rectificative reuses the original's frozen recipient even if
        // the fiscal profile changed since emission. Absent → generate the
        // snapshot from the billable user and its current profile, exactly
        // as before (byte-for-byte unchanged path).
        $invoice->customer_snapshot = $frozenCustomerSnapshot
            ?? $this->generateBillableUserSnapshot($billableUser, $userTaxProfile);
        $invoice->fiscal_snapshot   = $invoice->generateFiscalContextSnapshot($companyConfig, $userTaxProfile);

        $invoice->save();

        // Make immutable if requested
        if ($options['make_immutable'] ?? false) {
            $invoice->makeImmutable();
        }

        // Fiscal verification if requested. Only a NUMBERED birth can verify
        // (the verification payload carries the correlative): the proforma
        // branch honors the option verbatim; on the fiscal contract the
        // document is unnumbered at birth, so verification defers to the
        // issuance phase (§7.1 phase 2 / convertAndIssue §11).
        if ($numberAtBirth && ($options['verify_fiscally'] ?? false)) {
            $this->verifyInvoiceFiscally($invoice);
        }

        return $invoice->fresh() ?? $invoice;
    }

    /**
     * Generate encrypted issuer snapshot from CompanyFiscalConfig.
     */
    protected function generateIssuerSnapshot(CompanyFiscalConfig $config): string
    {
        $data = [
            'business_name'     => $config->business_name,
            'tax_id'            => $config->tax_id,
            'legal_entity_type' => $config->legal_entity_type,
            'address'           => $config->address,
            'city'              => $config->city,
            'state'             => $config->state,
            'zip_code'          => $config->zip_code,
            'country_code'      => $config->country_code,
            'is_oss'            => $config->is_oss,
            'is_roi'            => $config->is_roi,
            'currency'          => $config->currency,
            'snapshot_at'       => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Generate encrypted billable user snapshot from User + UserTaxProfile (ADR-003).
     */
    protected function generateBillableUserSnapshot(Model $billableUser, ?UserTaxProfile $fiscalData): string
    {
        $data = [
            'billable_user_id'     => $billableUser->id,
            'user_name'            => $billableUser->display_name              ?? $billableUser->name,
            'relationship_type'    => $billableUser->relationship_type?->value ?? 0,
            'fiscal_name'          => $fiscalData?->fiscal_name                ?? $billableUser->display_name ?? $billableUser->name,
            'tax_id'               => $fiscalData?->tax_id,
            'legal_entity_type'    => $fiscalData?->legal_entity_type_code     ?? $billableUser->legal_entity_type_code ?? null,
            'address'              => $fiscalData?->address,
            'city'                 => $fiscalData?->city,
            'state'                => $fiscalData?->state,
            'zip_code'             => $fiscalData?->zip_code,
            'country_code'         => $fiscalData?->country_code,
            'is_company'           => $fiscalData?->is_company                 ?? false,
            'is_eu_vat_registered' => $fiscalData?->is_eu_vat_registered       ?? false,
            'is_exempt_vat'        => $fiscalData?->is_exempt_vat              ?? false,
            'snapshot_at'          => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Generate encrypted fiscal snapshot.
     */
    /**
     * Aggregate the tax rules actually applied across the invoice lines
     * (AID-534): one entry per distinct rule, base and amount summed from the
     * items' immutable taxes_applied snapshots — same frozen source, same
     * instant, so the fiscal snapshot cannot drift from the lines. Ordered by
     * rate descending (the tax-breakdown precedent, AID-508).
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Verify invoice fiscally (dispatch to external package).
     */
    protected function verifyInvoiceFiscally(Invoice $invoice): void
    {
        if (! $this->fiscalVerification) {
            return; // No fiscal verification configured
        }

        // This will dispatch a job in the external package (e.g., lara-verifactu)
        // ADR-003: Uses billable_user_id instead of customer_id
        $result = $this->fiscalVerification->verify([
            'id'               => $invoice->id,
            'fiscal_number'    => $invoice->fiscal_number,
            'serie'            => $invoice->serie->value,
            'series_number'    => $invoice->series_number,
            'fiscal_year'      => $invoice->fiscal_year,
            'invoice_date'     => $invoice->invoice_date,
            'billable_user_id' => $invoice->billable_user_id,
            'total_amount'     => $invoice->total_amount->unscaledValue(),
            'taxable_amount'   => $invoice->taxable_amount->unscaledValue(),
        ]);

        if ($result['success']) {
            $invoice->update([
                'fiscal_verification_id'       => $result['id']   ?? null,
                'fiscal_verification_qr'       => $result['qr']   ?? null,
                'fiscal_verification_hash'     => $result['hash'] ?? null,
                'fiscal_verified_at'           => now(),
                'fiscal_verification_metadata' => $result['metadata'] ?? [],
            ]);
        }
    }

    /**
     * Create an invoice item with tax calculation.
     *
     * When the frozen keys are present (total_amount et al.), the line is
     * persisted VERBATIM — the proforma-conversion path (AID-556/AID-557):
     * amounts and the taxes_applied snapshot were already frozen with the
     * proforma (ADR-001) and must never be re-derived from the live catalog.
     *
     * `deducts_invoice_id` (AID-962 §7) names the issued advance invoice a
     * negative, frozen line deducts; it is persisted on both paths.
     *
     * @param  InvoiceItemData  $itemData
     */
    protected function createInvoiceItem(Invoice $invoice, array $itemData): InvoiceItem
    {
        $quantity  = $itemData['quantity']    ?? 100; // base-100: 1.00 unit
        $basePrice = $itemData['base_price']  ?? ($itemData['unit_price'] ?? 0);

        if (array_key_exists('total_amount', $itemData)) {
            return InvoiceItem::create([
                'invoice_id'         => $invoice->id,
                'article_id'         => $itemData['article_id']        ?? null,
                'item_type'          => $itemData['item_type']         ?? ItemType::GOOD,
                'description'        => $itemData['description']       ?? '',
                'internal_code'      => $itemData['internal_code']     ?? null,
                'quantity'           => FixedDecimal::ofUnscaled((int) $quantity, 2),
                'unit_measure_id'    => $itemData['unit_measure_id']   ?? null,
                'unit_price'         => FixedDecimal::ofUnscaled((int) $basePrice, 2),
                'taxable_amount'     => FixedDecimal::ofUnscaled((int) ($itemData['taxable_amount'] ?? 0), 2),
                'total_tax_amount'   => FixedDecimal::ofUnscaled((int) ($itemData['total_tax_amount'] ?? 0), 2),
                'taxes_applied'      => $itemData['taxes_applied']     ?? [],
                'total_amount'       => FixedDecimal::ofUnscaled((int) ($itemData['total_amount'] ?? 0), 2),
                'service_date_from'  => $itemData['service_date_from']  ?? null,
                'service_date_to'    => $itemData['service_date_to']    ?? null,
                'metadata'           => $itemData['metadata']           ?? null,
                'deducts_invoice_id' => $itemData['deducts_invoice_id'] ?? null,
            ]);
        }

        // Calculate taxes using TaxCalculationService (ADR-003: billable_user_id)
        $taxCalculation = $this->taxCalculationService->calculateForInvoiceItem([
            'article_id'       => $itemData['article_id']   ?? 0,
            'tax_group_id'     => $itemData['tax_group_id'] ?? null,
            'quantity'         => $quantity,
            'base_price'       => $basePrice,
            'billable_user_id' => $invoice->billable_user_id,
        ]);

        // AID-836: persist every optional field the InvoiceItemData shape
        // promises. They used to be dropped on this branch, which broke any
        // caller relying on them — notably the recurring flow's idempotency
        // check, which filters on metadata + service_date_from.
        return InvoiceItem::create([
            'invoice_id'         => $invoice->id,
            'article_id'         => $itemData['article_id']        ?? null,
            'item_type'          => $itemData['item_type']         ?? ItemType::GOOD,
            'description'        => $itemData['description']       ?? '',
            'internal_code'      => $itemData['internal_code']     ?? null,
            'quantity'           => FixedDecimal::ofUnscaled((int) $quantity, 2),
            'unit_measure_id'    => $itemData['unit_measure_id']   ?? null,
            'unit_price'         => FixedDecimal::ofUnscaled((int) $basePrice, 2),
            'taxable_amount'     => FixedDecimal::ofUnscaled((int) $taxCalculation['taxable_amount'], 2),
            'total_tax_amount'   => FixedDecimal::ofUnscaled((int) $taxCalculation['total_tax_amount'], 2),
            'taxes_applied'      => $taxCalculation['taxes_applied'],
            'total_amount'       => FixedDecimal::ofUnscaled((int) $taxCalculation['total_amount'], 2),
            'service_date_from'  => $itemData['service_date_from']  ?? null,
            'service_date_to'    => $itemData['service_date_to']    ?? null,
            'metadata'           => $itemData['metadata']           ?? null,
            'deducts_invoice_id' => $itemData['deducts_invoice_id'] ?? null,
        ]);
    }

    /**
     * Map status (string or int) to enum value.
     */
    protected function mapStatusToEnum(string|int $status): int
    {
        // AID-838: validated, not guessed. The accepted names are DERIVED from
        // the enum instead of listed here — the hand-written map had drifted to
        // four of the seven cases, and 'sent'/'overdue'/'converted' (plus every
        // typo) fell through a `default` that quietly returned DRAFT. Deriving
        // them means a case added to InvoiceStatus can never become a silent
        // fallback again. NOTE: the case NAME is the key, not label(), which
        // returns a translation and would make this locale-dependent.
        if (is_int($status)) {
            return (InvoiceStatus::tryFrom($status)
                ?? throw InvalidInvoiceStatusException::forValue($status))->value;
        }

        $normalized = mb_strtolower(trim($status));

        foreach (InvoiceStatus::cases() as $case) {
            if (mb_strtolower($case->name) === $normalized) {
                return $case->value;
            }
        }

        throw InvalidInvoiceStatusException::forValue($status);
    }
}
