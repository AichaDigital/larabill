# Integration with openmizan (accounting)

- **Status:** contract approved and delivered. The exact API v1 payloads in this document are fixed by the approved openmizan spec for AID-1607 (approved 2026-10-11 after three review rounds and the MR gates; delivered in openmizan v0.84.3) and cited by that spec's sections below.
- **Tracking:** larabill AID-1609 (this update: AID-1663); openmizan AID-1607 (issued documents over the API — **Done**, v0.84.3), AID-1578 (API publication), AID-1608 (collections).
- **Last reviewed:** 2026-10-14, against larabill `main` `c7dccf7` and the openmizan spec for AID-1607 (`docs/superpowers/specs/2026-10-11-aid-1607-api-v1-issued-documents-design.md`, openmizan v0.84.3).

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
| Number is not the next one in its series and year | `series.out_of_sequence` (the response states the expected number in `details`, §8) |
| Issue date earlier than the previous number's | `series.issue_date_before_previous` (§8; the same day is allowed) |
| Number already registered | `series.number_already_registered`, 409 (§8) |
| Unknown series | `series.unknown` |
| Obliged company, no Verifactu data | `verifactu.huella_required` (§9) |
| Same reference, different content | `fingerprint_conflict`, 409 (§11) |
| Same number, different reference | `series.number_already_registered`, 409 (§11) |

With strict sequence, **a gap blocks the series** in openmizan until it is corrected at the source. This is the main reason the requirements below exist.

The full error catalogue with every refusal code is §12 of the spec. A refused number still consumes its place in the series: a number openmizan refuses blocks the rest of that series until it is corrected at the source (spec §17, MR 3, follow-up openmizan AID-1643) — another reason R1 and R4 below exist.

## The API v1 contract (approved spec)

The payloads in this section are the integration contract openmizan expects from the consumer, fixed by the approved spec for AID-1607 (openmizan v0.84.3). Citations in parentheses refer to that spec's sections. The routes stay inside today's loopback lane until AID-1578 publishes the API (§5).

### Envelope and general rules (§5.2, §11, §17)

- **One write, one reference.** Every write carries `brand` (integer 1–99) and `external_reference` (string, max 191), the issuer's own identifier of the document or registration. It keys idempotency (§11): same reference and same content → 200 replay; same reference and different content → 409 `fingerprint_conflict`; a different reference claiming an already-registered number → 409 `series.number_already_registered`.
- **Issued bodies are wrapped in `data`** (§17, MR 3 execution ruling): `POST /api/v1/invoices/issued` and `POST /api/v1/credit-notes/issued` take the payload below inside a `data` object, the received side's shape.
- **Unknown fields are refused; absence is omission, never null** (§5.2). An optional field that does not apply is omitted from the payload; an explicit null is a 422. This applies to the tax id too (§10.3): a customer registered without one is invoiced with neither `tax_id` nor `tax_id_country` present.
- **Amounts** are canonical strings `^\d+\.\d{2}$`; `vat_rate_bp` runs from 0 to 10000 (basis points: 2100 = 21 %) (§5.2).
- **Accounts** are the company's full account codes, 12 digits for an API-issuing company (§10.4).
- **Dates** are `Y-m-d`. The document posts at its **operation date**; the issue date is the document date and orders the series (§5.2, amended by openmizan ruling R26).
- **`withholdings` is refused** with its own fiscal-debt code (`document.issued_withholding_not_supported`), not the generic unknown-field error (§5.2, §12).

### Issued invoice payload (§5.2)

`POST /api/v1/invoices/issued` — 201 created, 200 replay, 409 conflict, 422 refusal. The inner object (sent wrapped in `data`):

```json
{
  "brand": 1,
  "external_reference": "larabill:invoice:0198f2c4-7b1e-7c3a-9e2d-5f0a1b2c3d4e",
  "number": { "series_code": "CAS", "fiscal_year": 2026, "sequence": 124, "display": "CAS-2026-000124" },
  "issue_date": "2026-10-11",
  "operation_date": "2026-10-11",
  "customer": {
    "account_code": "430001000017",
    "tax_id": "B12345674",
    "tax_id_country": "ES",
    "country_code": "ES",
    "display_name": "Example Hosting SL"
  },
  "fiscal_classification": "domestic",
  "total_amount": "121.00",
  "contributions": [
    { "revenue_account_code": "705000000000", "taxable_base": "100.00", "vat_rate_bp": 2100, "vat_amount": "21.00" }
  ],
  "verifactu": { "huella": "9F86D081884C7D659A2FEAA0C55AD015A3BF4F1B2B0B822CD15D6C15B0F00A08" },
  "description": "optional, max 255"
}
```

