# Integration with openmizan (accounting)

- **Status:** contract principles decided; the exact API payload is fixed by the openmizan spec for AID-1607 and this document is updated when that spec is approved.
- **Tracking:** larabill AID-1609; openmizan AID-1607 (issued documents over the API), AID-1578 (API publication), AID-1608 (collections).
- **Last reviewed:** 2026-10-12, against larabill `origin/main` `c72de2e` and clientes `0c3aead` (citation audit: art. 6 RD 1619/2012 checked against the BOE consolidated text).

This document is the communication channel between larabill and openmizan. It states what openmizan will do with the invoices larabill issues, and what larabill has to make operational before a consumer (first one: Clientes) can feed openmizan in production.

## Roles

- **larabill** issues the invoice: it owns the numbering, the immutable issued document and the observable fiscal-submission state.
- **lara-verifactu** declares it to the AEAT: registration record, hash chain and submission outcomes.
- **openmizan** records it in the books. It never numbers, never recalculates taxes and never declares anything to the AEAT.
- **Proformas never reach openmizan.** A proforma has no fiscal or accounting effect; openmizan only sees an invoice once it is issued (in the proforma flow, when it is paid).

## What openmizan will expect

- **Structured number.** Each issued invoice carries its series code (larabill's `prefix`), its numbering year (`fiscal_year`) and its integer correlative (`series_number`), plus the formatted number as printed on the invoice. openmizan never parses the formatted string; larabill's `InvoiceNumber` value object already exposes the three pieces.
- **One series per document type.** Invoices and rectificatives live in separate series, as larabill already separates them.
- **Strict sequence per series and year.** The next number accepted is exactly the last one recorded plus one (or the declared opening number when a series starts mid-year).
- **Chronological consistency.** An issue date earlier than the previous number's issue date in the same series is rejected. The normative anchor for the numbering itself is art. 6.1.a) RD 1619/2012 — numbering within a series MUST be correlative (the RD does not literally mandate date ordering; that rule is openmizan's own acceptance check, consistent with correlative numbering and the VeriFactu registration practice).
- **Sent at issuance, with the Verifactu data.** The issuer does not wait for the AEAT response. A record the AEAT rejects is fixed upstream by subsanación; a wrong invoice is fixed with a rectificative.
- **Verifactu is per company and one-way.** Once the company is obliged, it stays obliged; an issued invoice without Verifactu data is rejected for an obliged company.
- **Revenue account per line.** Each invoice line carries the revenue account code (group 7XX) chosen by the product configuration in the billing program. openmizan validates that the account exists; it does not choose it. Tax accounts are group 47XX.
- **Customer identity.** openmizan gives each customer its own receivable subaccount and keeps the billing program's customer code as a searchable alias, so larabill must send a stable customer code.
- **Idempotent replay.** Re-sending the same number with the same content is a no-op; the same number with different content is a conflict.
- **Reconciliation read.** openmizan exposes the last recorded number per series, so the issuer can find out where it stands after a failure.

## What openmizan will reject

| Case | Error |
|---|---|
| Number is not the next one in its series and year | `series.out_of_sequence` (the response states the expected number) |
| Issue date earlier than the previous number's | chronology error |
| Unknown series | `series.unknown` |
| Obliged company, no Verifactu data | Verifactu error |
| Same number, different content | conflict |

With strict sequence, **a gap blocks the series** in openmizan until it is corrected at the source. This is the main reason the requirements below exist.

## What larabill has to make operational

### R1 — Number only at issuance, never leave a gap

- `src/Services/InvoiceNumberingService.php:252`, the continuity seed, states "a gap is acceptable, a reused number never is". Under openmizan both are unacceptable.
- Clientes allocates the number when the draft is created (`app/Services/Invoices/ManualInvoiceCreator.php:53`, invoice created as `DRAFT`), and `app/Policies/InvoicePolicy.php:106` allows deleting a draft. A numbered draft that is deleted leaves a gap.
- The v7 lifecycle design already solved this (`docs/superpowers/specs/2026-07-13-proforma-invoice-lifecycle-v7-design.md` §6: a numbered non-issued row is impossible, the correlative is assigned at issuance only), but its epic AID-459 was canceled. Whatever replaces it must keep that invariant.

### R2 — Durable delivery to accounting (outbox)

- There is no outbound integration today.
- Needed: one delivery intent per issued invoice, created in the issuance transaction, keyed by series + year + number, retried until openmizan acknowledges it, and reconciled against openmizan's last-number read after a failure.
- The fiscal-submission outbox in the v7 design (§6.7) is the same shape and can be the model.

### R3 — Fiscal-submission state visible on the invoice

- `src/Listeners/SyncLarabillInvoiceVerification.php` only reacts to `InvoiceRegisteredEvent` and copies the registry status once into `fiscal_verification_metadata`. Failures and later status changes of the record are not reflected on the invoice.
- Needed: an observable state per invoice (the v7 design proposed `FiscalSubmissionStatus`: not required, pending, registered, action required, content rejected), fed by typed outcomes from lara-verifactu, never by parsing messages.

### R4 — Gap detection per series

- A report or check that lists missing correlatives per series and year, so a gap is found in the billing program before openmizan rejects the next invoice.

### R5 — Accounting account per line

- `InvoiceItem` has no field for the revenue account code. The product configuration (in the consumer) chooses the account; larabill has to carry it on the line through to delivery.

### R6 — Verifactu enabled means enabled forever

- In Clientes the switch is an environment variable that can be turned off again (`config/larafactu.php:112-117`, `CASTRIS_VERIFACTU_ENABLED`). The obligation has to be one-way: once on, the software must not allow turning it off.

## Order of work

Fixes go top-down: **lara-verifactu → larabill → clientes**. lara-verifactu has its own gaps (records left pending are never resent, no accepted-with-errors state, exhausted retries are not reported); they will be tracked in a lara-verifactu ticket of their own, and R3 depends on its typed outcomes.

## Out of scope here

- **Collections and reconciliation** (openmizan AID-1608). The invoicing entry and the collection entries are separate facts; larabill's grouped-payments design will be the input for that work.
- **Exposure and authentication** of the openmizan API (AID-1578): one token per installation, bound to its own series.
