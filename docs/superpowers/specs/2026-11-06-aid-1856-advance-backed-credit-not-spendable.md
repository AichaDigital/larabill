---
title: Advance-backed credit is not spendable as a payment (AID-1856)
date: 2026-11-06
status: rev 7 — rev 1 approved by the owner on 2026-11-06, with D1, D2 and D3 of §10 accepted as recommended. Revs 2 to 7 apply rounds 1 to 6 of the spec gate (§12). The design is the one approved; two things were sharpened by the gate: the entry lock is taken one row per statement (§3.2), and one limit of the existing write path is declared (§6.7). The gate's last round passed over the difference rev 6 → rev 7; what the seven rounds cover and what none executed is the last paragraph of §12, and moving to the TDD is the owner's decision on that paragraph. No code has been written; push, MR, merge and tag each wait for the owner's word.
base: v9.3.2 (9e45f29)
origin: AID-1856, owner decision «option A» of 2026-11-06
---

# Advance-backed credit is not spendable as a payment

## 1. The defect, measured

`CustomerCredit::breakdown()` splits the balance into consumable, advance-backed and voucher-backed, and the docblock of `CreditBreakdown` (`src/ValueObjects/CreditBreakdown.php:15-18`, published in 9.1) says of the advance-backed part: «Their VAT was already declared on the advance, so they must not be applied as a payment». The writers do not honour it: the entry selection of `apply()` and `allocate()` filters only the voucher (`notVoucherBacked()`), so an entry linked with `linkAdvanceInvoice()` funds an ordinary payment, and FIFO picks it first when it is the oldest.

Why it matters: an invoice paid with advance-backed balance and carrying no deduction line declares the VAT twice — once on the advance (art. 75.Dos LIVA) and once on the invoice it pays (AID-1815, «Por qué el consumidor no puede rodearlo»).

Measured on v9.3.2 with throwaway probes, deleted after the run. P1–P4 on the SQLite suite; P5, P6 and P7 on MySQL 8.4.10 and MariaDB 11.4.13, same result on both.

| # | Scenario | Result today |
| -- | -- | -- |
| P1 | Entry A 50,00 (older) linked to an advance, entry B 50,00 (newer) unlinked; `apply()` 30,00 to a SENT invoice | The APPLIED exit consumes **A**. `breakdown()`: consumable stays 5000, advance-backed drops 5000 → 2000 |
| P2 | A payment whose only entry is linked to an advance; `allocate()` 30,00 from it | Succeeds. `availableCredit()` reported 5000 before the call; advance-backed drops 5000 → 2000 |
| P3 | The customer holds only advance-backed EUR credit; `apply()` 10,00 to an invoice with no fiscal currency | Succeeds: `inferCurrency()` takes the advance-backed balance as the currency hint and the entry is spent |
| P4 | Same seed as P1; `refund()` 10,00 | The REFUNDED exit consumes **A** (FIFO). **This is the published flow and stays** (§2) |
| P5 | While `apply()` runs, between its plan and the insert of its exit, a second connection links the planned entry to an advance (`UPDATE … SET advance_invoice_id`) | The second connection's UPDATE **commits** (no lock held on the entry row); `apply()` then commits an APPLIED exit on the now linked entry |
| P6 | A transaction holds a locking read of ONE entry row by primary key; a second connection (lock wait 2 s) tries three writes | Linking the locked entry: refused, error 1205. Updating another entry: OK. Inserting a new entry row of the same payment (its id sorts after the locked one): OK |
| P7 | Five entries; a transaction locks the 2nd and the 4th by primary key, once as one `IN` list and once one row per statement; a second connection (lock wait 1 s) inserts rows whose ids sort immediately before and immediately after a locked row, updates the 3rd, and updates a locked row | Same for both shapes: every insert OK, the update of the row in between OK, the update of a locked row refused with 1205 |

P5 is the reason a filter alone is not the fix: under REPEATABLE READ the plan is a plain read, and nothing stops a link from committing after it. P6 and P7 are the evidence for the lock chosen in §3.2: a point lock on the primary key blocks a write of that row and nothing else — no gap before it, none after it.

`clientes` cannot reach the defect today. Measured on a fresh shallow clone of its `origin/main` at `6a34fee1` (2026-11-05), not on its checkout: zero calls to `linkAdvanceInvoice()`, `AdvanceInvoiceBuilder` or `availableCredit()` under `app/`; it reads `breakdown()->advanceBacked` for display (`app/Livewire/Customers/CustomerCreditPanel.php:397`) and calls `apply()`, `refund()` and `allocate()` through its own ledger adapters.

## 2. Owner decision and scope

Decision of 2026-11-06 («option A»), recorded on the ticket:

- **Blocked:** using advance-backed balance as an ordinary payment, through `CustomerCredit::apply()` **and** through `PaymentService::allocate()`.
- **Kept:** `CustomerCredit::refund()` keeps consuming advance-backed entries. It is the flow published in 9.0 — refund of the advance, then its rectificative through `AdvanceInvoiceBuilder::rectificativeLines()` — and excluding them would leave it unreachable.
- **AID-1815** stays the destination of the advance's treatment on later invoices.

An advance and a voucher are not the same thing: paying with the advance is blocked; its published fiscal refund is not removed.

In scope:

| Piece | Where | Change |
| -- | -- | -- |
| Entry selection of `apply()` | `src/Services/CustomerCredit.php:429-445` (`sourcePaymentIds()`), `:487-533` (`plan()`) | Advance-backed entries are never candidates; the decision is taken on the locked row |
| Currency inference of `apply()` | `src/Services/CustomerCredit.php:395-420` (`inferCurrency()`) | Infers over the same locked, spendable set |
| Funding of `allocate()` | `src/Services/PaymentService.php:286-320`, `:597-658` (`consumeEntries()`) | Same selection, same lock; the available-credit check reads the locked set |
| Read face of a payment | `src/Models/GroupedPayment.php:131-146` (`availableCredit()`) | Excludes advance-backed entries (decision D1, §10) |

Not in scope: `refund()` (not one line of its path changes), the targeted deduction on a draft (AID-1815), the unchecked entry writes (AID-1874), and the items of §9.

## 3. Design

### 3.1 One predicate

An entry **can fund a payment** when, on its row: it is active (`reversed_at` NULL), its kind is an entry kind, `sale_invoice_id` is NULL and `advance_invoice_id` is NULL. The first three are today's rule; the fourth is this change.

`CreditMovement` gains the mirror of the voucher pair: `scopeNotAdvanceBacked()` and `isAdvanceBacked()`. The scope is used to narrow the candidate reads; it is never the decision (§3.2).

### 3.2 The decision is taken on locked entry rows

One new helper next to the existing ones, in the `@internal` `Services\Payments\Ledger`:

```php
/**
 * Lock the given credit entries by primary key, id ascending, and return
 * the ones that can fund a payment (§3.1), read from the LOCKED rows.
 *
 * @param  list<string>  $entryIds  candidate ids, in the caller's FIFO order
 * @return Collection<int, CreditMovement> in that same order
 */
public static function lockFundingEntries(array $entryIds): Collection
```