- **Number:** `series_code` max 20; `fiscal_year` four digits and equal to the calendar year of `issue_date` (§8); `sequence` integer ≥ 1; `display` max 80, stored unparsed as the document reference.
- **Customer:** `account_code` (12 digits) identifies the customer — the account its registration answered with (§10.2). The identity is cross-checked against the stored customer (§10.1): `country_code`, `tax_id` and `tax_id_country` must match (mismatch → `customer.identity_mismatch`; the name may differ — the document stores the name it was sent with).
- **Classification:** `fiscal_classification` is one of `domestic`, `eu_intra_community`, `non_eu`. `intra_community_operation_kind` is required for an `eu_intra_community` document without a profile and prohibited on a `domestic` one. `vat_treatment_profile_slug` is required for `non_eu` and refused for `domestic`; an `eu_intra_community` service without a profile is refused (`document.vat_treatment_profile_required`) and goods are fiscal debt (`document.intracom_issued_requires_service`) (§5.2).
- **Revenue accounts:** exactly one form — `revenue_account_code` on every contribution, or the header-level `revenue_account_code` (§7.1). Codes must exist, be of type `standard` and start with 70.
- **The customer is never created here:** no customer number, aliases or VIES flag travel with the invoice (§5.2, §1 ruling 15).

### Issued credit note payload (§5.3)

`POST /api/v1/credit-notes/issued` — the invoice payload (§5.2) with the number belonging to a `credit_note_issued` series, plus:

```json
{
  "rectifies": { "document_type": "invoice_issued", "series_code": "CAS", "fiscal_year": 2026, "sequence": 120, "display": "CAS-2026-000120" },
  "direction": "decrement",
  "rectification_reason": "max 2000"
}
```

- **`rectifies.document_type`** is required: `invoice_issued` or `credit_note_issued` — an issued credit note may rectify either (§5.3).
- **Number and display are one identity** (§5.3): `rectifies`' series, fiscal year, sequence and `display` must all name the same original; any disagreement → `credit_note.original_number_mismatch`.
- **The original must belong to the same customer** → `credit_note.customer_mismatch` otherwise (§5.3). Not found: before the series' opening point it is recorded as the external rectified reference; otherwise → `credit_note.original_not_found`.
- **The credit note sends its own revenue accounts** (either form, §7.1); they are not compared with the original's — a partial rectification may touch one line only. It keeps its own number and series and posts at its own operation date, even when the original's fiscal year is closed (§5.3).
- **Direction:** `decrement` or `increment`.

### Customer registration payload (§5.4)

`POST /api/v1/customers` — 201 created; 200 when the tax id was already registered or on replay; 409 conflict; 422 refusal.

```json
{
  "brand": 1,
  "external_reference": "clientes:fiscal-identity:0198f2c4-7b1e-7c3a-9e2d-5f0a1b2c3d4e",
  "tax_id": "B12345674",
  "tax_id_country": "ES",
  "display_name": "Example Hosting SL",
  "country_code": "ES",
  "vies_validated": false,
  "aliases": [{ "source": "whmcs", "code": "858" }]
}
```

- **`tax_id` and `tax_id_country`:** both or neither (§5.4, §10.3); one without the other, or an explicit null, is a 422. `country_code` is always required: where the customer is established. It may differ from `tax_id_country` (§10.3).
- **`aliases`:** optional, max 10 — the issuer's own customer number goes here (§5.4). An alias already held by another counterparty is not added and comes back in `warnings`; a clash never blocks the registration (§10.2).
- **`confirm_distinct_from`:** optional list of account codes, max 20, used only by the duplicate guard (§5.4, §10.5).
- **Answer (201 created / 200 already registered or replay):**

```json
{
  "account_code": "430001000017",
  "customer_number": 17,
  "origin_brand": 1,
  "created": true,
  "tax_id": "B12345674",
  "tax_id_country": "ES",
  "display_name": "Example Hosting SL",
  "country_code": "ES",
  "warnings": []
}
```

- **On an already registered tax id the answer shows the STORED values** (§5.4): the name may be another spelling, the tax id is in its stored form (§10.3), and `tax_id_country` is null when the counterparty predates its registration without that evidence. The consumer must keep the `account_code` it answers with and send it on every invoice.

### Rounding duty per contribution (§7.2)

openmizan never recalculates VAT. It checks what it receives:

- **Per contribution:** `|round(taxable_base × vat_rate_bp / 10000) − vat_amount| ≤ 1` cent, otherwise `vat_rate.mismatch`.
- **Issuer duty — the contribution must mirror how the invoice computed its VAT (§7.2).** If the invoice rounds per line, send one contribution per line. If it computes on group totals, send one contribution per (account, rate) group. Summing per-line-rounded quotas into one contribution can break the tolerance.
- **Total:** `total_amount = Σ taxable_base + Σ vat_amount`, exactly, otherwise `document.contribution_sum_mismatch`.
- **Domestic rates:** 4, 10 and 21 % only (`vat_rate.unsupported_domestic_rate`). All contributions at 0 → `document.domestic_exempt_not_supported`; zero and taxed together → `document.mixed_vat_treatment_not_supported`. Several rates under one treatment are allowed (for example 21 % and 10 % on the same domestic invoice).

### NIF and tax id rules (§10.3)

The rules are keyed on the country that **issued** the tax id, not on where the customer is established:

