---
title: The currency of an issued invoice — document fix (Step A) and the customer-currency question (Step B)
date: 2026-11-04
status: APPROVED rev 4 for Step A only (owner, 2026-11-04) — the frozen-currency guarantee is limited to issued invoices that carry a currency in their snapshot; A6/TA3 (PDF template datum) withdrawn from Step A by the owner on 2026-11-04 and kept as an explicit limit (the bundled templates print a literal € and never read totals.currency); Step B stays open and separate; VerifactuAdapter stays out until its owner clarifies its contract
base: v9.3.0 (ea8f551)
origin: clientes AID-962 review round 2, finding on CustomerCredit::apply(); external read-only review of v9.3.0, point 3
supersedes: rev 1 of this file (same date). Rev 1 proposed an optional per-call `currency` parameter on apply() and a public `Invoice::collectionCurrency()`. Both are withdrawn: a per-call currency lets the caller change currency operation by operation, which contradicts the owner's rule (§1).
---

# The currency of an issued invoice

## 1. The owner's rule, and how this spec splits it

The business decision is taken and is not reopened here:

- The currency is decided by the **consumer** of the package.
- The consumer may have a default currency for every customer and assign a different one to a specific customer.
- Once assigned to a customer, that currency does not change: the customer's invoices, rectificatives, payments and balance stay in it.
- `clientes`, in its current scope, uses EUR for everyone. It needs no per-operation multi-currency.

This spec has two parts that must not be merged:

- **Step A (§3–§8)** is a technical fix: an issued invoice whose snapshot carries a currency keeps that frozen currency, and a later edit of the issuer's fiscal configuration does not change how its payments, its applied credit or its outstanding are read. It adds no public API and no per-operation currency. Proposed as **9.3.1**.
- **Step B (§9)** is an architecture question: whether larabill should offer a contract to *check* the consumer's customer-currency rule. It is open, it does not block Step A, and it does not block `clientes`, which is EUR.

**What Step A does not do, explicitly:**

- It does **not** impose the consumer's currency policy. larabill still does not know which currency a customer is assigned; Step A neither stores it nor infers it.
- It does **not** let anyone change a customer's currency, nor a document's. It adds no way to pick a currency per call; it removes the path by which an edited configuration silently changed it.
- It does **not** decide what currency *new* invoices are issued in. That stays where it is today (the issuer configuration at issue time), and whether that matches the customer's assigned currency is Step B's question.

## 2. Where the currency lives today (v9.3.0, measured)

- **Customer:** no currency anywhere. Neither `users` nor `UserTaxProfile` carries one.
- **Issuer configuration:** `company_fiscal_configs.currency`, `NOT NULL DEFAULT 'EUR'` (`create_company_fiscal_configs_table`, `:38`), described as the default currency, fillable, no update guard. Temporal only by convention.
- **Document:** `issue()` freezes the currency in `fiscal_snapshot` (`Invoice::generateFiscalContextSnapshot()`, `src/Models/Invoice.php:1749`, key `currency` at `:1764`). `issue()` refuses an invoice with no `company_fiscal_config_id` (`InvoiceService::assertIssuanceCoherence()`, `:875`), so every invoice issued by the current code has a frozen currency. Rows issued before the snapshot existed, or whose snapshot carries no currency, have none: Step A does not give them one (§4 A1, §6).
- **Payment:** chosen by the caller on every call (`PaymentService::receive()`, `GroupedPaymentService::register()`), checked only against the configuration's *current* currency.
- **Credit:** kept per (customer, currency); larabill tolerates several currencies for one customer by design (`inferCurrency()`, `InsufficientCreditException::currencyAmbiguous()`).

Nothing stops one customer's documents, payments and credit from mixing currencies. Step A does not change that (it is Step B's question); it only stops an *issued* document from changing currency after the fact.

## 3. The defect Step A fixes

Every read of an invoice's currency on the collection side goes through the issuer configuration, not through the document:

| Site | What it decides | Read |
|---|---|---|
| `src/Services/CustomerCredit.php:258` + `:366-369` (`fiscalCurrency()`) | which payments `apply()` locks, read before the transaction | `companyFiscalConfig->currency` |
| `src/Services/CustomerCredit.php:270-276` | the currency `apply()` plans in, read under the locks; the config row is **not** locked | `companyFiscalConfig->currency`, else `inferCurrency()` |
| `src/Services/CustomerCredit.php:272` → `Ledger::assertPayable()` | the currency check of `apply()` | compares that same value with itself, so it never fails |
| `src/Services/CustomerCredit.php:138` | which invoices count in a customer's outstanding for a currency | `companyFiscalConfig->currency` |
| `src/Services/Payments/Ledger.php:103` (`assertPayable()`, used by `PaymentService` `:163` and `:304`) | whether a payment's currency matches the invoice | `companyFiscalConfig->currency` |
| `src/Services/GroupedPaymentService.php:161` (`register()`) | same check, legacy path | `companyFiscalConfig->currency` |