It takes the ids in ascending order and issues **one locking read per id** — `whereKey($id)->lockForUpdate()->first()`, a unique search on the primary key — and applies the predicate in PHP to each row the locking read returns. One row per statement, not one `IN` list, for two reasons: InnoDB documents a unique search that finds its row as locking that index record only, with no gap, whatever the size of the table, whereas a long `IN` list is a range access whose plan is the optimizer's to choose; and a list binds one placeholder per id, which would give `apply()` a ceiling on the number of entries it did not have (§6.8). In P7's arrangement — two ids locked among five rows — the two shapes locked the same; that is one measurement, not a rule for lists. The per-row form is chosen because its behaviour is the documented one and does not depend on the list. The cost is one indexed read per candidate entry, on a path that already issues one `remaining()` query per entry. A locking read returns the latest committed row, not the transaction's snapshot, so a link committed at any moment before the lock is seen, and a link attempted after it waits until the caller commits (P6). Either the entry is excluded, or it is consumed while it is still unlinked and the link lands afterwards on what is left — which is the case `breakdown()` already documents («an entry linked after being partly consumed counts only what is left»).

`linkAdvanceInvoice()` is the only **package** writer that sets `advance_invoice_id` to a value (`src/Models/CreditMovement.php:302-307`). It is not the only writer of the column: the attribute is mass-assignable (`src/Models/CreditMovement.php:68-72`), so a consumer can write it through the model, and the foreign key clears it when the advance is deleted (`nullOnDelete`, `database/migrations/2026_10_24_000001_add_allocations_and_credit_movements.php:264-268`; pinned by `tests/Integration/Mysql/CreditMovementsConstraintsTest.php:68-80`). The design does not depend on who writes it. A write that **sets** the column is an UPDATE of the entry row and waits on the entry lock exactly like the link (P6 measured a plain UPDATE, not the method). A write that **clears** it can only turn an entry spendable; landing after the decision, it leaves credit unused and never over-spends. Every other change to what an entry has left happens under its payment's lock, which the caller already holds.

### 3.3 `CustomerCredit::apply()`

1. Outside the transaction, `sourcePaymentIds()` reads the POSTED payments holding at least one active entry that is neither voucher-backed nor advance-backed. A payment whose only entries are advance-backed is no longer a source payment of an `apply()`.
2. Inside the transaction, unchanged: lock the payments, lock the invoice, the payability checks, the currency-mismatch check.
3. New: the candidate entries of the locked payments (plain read, narrowed by the two scopes; every currency when the invoice has no fiscal currency) are passed to `Ledger::lockFundingEntries()`. This happens **once**, after the payability checks, so a refused invoice locks no entry.
4. The currency inference (invoices with no fiscal currency) and the FIFO plan both read that one locked set. **The candidate rows serve only to obtain ids; every later read and write takes the instances the helper returned** — the inference, the plan, the grouping by payment, the «payment not locked» check, `remaining()`, the band placement and the attributes each exit copies from its entry (`src/Services/CustomerCredit.php:578-590`). Order (`occurred_at`, then `id`), the remainder arithmetic, the grouping by payment and the exits are unchanged.
5. The order of the published checks is unchanged: amount; invoice payable; amount ≤ credit; amount ≤ outstanding.

A payment reversed between the id read and its lock is dropped by the POSTED filter of `lockPayments()` (`src/Services/CustomerCredit.php:465-471`), as today; its entries are then not candidates and the call funds from the rest or refuses.

What the caller sees when the amount exceeds the spendable credit: `InsufficientCreditException::forBalance()` with the spendable sum as «available» — the same exception, the same shape, the same rule the voucher already follows. With no fiscal currency and only advance-backed credit: `noCreditForCustomer`. Advance-backed credit in one currency and consumable credit in another is no longer ambiguous: the consumable one is inferred.

### 3.4 `PaymentService::allocate()`

Unchanged up to and including `assertWithinOutstanding()`. Then: the candidate entries of this payment go through `Ledger::lockFundingEntries()`; the available credit is the sum of what is left of the returned entries; above it, `AllocationExceedsPaymentException::forPayment()` with that sum, as today. `consumeEntries()` no longer re-reads the payment's entries (`src/Services/PaymentService.php:600-606` today): it receives the locked set and funds the allocation from those instances, in the same FIFO order, with the same band placement.

`allocate()` keeps its published caller contract, unchanged and still not enforced (`src/Services/PaymentService.php:102-113`): call it as the outermost transaction, or inside a wrapper that has made no prior read. Everything this spec promises about `allocate()` holds under that contract. Outside it, §6.7.

### 3.5 `GroupedPayment::availableCredit()`

Adds `notAdvanceBacked()`. Its own docblock already gives the rule for the voucher — «it funds no allocation, so it is not available credit of the payment» — and after §3.4 the same holds for an advance-backed entry. It stays a plain read: a snapshot, not a reservation. `allocate()` no longer takes its check from it (it reads the locked set), so the two can only differ when a link commits in between, and then `allocate()` is the one that is right.

### 3.6 `CustomerCredit::refund()`

Untouched, in three senses that are each pinned by a test: **which entries** it takes (voucher excluded, advance included), **in which order** (`occurred_at`, then `id`), and **which locks** it holds (its payments, and no entry row). `refund()` never calls `Ledger::lockFundingEntries()`. The implementation separates the entry selection from the FIFO arithmetic: `apply()` and `refund()` share the arithmetic and each has its own selection — `refund()`'s is today's query, unchanged. T8a pins rows and order; T8b pins that no entry lock is held (§7).

### 3.7 Docblocks that change with the behaviour

`CreditBreakdown` (the advance-backed part is not taken by `apply()` or `allocate()`; `refund()` still takes it), the class docblock of `CustomerCredit` (the lock paragraph gains the entry rows), `apply()`, `allocate()`, `availableCredit()`, and two edits in the docblock of `linkAdvanceInvoice()`: its opening calls the method «the only setter of `advance_invoice_id`» (`src/Models/CreditMovement.php:198-202`), which §3.2 shows is not exact — it becomes «the only setter in the package»; and its concurrency paragraph gains one sentence: a link waits for a running `apply()`/`allocate()` that holds the entry, and the reverse. No test can see a docblock: both edits are an explicit item of the implementation gate.

## 4. Locks

Order inside one transaction, new step in bold:

| Path | Order |
| -- | -- |
| `apply()` | payments (id asc) → invoice → **entry rows (id asc)** → inserts |
| `allocate()` | payment → invoice → **entry rows (id asc)** → inserts |
| `refund()` | payments (id asc) → inserts (unchanged) |
| `linkAdvanceInvoice()` | advance invoice → entry row (its UPDATE) (unchanged) |

No package path takes an explicit **locking read** of `credit_movements` today (measured: `rg lockForUpdate src/`), so the entry rows are a new last step, always after every payment and invoice lock.