- **`tax_id_country` travels beside every NIF**, on the registration and on the invoice: `tax_id` and `tax_id_country` are both or neither (§5.2, §5.4, §10.3). Absence is expressed by OMITTING both fields, never by sending null (§5.2).
- **`tax_id_country`** is ISO 3166-1 alpha-2 of the issuing country (Greece is `GR`, even though its VAT prefix is `EL`). It may differ from `country_code` — a company established in France that holds a Spanish NIF sends `country_code: "FR"`, `tax_id_country: "ES"` (§10.3).
- **Spanish tax ids** (`tax_id_country = ES`): NIF, NIE or CIF — including the `K`, `L`, `M` and `N` forms — must carry a valid control character, otherwise `customer.tax_id_invalid`. Stored **without** the `ES` prefix: `ESB12345674` is accepted and stored as `B12345674`; lookups compare a Spanish tax id in both forms (§10.3).
- **Other EU member state:** `vies_validated = true` required (`customer.tax_id_not_expected`), and the tax id must start with that state's VIES prefix. Stored **with** its VIES prefix, as sent (§10.3).
- **Outside the EU:** refused (`customer.tax_id_not_expected`) (§10.3).
- **A Spanish-established customer (`country_code = ES`) or a `vies_validated` customer always carries a tax id** → `customer.tax_id_required` otherwise (§10.3).
- **No silent corrections:** a stored tax id, `tax_id_country` or country is never changed or filled as a side effect (§10.1).

### The customer duty (§10.2, §10.5; §1 ruling 15)

- **Register each fiscal identity ONCE, before its first invoice**, through `POST /api/v1/customers`. An invoice for a customer that was not registered is refused (`customer.not_registered`); openmizan never registers a customer on the fly while posting an invoice (§1 ruling 15, §10.2).
- **A customer is a fiscal identity** — a name and a NIF/VAT, unique in the company — not the issuer's customer (§1 ruling 10, §10.1). The tax id is the key: a new profile row in larabill with the same NIF is the same fiscal identity, and registering that tax id again — from any brand, any issuer customer or any new profile — answers 200 with the same customer and the same account (`created: false`). No new registration.
- **Save the `account_code` the registration answers with** and send it as `customer.account_code` on every invoice (§10.2). Keep it per fiscal identity, not per issuer customer: one issuer customer can bill as several fiscal identities, each with its own account (§1 ruling 10).
- **`possible_duplicate` (422) carries `details.candidates`** — up to 20 similar customers (§10.5). It is shown to a person, who confirms distinctness by sending `confirm_distinct_from` naming every candidate. An integration never confirms it alone: a yes/no flag would silence the guard for ever (§10.5).
- **Known limitation (§10.3):** a customer registered without a tax id cannot gain one later (`counterparty.tax_id_cannot_be_added`, openmizan ADR-0018 pending). It is registered again with its tax id, which gives it a new account, until the openmizan "add" action exists.

### Recommended flow for a new customer (§1 rulings 15–17, §5.5)

- **On a new customer's first invoice, create the customer first**, using the search while typing: `GET /api/v1/customers?search=…` matches a normalized-name **contains** or a stored tax id **prefix**, company-wide, and answers up to 20 candidates (`account_code`, `display_name`, `tax_id`, `tax_id_country`, `country_code`, `origin_brand`, aliases), so the person sees existing customers before creating a duplicate. The search term needs at least 4 characters after normalization; `%` and `_` are literal (§5.5).
- **An integration checks its own customer number with the exact alias lookup:** `GET /api/v1/customers?alias_source=…&alias_code=…` — exact match, no minimum length, zero or one customer (§5.5). Send the issuer's customer number as an alias at registration (§5.4).
- **This flow applies only when the consumer is configured with openmizan as its accounting program**; otherwise the consumer never calls openmizan (§1 ruling 17).

### Reading the serie state (§5.1)

`GET /api/v1/series/{type}/{code}/status?fiscal_year=YYYY` — the reconciliation read promised above (R2 and R4):

```json
{
  "series": { "type": "invoice_issued", "code": "CAS", "brand": 1, "resets_annually": true },
  "fiscal_year": 2026,
  "last_registered": { "number": 123, "display_number": "CAS-2026-000123", "issue_date": "2026-10-11" },
  "expected_next_number": 124,
  "opening_point": null
}
```

- After a delivery failure the issuer reads where the series stands and resumes at `expected_next_number`. Errors: 404 `series.unknown`; 422 `series.fiscal_year_before_opening` for an annual series' opening year and before (§5.1, §8).

### The Verifactu footprint (§9)

- **`verifactu.huella` is 64 uppercase hexadecimal characters** (SHA-256) — the form the AEAT record carries and lara-verifactu emits; a present but malformed huella is refused with `verifactu.huella_malformed`, obliged or not (§9).
- **Mandatory since activation:** once the company is obliged (`verifactu_obliged_since`, a one-way activation with no deactivation), every document with `issue_date` on or after that date must carry the huella → `verifactu.huella_required`. Documents dated before activation are accepted without one (§9).
- **openmizan stores only the huella** (`accounting_documents.verifactu_reference`). The QR, the AEAT registry number and the AEAT submission status are not stored — they stay in the billing program (§9).

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