Consequence, with no race needed: issue an invoice in EUR, then edit the referenced configuration in place to USD.

- `apply()` locks the customer's USD payments, plans over USD entries and writes USD-funded allocations on an EUR document. `assertPayable()` passes.
- `receive()`/`register()` accept a USD payment on that EUR document and refuse an EUR one.
- The outstanding read counts that EUR document under USD.

If the edit lands between `apply()`'s read before the transaction and its read under the locks, the plan filters one currency over payments locked for another and fails with insufficient credit: closed, but for the wrong reason.

`clientes` validates EUR before calling larabill (auto-apply in `CreditApplicationPolicy::afterIssuance()`, manual apply in `CustomerCreditPanel::applyToInvoice()`), and then calls `apply()` with no currency. After Step A, larabill no longer needs to be told: the document says EUR and nothing an edit does can change that.

Inherited from v8.3.0 (AID-962 phase 1), not introduced by 9.x.

## 4. Step A — contract

**A1. The document is the owner of its currency once issued — when the snapshot carries one.** For an ISSUED invoice whose `fiscal_snapshot` carries a currency, its currency is that frozen value, and a later edit of the referenced `CompanyFiscalConfig` never changes it. **The guarantee is limited to those invoices.** A legacy issued row with no snapshot, or with no currency in it, keeps today's fallback (the referenced configuration, A2): its currency still follows an edit of that configuration, and Step A does not protect it the same way. What Step A adds for those rows is only A3's refusal when the value moves during an `apply()`. The frozen currency is evidence of how the document was issued, not a per-invoice policy: nobody chooses it after the fact, and nothing in Step A lets anyone do so.

**A2. One internal reader.** A new class, `src/Support/DocumentCurrency.php`, marked `@internal` **at class level** (as `Payments\Ledger` is), with one method:

```php
/**
 * The currency an invoice is denominated in, for the issued-document paths.
 *
 * @internal Not part of the supported surface (docs/api-surface.md).
 */
final class DocumentCurrency
{
    /**
     * ISSUED with a frozen snapshot currency → that currency.
     * Otherwise (not yet issued, or a legacy issued row with no snapshot) →
     * the referenced CompanyFiscalConfig, exactly as today; null when none.
     */
    public static function of(Invoice $invoice): ?string
}
```

"Exactly as today" is literal: the fallback returns the configuration value raw, so an empty string stays an empty string (a currency no payment matches), as every collection path compared it in v9.3.0; only an empty *snapshot* currency counts as absent, as `VoucherSaleService` already treated it (adversarial round 1, finding 1). It is the logic of `VoucherSaleService::invoiceCurrency()` (`:493-505`), which then delegates to it. Every site of §3 reads only this method. Per `docs/api-surface.md`, `@internal` "may change in any release without notice", so the class carries no SemVer promise and is not new public API; `SurfaceTaxonomyTest` requires the class-level tag. It lives outside `Invoice` so the `Invoice` contract snapshot (`tests/Contract/snapshots/Invoice.json`) does not change.

**A3. `apply()` decides from the locked row.** Under the transaction, the currency is `DocumentCurrency::of()` on the invoice instance `Ledger::lockInvoices()` returned. For an issued invoice with a snapshot currency the value lives on the locked row and is immutable, so it cannot move during the operation; the configuration row needs no lock. On the legacy fallback it can move, because the configuration row is not locked. The read before the transaction uses the same method. If the two disagree (possible only on the legacy fallback of A2), `apply()` refuses with the existing `GroupedPaymentValidationException::currencyMismatch()` instead of planning over payments chosen for another currency.

**A4. No inference on an issued document.** `inferCurrency()` (the only currency with a positive balance) is reached only when `DocumentCurrency::of()` returns null. For a document issued by the current code that never happens. The D3 path stays, unchanged, for invoices that reference no configuration.

**A5. The checks compare two independent values.** `Ledger::assertPayable()` and `register()` compare the payment's currency with `DocumentCurrency::of()`. Inside `apply()` the comparison is between the planning currency and the document's, which after A3 are the same value by construction; the check stays as the guard against a future regression.

**A6. Withdrawn (owner, 2026-11-04).** Rev 3 proposed that the PDF template data carry the document's currency. Measured during implementation: the bundled templates under `resources/views/pdf/` print a literal `€` and never read `totals.currency`, so changing that datum changes no printed PDF. Step A therefore does not touch `DomPDFService`; the limit is recorded under «Not touched by Step A» below. The number is kept so A7 and the tests keep their names.