Rows of that table are locked by writes. The inventory below is derived, not remembered: every statement under `src/` that builds or writes a `CreditMovement` (`rg -n "CreditMovement::query\(\)->create\(|new CreditMovement|Ledger::createExit\(" src/` for the inserts; a multi-line `rg` for `CreditMovement::query()…->update(`/`->delete(` plus the model's own `static::query()…->update(` for the rest). There is no DELETE.

| Kind | Statement | Runs under |
| -- | -- | -- |
| UPDATE of an existing entry | the link, `src/Models/CreditMovement.php:302-307` | the advance invoice lock only — the one path the new lock has to serialize with |
| UPDATE of existing entries | `PaymentService::reverse()`, `src/Services/PaymentService.php:409` | that payment's lock, then its invoices |
| UPDATE of existing exits | `PaymentService::reverseAllocation()`, `src/Services/PaymentService.php:496` | that payment's lock, then the invoice |
| INSERT of a new entry | SURPLUS of `reverseAllocation()`, `src/Services/PaymentService.php:498` | that payment's lock, then the invoice |
| INSERT of a new entry | `recordRemainder()`, `src/Services/PaymentService.php:672` | the transaction that creates the payment itself; its invoice locks, no earlier payment lock (the payment row is new) |
| INSERT of a new entry | voucher backing, `src/Services/VoucherSaleService.php:326` | payment, then proforma, then invoice |
| INSERT of an exit | `Ledger::createExit()` (`src/Services/Payments/Ledger.php:234`), called from `apply()`, `refund()` (`src/Services/CustomerCredit.php:596`, `:605`) and `allocate()` (`src/Services/PaymentService.php:623`, `:637`) | the caller's payment lock(s); takes a shared foreign-key lock on the entry it consumes |
| INSERT of an exit | CONSUMED, `src/Services/VoucherSaleService.php:712` | the voucher's payment locks; shared foreign-key lock on a voucher entry, which is never a candidate |

What follows from it, class by class. **Updates of existing rows** other than the link, and **inserts of exits**, run under a payment lock that `apply()`/`allocate()` take first, so they are serialized with them before either reaches an entry row. **Inserts of new entries** neither wait for the new step nor make it wait: an uncommitted row is locked only by its creator and cannot be among the ids of a concurrent candidate read, and a point lock by primary key holds no gap (P6, P7). An entry committed after the candidate read is simply not seen in this run — the existing rule «it can leave credit unused, never over-consume it». That leaves the link as the only **package** write that reaches an existing entry without its payment's lock. A consumer's own write of the mass-assignable column is the other one, and §3.2 covers it: setting it waits on the entry lock, clearing it can only under-spend.

Why this adds no lock cycle with the link: a cycle needs `apply()`/`allocate()` to wait for something the link holds while the link waits for an entry they hold. The link holds only its advance invoice. `apply()`/`allocate()` lock exactly one invoice, their target, and lock it **before** any entry row; if the target is the advance itself, both paths queue on that invoice lock first and never hold an entry against each other. Two `apply()`/`allocate()` calls over the same entry are already serialized by the payment lock. This is an argument, not a measurement: T12 runs it under load on both engines, and the existing sentence of `linkAdvanceInvoice()` about a possible deadlock victim on the foreign-key locks is left as written — this change does not claim to remove it.

Cost: one extra locking read by primary key per candidate entry of the call. No gap lock (P6, P7: rows insert on both sides of a locked row while the lock is held).

## 5. Data and upgrade

No migration, no schema change, no backfill. `advance_invoice_id` keeps its meaning.

Rows already written stay valid and behave as they do today: an APPLIED exit that consumed an advance-backed entry under ≤ 9.3 is still counted by `remaining()` and `breakdown()`, and its allocation is still reversible with `reverseAllocation()`, which gives the amount back to the entry. `AdvanceInvoiceBuilder::deductionLine()` still reads it **when its band is resolved**; an exit with `band_start` NULL keeps being refused with `unresolvedEntryPosition` (`src/Services/AdvanceInvoiceBuilder.php:353-356`), as on 9.3 — this change neither resolves nor worsens that history (T10).

The CHANGELOG carries the query a consumer can run to see whether it has such rows. It over-reports on purpose — an entry consumed first and linked later is legitimate and also appears — because the link carries no timestamp:

```sql
SELECT e.id AS entry_id, e.advance_invoice_id, SUM(-x.amount) AS applied_base100
FROM credit_movements e
JOIN credit_movements x ON x.consumes_movement_id = e.id
WHERE e.advance_invoice_id IS NOT NULL
  AND x.kind = 2            -- APPLIED
  AND x.reversed_at IS NULL
GROUP BY e.id, e.advance_invoice_id;
```

Upgrade for a consumer: `composer update aichadigital/larabill`, nothing else.

## 6. Consequences and limits

1. **`deductionLine()` has no package-made exit to read until AID-1815.** `apply()` and `allocate()` are the only package writers of APPLIED exits. After this change neither takes an advance-backed entry, so no package path creates the APPLIED exit `deductionLine()` needs; it keeps working on inherited rows (§5) and stays `@api`. This is not a regression of a working flow: the 9.0 order «apply → compute the line → put it on the invoice» is circular (`apply()` needs an issued invoice, the line must enter before issue — AID-1815), and the only end-to-end run ever made built its exit by hand (`docs/specs/aid-1778-advance-invoice-partial-card.md:233`). But it must be said: between this release and AID-1815, advance-backed balance leaves the ledger only through `refund()`. Decision D3 (§10).
2. **`refund()` is FIFO and cannot be aimed at one entry.** A refund meant for consumable credit takes the advance-backed entry if it is older, and the reverse. Unchanged, published, out of scope.
3. **A link during the call can leave credit unused, never over-spend it.** An entry linked between the candidate read and the lock is excluded; the call then refuses or funds from the rest.
4. **`balance()` and `position()` keep counting everything.** Only `breakdown()->consumable` says what `apply()` can take; a consumer that caps an amount with `balance()` gets `InsufficientCreditException`. Same as the voucher since 9.2.
5. **The messages of `InsufficientCreditException` keep their text.** «holds no credit in any currency» is imprecise for a customer who holds only advance-backed credit; the voucher has the same imprecision and no message is touched here.
6. **`nullOnDelete` on the foreign key:** if the consumer deletes a DRAFT advance, the entry becomes unlinked and spendable again. That is the correct reading of the data, not a hole.
7. **`allocate()` inside a caller's transaction that has already read: existing defect of the write path, not cured here.** The entry lock makes the *selection* current; what is left of each entry is still computed by plain reads (`remaining()`, `src/Models/CreditMovement.php:147-159`; `ExitBands::segmentsFor()`), which under REPEATABLE READ come from the caller's snapshot. Measured on v9.3.2, MySQL 8.4.10 and MariaDB 11.4.13, same result: a caller opens a transaction and makes one plain read; a second connection applies 60,00 from an entry of 100,00 and commits; the caller then allocates 80,00 from that entry — it succeeds, and the entry ends with 140,00 of active exits on overlapping bands `[0, 6000)` and `[0, 8000)`. This is the published snapshot rule of `allocate()` and `receive()` («every sum here would read stale rows», `PaymentService.php:102-105`), it predates this change, and this change neither causes nor worsens it: `apply()` is immune because its outermost guard runs before any read (`CustomerCredit.php:251`). The invariants of T11–T14 are therefore stated under the caller contract. Enforcing that contract is out of scope (§9) and gets its own ticket. `clientes` at `6a34fee1` has no caller of `allocate()` under `app/` (its adapter exposes the method; nothing calls it).
8. **Size of the entry lock: no new ceiling.** The lock is taken one row per statement (§3.2), so it binds one id at a time and adds no limit on how many entries a customer or a payment may hold. The candidate read keeps today's shape — entries filtered by the ids of the locked payments, the same `whereIn` the plan already issues (`src/Services/CustomerCredit.php:495`) — and so keeps today's ceiling, which is on payments, not on entries.

## 7. Tests

Two kinds, and the table says which is which. **Red first (R):** fails on v9.3.2 for the defect, passes after the fix. **Baseline guard (G):** passes on v9.3.2 and must still pass after the fix — it pins behaviour that must not move, so it is written and seen green **before** the fix. Feature tests on the SQLite suite; the transactional and concurrent ones on **MySQL 8.4 and MariaDB 11.4**, both, zero skipped.

| # | Kind | Test | Asserts |
| -- | -- | -- | -- |
| T1 | R | `apply()` with an older advance-backed entry and a newer consumable one (P1) | the exit consumes the consumable entry; advance-backed unchanged |
| T2 | R | `apply()` with only advance-backed credit | `InsufficientCreditException` (available 0); no allocation, no exit, invoice state unchanged |
| T3 | R | `apply()` of an amount ≤ `balance()` but above the consumable part | refused; nothing written |
| T4 | R | `allocate()` from a payment whose only entry is advance-backed (P2) | `AllocationExceedsPaymentException` (available 0); nothing written |
| T5 | R | `allocate()` from a payment with one linked entry and one unlinked SURPLUS | funds only from the unlinked one; above it, refused |
| T6 | R | No fiscal currency (P3): only advance-backed EUR → `noCreditForCustomer`; advance-backed EUR + consumable USD → applies in USD | the advance is not a currency hint |
| T7 | R | `availableCredit()` | excludes what is left of advance-backed entries; equals what `allocate()` accepts |
| T8a | G | `refund()` across three entries of two payments — linked (oldest), unlinked, linked (newest) — for an amount that reaches the third | the exits consume in (`occurred_at`, `id`) order whatever the link, with the exact rows v9.3.2 writes; `rectificativeLines()` builds its lines from the exits on the linked entries against an issued advance |
| T8b | G | Engine, two connections: while `refund()` is between its plan and the insert of its first REFUNDED exit, the real `linkAdvanceInvoice()` runs on a second connection (hook as in T12) | the link **commits** and `refund()` then commits its exit on the entry, as on v9.3.2; **and** the query trace of the whole `refund()` call on the primary connection (recorded with `DB::listen()`) holds, for `credit_movements`, only plain SELECTs and INSERTs — no locking read (`for update`, `for share`, `lock in share mode`) and no UPDATE |
| T9 | R | Entry partly applied, then linked | the earlier exit and allocation stand; what is left is not spendable; `reverseAllocation()` of the earlier allocation gives it back to the entry |
| T10 | G | Inherited rows, seeded as ≤ 9.3 wrote them: an APPLIED exit on a linked entry with a resolved band, and one with `band_start` NULL | resolved: `deductionLine()` reads it and `reverseAllocation()` reverses it; NULL band: `deductionLine()` refuses with `unresolvedEntryPosition`, as today |
| T11 | R | Invariant over sequences of `apply()`/`allocate()` on mixed entries | `breakdown()->advanceBacked` never decreases; consumable + advance-backed + voucher-backed = `balance()` |
| T12 | R | Engine, two connections, once for `apply()` and once for `allocate()`: a link attempted while the call holds the entry (P5 inverted) | see «T12» below |
| T13 | R | Engine, two connections, once for `apply()` and once for `allocate()`: a link **committed** after the candidate read and before the entry lock | see «T13» below |
| T14 | R | Engine, forked (`tests/Concurrency`, `RUN_CONCURRENCY_IT=1`): appliers and linkers over the same entries | see «T14» below |
| T15 | R | Engine: the `allocate()` case of T13 run inside a caller's transaction that has made **no** prior read — the context its contract tolerates | same assertions as T13: the locked-row decision holds inside the tolerated wrapper |

**T12 — the link waits for the call.** A `CreditMovement::creating` listener fires on the first APPLIED exit of the call; at that point the planned entry is already locked. Inside the listener the **real** `linkAdvanceInvoice()` runs on a second connection (a copy of the test connection, made the default connection for the duration of the hook and restored in `finally`; `innodb_lock_wait_timeout = 1` on its session). From the same hook and the same second connection, three more writes pin that the lock is a point lock: an insert of an entry row whose id sorts immediately before the locked entry, one immediately after it, and an update of an entry the call does not hold — all three succeed (P7). The rows used belong to **another customer**: every candidate entry of the call is locked, so another entry of the same customer and currency is not free, and a row inserted for a payment the call holds would wait on that payment's lock through its foreign key, not on the entry lock. Asserts: the hook fired exactly once; the link was refused by the lock wait timeout (error 1205) and nothing else; the three other writes succeeded; after the call the entry is unlinked, the call committed, and its exit stands. On v9.3.2 the same hook lets the link commit (P5) — that is the red.

**T13 — the decision comes from the locked row.** A `DB::listen()` hook on the primary connection (the repository's own pattern for deterministic interleavings, `tests/Integration/Mysql/ReplayBackstopRaceTest.php:72-94`) records every query of the call and, on one chosen plain SELECT of entry rows of `credit_movements` issued **inside** the transaction (after the first locking read of `grouped_payments`), runs the real `linkAdvanceInvoice()` on the second connection before returning; the link commits. Which SELECT is chosen is a parameter of the test (its ordinal among those SELECTs), because the test must not assume the implementation's query shape. What makes the choice verifiable is an assertion on the trace, checked after the call: **the locking read of `credit_movements` observed after the hook binds the linked entry's id.** That can only be true if the ids handed to the lock were read before the link landed — if the hook fires on an earlier SELECT (the pre-transaction read of source payments, or any read that precedes the real candidate read), a narrowed candidate read no longer returns the entry, its id is not in the lock, and the test fails as an invalid interleaving instead of passing for the wrong reason. Asserts, all of them: the hook fired exactly once; the link is committed (read on the second connection); a locking read of `credit_movements` follows the hook and binds the entry's id; no APPLIED exit exists on the linked entry — with one entry the call is refused for lack of credit, with a second unlinked entry it funds from that one. Run for `apply()` with a fiscal currency, `apply()` with none (where today an inference read precedes the plan) and `allocate()`. The link lands after the call's snapshot, so only the locked-row predicate can exclude the entry: an implementation that applies the predicate to the candidate rows passes T1–T11 and fails here; one that takes no locking read fails the trace assertion. The trace also pins the shape of §3.2: every locking read of `credit_movements` in the call binds exactly one id.

**T14 — under load.** N applier processes and M linker processes over a shared set of entries, no refunds. Each linker, right after its link commits, reads what is left of its entry on a fresh autocommit read and appends `{entry_id, remaining}` to a results file. After every child has joined, the parent asserts, for each record, that what is left of that entry **now** equals the recorded value: with the entry lock, an applier either committed its exit before the link (already inside the recorded value) or excluded the entry afterwards. It also asserts that no exit sums past its entry and that every child ended by committing or by a clean refusal (insufficient credit, already linked, deadlock victim, lock wait timeout) — nothing half-written.

The mechanics of T8b, T12 and T13 were probed on v9.3.2 before this revision, on MySQL 8.4.10 and MariaDB 11.4.13, with throwaway tests deleted after the run: a `CreditMovement::creating` hook on the first exit of `apply()` and of `refund()` fires once, the real `linkAdvanceInvoice()` runs and commits on the second connection, and the default connection is restored; a `DB::listen()` hook on the plan SELECT of `apply()` fires once and does the same. On v9.3.2 the link commits in all three and `apply()` then commits its exit on the linked entry — the red of T12 and T13, and the green of T8b.

No test pins the sequence of §6.7: it is a declared limit of existing behaviour outside the caller contract, and a test asserting an over-consumed entry would guard a defect, not a guarantee.

Sensitivity, proven and written down per engine: T12 turns red with the entry lock removed; T13 turns red with the predicate moved back to the candidate rows; T1–T7, T9 and T11 turn red when nothing excludes advance-backed entries — the locked-row predicate, the scope of the candidate read and, for `apply()`, the narrowing of its source payments all removed. Removing a narrowing alone changes no test, because a narrowing only reduces what is locked and the predicate on the locked row is the decision (measured figures in §13); T8b turns red if `refund()` takes a locking read of any entry at any point of the call, before or after its exits. T14's red is probabilistic: it is run with the entry lock removed and the result is recorded per engine — if an engine cannot be made to fail, that is written down and T14 counts there as evidence of consistency and of no deadlock storm only, with T12 and T13 as the proof of the mechanism. Its fork count carries its measured floor and its ceiling against `innodb_lock_wait_timeout`, and the job fails if the test reports skipped.

Before any push: `tests/Concurrency` and `tests/Integration/{Mysql,InstallMysql,UpgradePath}` on both engines, the full SQLite suite, PHPStan level 8, Pint twice.

## 8. Version

Proposed: **9.4.0**, minor, `### Fixed`, «Ships migrations: no».

- It restores semantics the package publishes since 9.1 (`CreditBreakdown`), and STABILITY rule 5 puts a fix that changes observable behaviour under **Fixed** with the old and the new behaviour stated. The entry states old and new for each of the three observable changes: `apply()` and `allocate()` — calls that 9.3 accepted and that spent advance-backed credit are now refused; `GroupedPayment::availableCredit()` — 9.3 counted what was left of advance-backed entries, 9.4 excludes it; currency inference of `apply()` on an invoice with no fiscal currency — 9.3 took advance-backed balance as a hint, 9.4 does not.
- It adds public surface (`scopeNotAdvanceBacked()`, `isAdvanceBacked()` on an `@api` model), which by itself makes it a minor.
- Precedents of a refusal introduced in a minor: 6.9.0 (`MissingFiscalSeriesException`), 6.11.0 (`ReverseChargeWithTaxException`), 6.12.0 (`InvalidInvoiceStatusException`).

The other reading exists and is the owner's call (D2): 9.0 treated the narrowing of `linkAdvanceInvoice()` as a qualified exception inside a major. The difference argued here: that one narrowed what a method accepts by contract; this one stops a write the package's own published docblock says must not happen and that declares VAT twice.

`CreditMovement` and `GroupedPayment` are not among the contract snapshots (`tests/Contract/snapshots/`), so no snapshot regenerates. `docs/api-surface.md` is updated where it describes `CustomerCredit`.

## 9. Out of scope

- `refund()` in any form, including aiming a refund at one entry.
- The targeted deduction of an advance on a draft invoice: AID-1815.
- The unchecked entry writes and the invoice collection-state save: AID-1874.
- `PaymentService::reverse()` of a payment whose entry is linked to an advance.
- Any amount check between an advance and what is left of its entry at link time.
- Enforcing the caller contract of `allocate()` and `receive()`, or making their sums current inside a caller's transaction (§6.7).
- The exception messages (§6.5).

## 10. Decisions for the owner

**Owner decision (2026-11-06):** the spec is approved with the three recommendations below accepted as written.

| # | Decision | Recommendation |
| -- | -- | -- |
| D1 | `GroupedPayment::availableCredit()` also excludes advance-backed entries (§3.5) | Yes. It is the read face of `allocate()`; leaving it would advertise credit `allocate()` refuses. `clientes` does not call it |
| D2 | Version (§8) | 9.4.0, minor, under Fixed |
| D3 | Consequence 1 of §6: until AID-1815 ships, advance-backed balance leaves only through `refund()` | Accept it and say it in the CHANGELOG; AID-1815 is already the agreed destination |

## 11. Process

1. Owner review of this spec. No code before it.
2. Spec gate, adversarial and read-only, before the TDD; every finding measured against the tree and adjudicated here.
3. TDD in the order of §7, on a branch of AID-1856.
4. Implementation gate before the MR is merged.
5. Conformance against `clientes` on the exact candidate, in a disposable workspace of its `origin/main` — never its checkout. Known limit, stated in advance: `clientes` links no entry to an advance, so that run proves non-regression only; the new behaviour is proven by this package's own tests on both engines.
6. Push, MR, merge and tag each wait for the owner's word.

## 12. Spec gate

### Round 1 — rev 1, full and unbounded, two samples of one prompt

- **Scope:** rev 1 of this spec over the tree at `9e45f29` (v9.3.2), read-only. Reviewer: Codex (`gpt-5.6-terra`, `sandbox: read-only`), sessions `01a1b1b9-6847-7d00-b34f-f8a0c30de690` and `01a1b1b9-6847-7a12-8bb9-51dddac55871`. Prompt, verdicts and traces: `~/.gstack/projects/larabill/gates/AID-1856/spec-round-1/` and `spec-round-1b/`.
- **Method verdict, both samples:** real with conditions. Both enumerated the interleavings of the link against `apply()`/`allocate()` and found none that commits an APPLIED exit after a link the decision could see; neither found a lock cycle.
- **Gate verdict, both samples:** FAIL, on precision of claims and on test design. No P1. Union below; every locator was opened before deciding.

| # | Finding (sample) | Decision | Landed in |
| -- | -- | -- | -- |
| 1 | P2 — `linkAdvanceInvoice()` is not the only writer of `advance_invoice_id`: the attribute is mass-assignable and the foreign key clears it (both) | Accepted. Confirmed at `CreditMovement.php:68-72` and migration `:264-268` | §3.2, rewritten: only package setter; a set waits on the entry lock, a clear can only under-spend |
| 2 | P2 — «no package path locks `credit_movements` rows» is false: `reverse()` and `reverseAllocation()` update rows in bulk (1b) | Accepted. Confirmed at `PaymentService.php:409`, `:496` | §4, claim narrowed to locking reads and the table of row-locking writes added |
| 3 | P2 — inherited-row support overstated: `deductionLine()` refuses an exit with a NULL band (1b) | Accepted. Confirmed at `AdvanceInvoiceBuilder.php:353-356` | §5 and T10 |
| 4 | P2 — T12, T13 and T14 are not reproducible designs: no trigger, no trace, no checkable clock edge (both) | Accepted | §7, the three paragraphs «T12», «T13», «T14»; the hook mechanics probed on both engines before writing them |
| 5 | P2 — T8 can stay green while `refund()` changes its order or starts locking entries (both) | Accepted | §3.6; T8 split into T8a (three entries, order and rows) and T8b (the link commits during a refund) |
| 6 | P2 — the CHANGELOG contract omits the old/new of `availableCredit()` (both) | Accepted. `GroupedPayment` is `@api` (`src/Models/GroupedPayment.php:41`) | §8 — and the currency inference added for the same reason |
| 7 | P3 — «red first» is wrong for the tests that pin retained behaviour (1) | Accepted | §7, kinds R and G |

Confirmed by the samples and left as written: the two package writers of APPLIED exits (§6.1), the SQL of §5 and `kind = 2`, the absence of contract snapshots for the two models and the three precedents of §8.

**What round 1 covers:** the design of §3–§4 read against the code, by two samples that agree on the method. **What it does not:** neither sample ran anything against MySQL or MariaDB — the engine facts are the author's P5 and P6, and T12–T14 are what will make them durable; no sample examined a consumer other than by the spec's own statement about `clientes`.

### Round 2 — bounded to the difference rev 1 → rev 2

- **Scope:** `diff` of rev 1 against rev 2 and the consequences of the seven corrections; tree unchanged at `9e45f29`. Session `01a1b1c3-c8f2-75c0-9711-4bb41c0896c0`, read-only, 7 minutes. Artifacts: `~/.gstack/projects/larabill/gates/AID-1856/spec-round-2/`.
- **Method:** unchanged — rev 2 changed precision and test instrumentation only. The reviewer confirmed from `vendor/` that switching the default connection routes the real linker to the second connection, that T12's refusal can only come from the entry lock in that fixture, that T14's recorded value is final, and that the clear-only statement of §3.2 holds.
- **Verdict:** FAIL. Part A: four corrections land, three partially. Four findings, each opened against the tree.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 | The table of row-locking writes of §4 omits three inserts of new entries, so «every row but the first» does not follow | Accepted. Confirmed at `PaymentService.php:498`, `:672`, `VoucherSaleService.php:326`. The table was a remembered list; it is now derived from a stated search and reasoned by class | §4 |
| 2 | T13 can pass for the wrong reason: a hook on an earlier SELECT links the entry before a broken implementation's candidate read, which then excludes it | Accepted | §7 «T13»: the trigger is a parameter and the trace must show the locking read binding the linked entry's id |
| 3 | T8b only proves no entry lock before the first exit; a lock taken after it stays green | Accepted | §7 T8b: the trace of the whole call admits no locking read and no UPDATE on `credit_movements` |
| 4 | The docblock of `linkAdvanceInvoice()` still says «the only setter», which §3.2 now contradicts | Accepted. Confirmed at `CreditMovement.php:198-202` | §3.7 |

**What round 2 covers:** whether the seven corrections of round 1 landed, and what they implied. It did not re-read the untouched design.

### Round 3 — bounded to the difference rev 2 → rev 3

- **Scope:** `diff` of rev 2 against rev 3; tree unchanged at `9e45f29`. Session `01a1b1cb-ab22-77f1-ac9c-842a35dabafb`, read-only, 5 minutes. Artifacts: `~/.gstack/projects/larabill/gates/AID-1856/spec-round-3/`.
- **Method:** unchanged. The two trace assertions were judged real from `vendor/` and the tree: `QueryExecuted` exposes the bindings and is logged after the SELECT ran, so T13's assertion is checkable; `refund()` on v9.3.2 issues only plain SELECTs and INSERTs on `credit_movements`, so T8b is a true baseline guard.
- **Verdict:** FAIL, on one sentence. Part A: three corrections land, one partially. The reviewer's own search confirmed the inventory of §4 complete for package writers.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 | The conclusion of §4 says «the only write» where a consumer's direct write of the fillable column also reaches an entry without a payment lock | Accepted; a precision defect, §3.2 already said it | §4, last sentence of the class-by-class paragraph |

**What round 3 covers:** whether the four corrections of round 2 landed. Trajectory of the bounded phase: 7 → 4 → 1 findings, none against the design, the method unchanged in all three.

### Round 4 — unbounded, on the write path after the selection

- **Scope:** rev 4 read in full; Part B not bounded to any diff — what `apply()` and `allocate()` do with the entries once the selection has let them through (instance of each read and write, rows and columns written, state fixed before the locks, wrong-debit sequences, size of the lock). Session `01a1b1d1-676c-7510-a262-be87fb6e3a97`, read-only, 6 minutes. Artifacts: `~/.gstack/projects/larabill/gates/AID-1856/spec-round-4/`.
- **Method:** the selection stays sound. The reviewer's objection is to the reach of the promise, not to the mechanism: «after any `apply()` or `allocate()`» is unconditional, and `allocate()` tolerates a caller transaction whose snapshot is already pinned.
- **Verdict:** FAIL. For `apply()`: no wrong-debit sequence found, protected by its outermost guard. For `allocate()`: one suspected sequence, which the author then ran.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 | The spec must say that the locked instances flow through every later read and write; today `consumeEntries()` re-reads the payment's entries | Accepted. Confirmed at `PaymentService.php:600-606` | §3.3 step 4, §3.4 |
| 2 | `apply()`: a payment reversed after the id read is dropped by the POSTED filter — undeclared | Accepted; existing behaviour, now stated | §3.3 |
| 3 | SUSPECTED → **measured true** on MySQL 8.4.10 and MariaDB 11.4.13, on v9.3.2: `allocate()` inside a caller transaction with a prior plain read computes what is left from a stale snapshot and over-consumes the entry (100,00 entry, 140,00 of exits, overlapping bands) | Accepted as a limit, not as part of this fix: it is the published, unenforced caller contract of `allocate()`/`receive()`, it predates the change and the change does not worsen it. The promise is restated under that contract. Enforcing it is its own ticket | §3.4, §6.7, §9, the note before the sensitivity paragraph; T15 for the tolerated wrapper |
| 4 | No chunking of the id list; ceiling of placeholders; plan of a large `IN` | Accepted as a declared limit: same statement shape and ceiling as the existing locking read of the source payments | §6.8 |

Confirmed and left as written: the fix of rev 4; `apply()`'s immunity through its guard; that excluding entries changes no invariant of the allocation and exit rows (one allocation per payment spent, each fully funded by exits of its own payment's entries; bands preserved).

### Round 5 — identical repeat of round 4, over rev 5; union taken

- **Scope:** the prompt of round 4 with its revision references changed, over rev 5. Session `01a1b1d9-d4f6-78e1-9d0b-119453924a86`, read-only, 6 minutes. Artifacts: `~/.gstack/projects/larabill/gates/AID-1856/spec-round-5/`.
- **Method:** sound under the declared caller contract; «no post-selection debit gap remains». It agrees with round 4 on every point of the write path and found no further wrong-debit sequence for `apply()` or `allocate()`.
- **Verdict:** FAIL, on the size of the lock — the one thing round 4 had only half-seen.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 | VERIFIED — «same ceiling as the payment lock» (§6.8 of rev 5) is false: that lock binds payment ids, the helper binds entry ids, and one payment can hold many entries; `allocate()` binds no id list at all today. A list of ids is a new ceiling (65,535 placeholders on MySQL's prepared protocol) | Accepted. The claim was wrong | §3.2: one locking read per id; §6.8 rewritten: no new ceiling |
| 2 | SUSPECTED — P6 locked ONE id; it does not establish that a multi-id `IN (…) FOR UPDATE` is gap-free | Accepted and measured: P7, both engines, both shapes, inserts on both sides of locked rows. Gap-free in that two-id arrangement, which is all it measures; for any other list the plan is the optimizer's, so the design no longer uses a list | §1 P7, §3.2, T12 (the three writes that must succeed), T13 (one id per locking read) |
| 3 | SUSPECTED — MariaDB's own placeholder threshold is unverified | Moot: no id list is bound | — |

**Union of rounds 4 and 5:** both judged the selection and the debit that follows it sound for `apply()`; both placed the only wrong-debit sequence in `allocate()` outside its published caller contract (measured by the author, §6.7); round 4 alone asked for the locked instances to flow through the arithmetic and for the reversed-payment note; round 5 alone falsified the ceiling claim and asked for the multi-row gap probe.

### Round 6 — bounded to the difference rev 5 → rev 6

- **Scope:** `diff` of rev 5 against rev 6; tree unchanged at `9e45f29`. Session `01a1b1e1-bcba-7371-8c4b-dd349d819843`, read-only, 3 minutes. Artifacts: `~/.gstack/projects/larabill/gates/AID-1856/spec-round-6/`.
- **Method:** the per-row lock is a narrowing, not a change. A link that commits on entry B after the call locked A is seen by B's own locking read and B is excluded; no cycle between entries is reachable, because the paths that share an entry already serialize on its payment lock and the linker updates one entry only. Read from `vendor/`: `whereKey($id)` with a scalar emits `id = ?`, `first()` adds `LIMIT 1`, the lock clause follows; the primary key is the UUID `id`.
- **Verdict:** FAIL, on one generalization. Part A: the ceiling removal and the cost statement land; the measurement lands partially.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 | «For short lists the two shapes lock the same» says more than P7's two-id measurement supports | Accepted. The design does not rest on it | §3.2 and the round 5 record, both reworded to what P7 measured |

### Round 7 — bounded to the difference rev 6 → rev 7

- **Scope:** `diff` of rev 6 against rev 7 (two reworded sentences and the record of round 6). Session `01a1b1e4-b478-7232-a751-57037e929ca6`, read-only, 2 minutes. Artifacts: `~/.gstack/projects/larabill/gates/AID-1856/spec-round-7/`.
- **Verdict:** PASS over that difference. The correction lands in both places; no other passage states P7 more broadly than it measured; the record of round 6 matches its verdict. Part B empty.

### What the gate covers, and what it does not

Seven rounds, eight reviewer sessions, all Codex in a read-only sandbox over the tree at `9e45f29`; 19 findings accepted (7, 4, 1, 4, 2 and 1 by round), one more made moot by a correction, none discarded, none against the approved design.

- **How the design decides** (the selection, the entry lock, the interleavings with the link, the lock order): round 1, full and unbounded, two samples; rounds 2 and 3, bounded to the corrections.
- **What the write path does once the selection has let entries through** (instances, rows written, state fixed before the locks, wrong-debit sequences, size of the lock): round 4, unbounded, and round 5, its identical repeat; their union is in the round 5 record.
- **Differences of corrections only:** rounds 6 and 7. The PASS of round 7 is a pass of the difference rev 6 → rev 7, not of the document.

What no round executed:

- **Nothing on MySQL or MariaDB.** The reviewer was told not to touch them. Every engine fact in this spec — P5, P6, P7, the hook mechanics of §7, the sequence of §6.7 — was measured by the author with throwaway probes on MySQL 8.4.10 and MariaDB 11.4.13, deleted after each run. They become durable only as T8b, T12, T13, T14 and T15.
- **No code.** There is no implementation; every statement about it is a statement about a design. The implementation gate of §11 is a separate gate and is not replaced by this one.
- **No load.** T14 exists as a design; no forked run has been made, and the argument of §4 that the entry lock adds no cycle is still an argument.
- **No consumer run.** `clientes` was searched at `6a34fee1` on shallow clones; its suite was not run against anything. That is step 5 of §11.
- **No mutation run** and no production data.

## 13. Implementation notes

Written during the TDD, after the spec gate closed. They are corrections of this document against what the implementation showed, not changes of design; the implementation gate of §11 reads them with the code.

1. **T12, which rows are free** (§7 «T12», corrected above). The first draft of the test updated another entry of the same customer and inserted neighbours copied from the locked entry. Both waited — on locks the design takes on purpose (every candidate entry; the payment row, through the foreign key of the inserted row), not on a gap. The test uses another customer's rows.
2. **The scope is a narrowing, not the decision** (§7, sensitivity paragraph, corrected above). With the scope removed from the candidate reads and the locked-row predicate in place, every test stays green: more rows are locked, the same ones are excluded. The same holds for the narrowing of the source payments of `apply()` (§3.3 step 1): it changes how many payments are locked, not what the call does.
3. **`Ledger::lockFundingEntries()` returns the entries in the order the ids were given** (the caller's FIFO order), not keyed by id; it sorts the ids itself to lock in ascending order. The sketch of §3.2 is corrected.
4. **One existing proof changed: AID-1778 R20** (`tests/Unit/Services/AdvanceProRataTest.php`, «telescopes over mixed APPLIED/REFUNDED live exits and after a reversal + refill»). It wrote its APPLIED exits with `allocate()` on an entry already linked to an advance — the path this change closes, consequence 1 of §6. It now writes them before the link, on a second entry, and refills the hole left by the reversal with a refund, which is what a linked entry still takes. Same real writers, same bands, same telescoping figures; it also asserts that `allocate()` refuses on the linked entry.
5. **The FIFO tie-break is the engine's order of ids, and the tests must ask for it.** Two entries of one payment share `occurred_at`, so `id` breaks the tie. Ids are generated with `Str::orderedUuid()` and the column is a native UUID on MariaDB, which does not sort them as strings: which of the two entries comes first differs between engines and between runs. The first version of T13 assumed the linked entry was the head of the FIFO; when MariaDB put the other one first, a broken implementation funded from the free entry and the case passed without exercising anything (seen as 6 and then 7 of 8 cases red under the same mutation). T13 and T8a now read the order from the engine and link accordingly. This is existing behaviour of the FIFO, not something this change touches: the order is stable within an engine, it is simply not creation order.
6. **Sensitivity, as measured** (2026-11-06; each mutation applied to `src/`, checked to have applied, the tests run, `src/` restored from the commit):

| Mutation | SQLite feature tests (17) | Engine tests (11), MySQL 8.4.10 | Engine tests (11), MariaDB 11.4.13 |
| -- | -- | -- | -- |
| None | 17 green | 11 green | 11 green |
| Entry lock replaced by a plain read | 17 green (SQLite has no row locks) | T12 ×2 and T13/T15 ×8 red | same |
| Predicate applied to the candidate rows (the lock still taken) | 17 green | T13/T15 ×8 red | same |
| Predicate applied to a plain re-read after the lock | 17 green | T13/T15 ×8 red | same |
| Predicate no longer checks the advance (scope kept) | 17 green | T13/T15 ×8 red | not run |
| `refund()` selects its entries with a locking read | 17 green | T8b red | same |
| `availableCredit()` counts advance-backed entries | T7 red | 11 green | not run |
| Scope removed from the candidate reads only | 17 green | 11 green | not run |
| Source payments of `apply()` not narrowed only | 17 green | 11 green | not run |
| Predicate, scope and source-payment narrowing all removed | 14 red (T1–T7, T9, T11); the 3 guards green | not run | not run |

The guards T8a and T10 pass against the `src/` of v9.3.2 (`9e45f29`).

7. **T14, measured.** With the entry lock: green on MySQL 8.4.10 (3 of 3) and MariaDB 11.4.13 (3 of 3, plus the full harness run). With the lock replaced by a plain read: the invariant breaks on every round — MySQL, 6 of 6 at each of 1+1, 2+1, 3+2 and 4+3 processes; MariaDB, 4 of 4 at 1+1 and at 4+3 — always as «1000 at link, 900 now». So T14's red is not probabilistic in practice, and the caveat of §7 did not materialize. Floor: one applier and one linker. Ceiling: one `apply()` over the fixture costs 16.6 ms on MySQL and 23.2 ms on MariaDB against a lock wait timeout of 50 s; the test ships with 4 appliers and 3 linkers. No deadlock victim and no lock wait timeout was observed in any green run.
8. **Gated harnesses with the change** (`tests/Concurrency`, `tests/Integration/{Mysql,InstallMysql,UpgradePath}`, `RUN_CONCURRENCY_IT=1`): MySQL 8.4.10, 149 passed; MariaDB 11.4.13, 150 passed (that run already included T14); zero skipped on both.

## 14. Implementation gate

### Round 1 — `git diff 9e45f29..c6dd068`, full, two samples of one prompt

- **Scope:** the three commits on top of v9.3.2 (spec, fix, tests), read-only. Reviewer: Codex (`gpt-5.6-terra`, `sandbox: read-only`), sessions `01a1b248-233b-7060-a0ef-de177c34cfa7` and `01a1b248-233b-7e53-a268-3a6a275ae2de`. Prompt, verdicts and traces: `~/.gstack/projects/larabill/gates/AID-1856/impl-round-1/` and `impl-round-1b/`.
- **Method verdict, both samples:** real on the three rulings. (a) `apply()` and `allocate()` use only the instances `Ledger::lockFundingEntries()` returns, for inference, plan, availability, bands and exits; the lock is one `id = ?` … `FOR UPDATE` per id (read from `vendor/`), after the payment and invoice locks and the payability checks. (b) `refund()` issues the same source-payment query and the same FIFO entry query as on `9e45f29`, and takes no entry lock. (c) The tests assert rows and state after a refusal, the hooks prove their interleaving, and T14 detects a dead child or an applier that gave up.
- **Reproduced by the reviewer on SQLite, in disposable copies:** 17 of 17 feature tests green on HEAD; on the `src/` of `9e45f29`, 14 red and exactly the guards T8a and T10 green; with only the candidate scope removed, or only the narrowing of `sourcePaymentIds()`, 17 of 17 green; Pint and PHPStan level 8 clean; R20 green.
- **Gate verdict, both samples:** FAIL, on one P2 and four P3. No P1. Union below; every locator opened before deciding.

| # | Finding (sample) | Decision | Landed in |
| -- | -- | -- | -- |
| 1 | P2 — the `LogicException` of `consumeEntries()` changed its text («available-credit check» for «availableCredit() check»), against §6.5 (both) | Accepted. The exact text of v9.3.2 is restored | `src/Services/PaymentService.php` |
| 2 | P3 — «nothing else on the table waits» is inaccurate: a write of the same entry waits, which is the purpose (1) | Accepted | docblock of `Ledger::lockFundingEntries()`, CHANGELOG |
| 3 | P3 — `lockFundingEntries()` locks and returns a repeated id twice (1) | Accepted. Ids are deduplicated keeping the first position; a direct test of the helper pins that and the predicate term by term (linked, reversed, voucher-backed, an exit, a missing id) | `Ledger.php`; `AdvanceBackedCreditNotSpendableTest.php`, «returns only the entries that can fund a payment…» |
| 4 | P3 — R20 no longer proved that `allocate()` refills a released band (1b) | Accepted. R20 does the whole original sequence, refill by `allocate()` included, before the link; then repeats the reversal and refill after the link, where the refill is a refund. The allocation refill was also still covered by `CreditMovementBandsTest.php:123-145` | `tests/Unit/Services/AdvanceProRataTest.php` |
| 5 | P3 — T10 did not prove that an inherited exit with a NULL band is still reversible (1b) | Accepted | T10, second test |
| 6 | P3 — query listeners of the engine test persist in the process, or stay active after an unexpected failure (1; 1b as SUSPECTED) | **Discarded, measured.** The harness builds the application for each test and flushes and drops it at teardown (`vendor/orchestra/testbench-core/src/Concerns/ApplicationTestingHooks.php:74`, `:116-118`); `Connection::listen()` registers on that application's dispatcher (`vendor/laravel/framework/src/Illuminate/Database/Connection.php:1130-1133`), which dies with it. Nothing reaches the next test | — |

**What this round covers:** the code and the SQLite tests, executed by the reviewer; the engine and forked tests, read. **What it does not:** no reviewer session touched MySQL or MariaDB — the engine figures of §13 notes 6–8 are the author's.

### Round 2 — bounded to `git diff c6dd068..2bbba89`

- **Scope:** the commit that applies round 1. Session `01a1b258-d777-7110-8afa-351ae483c091`, read-only, 5 minutes. Artifacts: `~/.gstack/projects/larabill/gates/AID-1856/impl-round-2/`.
- **Method:** unchanged — precision only. The deduplication keeps the first-occurrence order (checked on PHP 8.4), and no caller can pass a duplicate today; the direct helper test is a real test of the predicate and of the order, not of the lock (SQLite compiles the lock clause to nothing).
- **Part A:** the six rows of round 1 land, the discard included: the reviewer opened the harness and confirmed that the test application, with its dispatcher, is flushed at teardown even after a failed test, so a query listener cannot reach the next test.
- **Verdict:** PASS over that difference. Part B empty.

### What the implementation gate covers, and what it does not

Two rounds, three reviewer sessions, read-only over this branch. Round 1, full and unbounded, two samples whose union is six findings (one P2, five P3, no P1), five accepted and one discarded with evidence; round 2, bounded to the commit that applied them, PASS. The method was judged real in all three rulings by both samples of round 1: the locked-row selection in both writers, `refund()` byte-for-byte in its queries, and the tests as proofs rather than exception checks.

What no reviewer session executed: the engine tests (`tests/Integration/Mysql/AdvanceBackedCreditRaceTest.php`), the forked test (`tests/Concurrency/AdvanceBackedCreditConcurrencyTest.php`) and the four gated harnesses — read, not run; their figures on MySQL 8.4.10 and MariaDB 11.4.13 are the author's (§13 notes 6–8, and the run over the final commit recorded in §15). Nor the consumer's suite: that is the conformance of §15.

## 15. Conformance and engines, final commit

Candidate `2bbba89` (the commit after implementation gate round 2; this section and §14 round 2 are the only changes after it, both under `docs/`, which is export-ignored and not part of the distributed tree).

- **`clientes` at `6a34fee1`** (its `origin/main`, fresh disposable clone; the real checkout and its `main` untouched), baseline larabill v9.3.1 as its lock pins, candidate installed by path repository with `symlink: false` as 9.4.0, lock moving `aichadigital/larabill` only: full suite 4626 passed / 2 skipped on both sides; conformance suite 154 of 154 on MySQL 8.4 and on MariaDB 11.4 on both sides; per-test comparison of the JUnit output: zero failures and zero skips in either direction, zero status changes, same test set. The difference in assertion totals is, as in the 9.3.2 run, confined to three tests of a random seeder. Record and evidence: `~/.gstack/projects/larabill/gates/AID-1856/conf-2bbba89-clientes-6a34fee1/RESULT.md`.
- **Limit of that gate**, as announced in §11: `clientes` links no entry to an advance, so it proves non-regression only.
- **Gated harnesses of larabill** at `2bbba89` (`tests/Concurrency`, `tests/Integration/{Mysql,InstallMysql,UpgradePath}`, `RUN_CONCURRENCY_IT=1`): MySQL 8.4.10, 150 passed; MariaDB 11.4.13, 150 passed; zero skipped. The engine tests of §7 and T14: green on both engines at that commit.
- **Not done here:** push, MR, merge and tag — each waits for the owner's word.