**A7. Mechanical guard, scoped to the Step A paths.** An arch test reads the source of exactly these files and fails if any of them reads `companyFiscalConfig` for its currency other than through `DocumentCurrency`:

- `src/Services/CustomerCredit.php`
- `src/Services/Payments/Ledger.php`
- `src/Services/GroupedPaymentService.php`
- `src/Services/PaymentService.php`
- `src/Services/VoucherSaleService.php`

The guard is a closed list on purpose, not a sweep of `src/Services/`. Files that legitimately read the issuer configuration's currency because they build or describe a **new** document stay outside it, named so the exclusion is visible: `AdvanceInvoiceBuilder` (emission side, Step B) and `InvoiceService`/`Invoice::generateFiscalContextSnapshot()` (they are where the currency gets frozen). A new file that reads a document's currency on the collection side is added to the list in the same change. The guard counts every access to the configuration in those files, not only the adjacent `->companyFiscalConfig->currency` form: a relation read (property or method, whatever variable it lands in), `Invoice::getCompanyFiscalSnapshot()` (it returns the live relation, not the frozen snapshot; adversarial round 2) and a static `CompanyFiscalConfig::` call. The one allowed site is `VoucherSaleService::proformaCurrency()`, the pricing currency of a PROFORMA, which is not issued and has nothing frozen. Blind spot, declared: a string-named or dynamic access (`getRelation('companyFiscalConfig')`, `$invoice->{$name}`) is not seen (adversarial round 1, finding 3).

**Not touched by Step A:**

- What currency a new invoice is issued in (the issuer configuration at issue time).
- `AdvanceInvoiceBuilder` (`:117-129`): it compares a credit entry with the *active* configuration to build a *new* draft invoice. That is the emission side, so it belongs to Step B.
- `refund()`: it takes an explicit currency and touches no invoice.
- `VoucherSaleService`: already reads the frozen currency; only its private helper delegates to A2.
- `clientes`: no change, no workaround.
- **The PDF, an explicit limit — not solved by Step A.** `DomPDFService::getInvoiceTotals()` (`:506`) still hands the templates a fixed `'currency' => 'EUR'`, and the bundled templates under `resources/views/pdf/` print a literal `€` without reading `totals.currency`. A printed PDF therefore shows `€` whatever the document's currency, before and after Step A; only a consumer template that reads `totals.currency` sees the datum. Printing the document's currency would change templates consumers may have published, so it is a separate decision for the owner, outside this change.
- **`VerifactuAdapter` (`src/Services/Adapters/VerifactuAdapter.php:76`), classified apart.** It also sends a fixed `'currency' => 'EUR'` to `lara-verifactu`. It is **not** included in Step A on the strength of the literal alone. What was measured on 2026-11-04: `lara-verifactu` stores it on its own `Invoice` model (`$fillable` `currency`, `:82`) and exposes it through `InvoiceContract::getCurrency()` (default `'EUR'`, `:151-153`), but `rg` finds no caller of `getCurrency()` in its `src/`, so the value does not appear to reach the AEAT payload. Whether it is a deliberate convention of the AEAT contract (the registry amounts in euros) or a document-currency datum that should follow the invoice is an open question for `lara-verifactu`'s owner, to be answered against the AEAT specification before any change. Until then it stays as it is, and the A7 guard does not cover it.

## 5. Step A — tests

Sequential and concurrent tests are named as what they are.

**TA1 — sequential, `apply()` after an edit** (`tests/Feature/Payments/IssuedInvoiceCurrencyTest.php`, SQLite + MySQL suites).

- EUR invoice issued; customer holds EUR **and** USD credit; configuration edited in place to USD. `apply()` consumes EUR entries only; every APPLIED exit has `currency = 'EUR'`.
- Same, customer holds USD credit only → `InsufficientCreditException`; no allocation, no exit, collection state unchanged.
- Same shape as auto-apply: the invoice comes from the recurring emission (`RecurringInvoiceGenerated`), and the call is `apply($customerId, $invoice, min(balance, outstanding), $recordedBy)` with no currency, as `clientes` makes it.
- Red on v9.3.0 (the first consumes USD, the second applies USD).

**TA2 — sequential, payments after an edit.**

- Same edit. `receive()` of a USD payment allocated to that EUR invoice → `currencyMismatch`; an EUR payment is accepted. Same for `register()`.
- `CustomerCredit::for($id, 'EUR')` counts the invoice in its outstanding; `for($id, 'USD')` does not.

**TA3 — withdrawn with A6.** No test asserts the PDF template datum; Step A does not change it.

**TA4 — deterministic interleaving of two connections, MySQL gated harness** (`tests/Integration/Mysql/`).

A second connection commits `UPDATE company_fiscal_configs SET currency = 'USD'` between `apply()`'s read before the transaction and its read under the locks, injected with a `DB::listen` hook on the invoice locking read. With Step A the plan is EUR. On v9.3.0 it fails with insufficient credit in USD. This is a real interleaving between two connections, not a sequential test.

A fork test is not proposed: Step A introduces no lock and the invariant does not depend on serialisation, only on the source being the locked, immutable row. TA4 proves that source holds under a concurrent edit. Run on MySQL 8.4 (3384) and MariaDB 11.4 (3307) before pushing.

**TA5 — unit and guard.** `DocumentCurrency::of()` for ISSUED with a snapshot (it wins over an edited configuration), ISSUED with no snapshot (configuration), PREPARED/DRAFT (configuration), no configuration (null). The arch test of A7. Sensitivity: neutralising `of()` back to the configuration read turns TA1, TA2 and TA4 red; the mutation is confirmed with `rg` before reading the result, and restored from a file backup, never with `git checkout`.

## 6. Step A — compatibility

- **No signature changes.** No new `@api` method, no new parameter, no new exception constructor. `DocumentCurrency` is `@internal`.
- **Behaviour change, in bold in the CHANGELOG:** for an issued invoice whose configuration currency was edited after it was issued, `apply()`, `receive()`, `register()` and the outstanding read now use the frozen currency. Before, they used the edited one. This restores what `apply()`'s docblock already promised ("the currency is the invoice's fiscal currency"). The PDF is unchanged (§4, «Not touched by Step A»).
- **Who notices:** only an installation that edited a configuration's currency in place after issuing on it. Detection query for the CHANGELOG, which lists the candidates (the snapshot is encrypted; the comparison is done in PHP):

  ```sql
  SELECT c.id, c.currency, COUNT(i.id) AS issued_invoices
  FROM company_fiscal_configs c
  JOIN invoices i ON i.company_fiscal_config_id = c.id
  WHERE i.invoice_document_status = 'issued'
  GROUP BY c.id, c.currency;
  ```

- **Legacy issued rows with no snapshot currency: not covered by the guarantee.** They keep today's behaviour: their currency is read from the referenced configuration, so an edit of that configuration still changes how their payments, credit and outstanding are read, exactly as in v9.3.0. The only addition is A3: if the configuration changes during an `apply()`, it refuses instead of planning over payments chosen for another currency. The CHANGELOG says this in those words; it does not claim Step A protects them.
- **`clientes`:** EUR configuration never edited, so its observable behaviour is unchanged. The conformance gate before tagging proves it.

## 7. Step A — migrations

None. The frozen currency is already persisted in `fiscal_snapshot`.

## 8. Step A — version

**9.3.1 (patch).** It corrects behaviour and adds no public API: the only new class is `@internal` at class level, and `docs/api-surface.md` excludes that surface from the SemVer promise. No `@api` method, parameter or exception constructor is added, and the contract snapshots do not change.

## 9. Step B — the customer-currency rule (open, separate)

The rule of §1 is decided, and the immutability of a customer's assigned currency is the consumer's responsibility. What is open is an architecture question only: whether larabill should offer a way to **check** it. Step A does not need the answer and does not wait for it.

**What larabill cannot do today, even after Step A:** tell whether a document, a payment or a credit entry is in the customer's assigned currency. It does not know that currency. If the issuer configuration is edited, Step A protects every document already issued with a snapshot currency (not the legacy rows of §6), but new invoices are issued in the new currency, and nothing notices that the customer was assigned another.

**The proposal on the table** (not adopted):

- A contract implemented by the consumer: `CustomerCurrencyResolver::for(string $billableUserId): string`.
- A default implementation that returns one configured default for everyone. For `clientes` that is EUR, with no work on its side.
- larabill would consult it and refuse a mismatch at issue (the snapshot must equal the customer's currency), at `receive()`/`register()`, at `apply()`, at `refund()`, and at `AdvanceInvoiceBuilder`.
- The data and its immutability stay with the consumer; larabill would only check coherence.

**Alternative:** a currency column on the customer. It needs a migration on the consumer's `users` table and adds nothing to the `clientes` case. Not recommended.

**What stays exclusively the consumer's in either case:** which currency each customer has, the default, when it is assigned, and that it does not change. larabill would at most refuse operations that contradict it.

**What Step B would be, if adopted:** new public surface (a contract and its binding), so a minor, with its own spec and gate. It is not part of 9.3.1 and does not condition it.

## 10. Out of scope

AID-1856 (advance-backed balance funding `apply()`/`refund()`), AID-1857 (correction of voucher-sale invoices), AID-1858 (receipt replay) and AID-1859 (squash template) are separate defects and are not touched here.
