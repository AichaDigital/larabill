# AID-1825 — targeted credit exits, credit reservations and the advance's collection state (larabill 9.2.0)

- **Ticket:** AID-1825 (blocks `clientes` AID-962 MR A). Consumer design that motivates it: `~/SitesLR12/clientes/docs/superpowers/specs/2026-10-29-aid-962-prepaid-balance-design.md` §5–§6 (working tree of `clientes`, not yet on its `origin/main`).
- **Base:** `main` @ `2785375` (v9.1.0 published, `[Unreleased]` empty).
- **Status:** rev 4 — builder check applied (§12); owner answered Q1 (2026-10-30): no data backfill in the migration, documented SQL instead (§9). Rev 4 (2026-10-30): review finding applied (§5 lists `inferCurrency()` with the other fixed plans) and a rev 2 leftover removed (§10 upgrade-path test no longer expects the migration to move DRAFT → PAID). Draft for owner review. Nothing is implemented. After owner approval: adversarial spec gate (`ad-adversarial-gate` / `ad-gate-loop`), then plan, then TDD.
- **Related:** AID-962 spec (`docs/superpowers/specs/2026-10-24-aid-962-payment-allocations-customer-credit.md`), AID-1778 spec (`docs/specs/aid-1778-advance-invoice-partial-card.md`), AID-1817 (`breakdown()`, 9.1.0), AID-1815 (deduction primitive — separate ticket, NOT in scope), `STABILITY.md` rules 1, 2 and 5.

## 1. Intent

`clientes` is about to let staff decide, when a payment leaves money over, whether that money **goes back** (a mismatch to refund) or **is an advance** (VAT declared on an advance invoice, art. 75.Dos LIVA). It is also about to refund prepaid balance with a partial rectificative of the advance. With the 9.1 API none of this can be done safely:

- every exit plan is FIFO over **all** active entries of the customer and currency, so the money set aside for a refund, or already documented by an advance, can be eaten by the next `apply()`, `allocate()` or refund;
- the decision about a surplus can only be written **after** the entry exists, in a second transaction — a window where it is consumable;
- a refund has no operation key, so a retry after a crash can take the money out twice;
- an issued advance cannot be rectified (its collection status stays DRAFT), and the partial rectificative of an advance would distort `outstanding()` and `position()`.

The package owns the ledger and its locks, so the protection must live here: `clientes` cannot enforce a rule on a plan it does not compute.

## 2. Diagnosis, measured on `2785375`

- **D1 — untargeted plans.** `CustomerCredit::plan()` (`src/Services/CustomerCredit.php:466-510`) selects active entries filtered only by customer, currency and the locked payments; it never reads `advance_invoice_id`. It is shared by `apply()` (`:244`) and `refund()` (`:315`). `inferCurrency()` (`:376`) sums every entry's remainder. **Not in the ticket, found here:** `PaymentService::allocate()` (`src/Services/PaymentService.php:271`) funds an allocation from the payment's own entries through `consumeEntries()` (`:534`), FIFO, with no filter either, after checking `GroupedPayment::availableCredit()` (`src/Models/GroupedPayment.php:129`), which also sums every entry. That is a third untargeted exit path.
- **D2 — two-step surplus.** `PaymentService::recordRemainder()` (`:600`) and the direct-funded branch of `reverseAllocation()` (`:459-469`) create the entry; any later decision is a separate write.
- **D3 — refunds without a key.** `refund()` takes no idempotency key; one consumed entry may yield several REFUNDED rows (one per band gap, AID-1778 §5.4).
- **D4 — the advance stays DRAFT on the collection axis.** `InvoiceService::issue()` leaves `status` at DRAFT for every directly issued invoice (`src/Services/InvoiceService.php:443-445`); `SettlementService::assertRectifiable()` refuses a DRAFT original (`src/Services/SettlementService.php:506`). So an issued advance can never be rectified.
- **D5 — the advance rectificative nets the debt.** `rectify()` writes the rectificative with `status = SENT` (`:168`) and a negative total; `outstanding()` counts it with its sign (`CustomerCredit.php:108-147`). After a refund of R, `balance()` drops by R and `outstanding()` drops by R as well, so `position()` does not move.
- **D6 — link without the payment lock.** `CreditMovement::linkAdvanceInvoice()` (`src/Models/CreditMovement.php:231`) locks the advance invoice row, then updates the entry; it never takes the entry's payment lock, so a concurrent `apply()` may plan the entry in the same instant it becomes advance-backed. The docblock documents the deadlock cycle with `apply()` (SQLSTATE 40001, test R27).

## 3. Goals

- **G1 — Reservations.** An entry can carry a reservation, `held` (money to give back) or `advancePending` (an advance invoice is being built for it). Reservations are written and cleared only under the entry's payment lock.
- **G2 — Reservation at birth.** `receive()` and `reverseAllocation()` accept the reservation for the surplus entry they create, written in the same transaction.
- **G3 — Spendable means spendable.** Every untargeted plan — `apply()`, `inferCurrency()`, `allocate()` and the existing `refund()` — skips `held` and `advancePending` entries. `apply()`, `inferCurrency()` and `allocate()` also skip advance-backed entries (`advance_invoice_id` set).
- **G4 — Targeted refund.** A new refund that consumes only the entries the caller names, under a mandatory operation key that names ONE refund operation.
- **G5 — The advance is PAID when issued.** Issuing an invoice linked to an active entry sets its collection status to PAID.
- **G6 — The advance rectificative is not debt.** A rectificative of an advance never counts in `outstanding()`; a refund of R lowers `position()` by exactly R.
- **G7 — Breakdown reports the reservations.** `breakdown()` reports `held` and `advancePending` as their own parts.
- **G8 — Link respects reservations and locks.** `linkAdvanceInvoice()` refuses a held entry and takes the entry's payment lock first.

## 4. Non-goals

- Consuming advance-backed balance as a deduction line (AID-1815, its own ticket and release).
- Any policy: when to hold, when to build an advance, who may release. The package offers primitives; `clientes` decides.
- A history table of reservation changes. The reservation is current state on the entry, like `advance_invoice_id`.
- Moving money (bank transfers). A refund still records money that has already left.
- Changing `rectify()`'s artifact, numbering or VeriFactu mapping.

## 5. Anti-goals (a failure even if it works)

- Any held or advance-pending money consumed by a call that did not name it.
- Advance-backed money applied as a payment (VAT declared twice).
- Two refund operations recorded for one key, or one key adopting a different payload.
- A refund that leaves `position()` unchanged.
- A behaviour change observable on any ledger state 9.1 can produce, other than the three fixes declared in §9 (`apply()`/`inferCurrency()`/`allocate()` skipping advance-backed entries, the advance born PAID, the advance rectificative out of `outstanding()`).

## 6. Design

### 6.1 Schema — one migration, `add_credit_reservations_and_refund_operations`

Five pieces in the same MR (`.claude/CRITICAL_RULES.md` §B.6): `.php`, `.php.stub` by copy, `$migrationOrder` entry `'055'`, manifest entry via `bin/sync-upgrade-manifest`, install counts bumped. Every DDL step guarded by introspection (MySQL DDL is not transactional; a partial run must repeat cleanly), as in `2026_10_24_000001`.

- **`credit_movements`** gains:
  - `reservation` `unsignedTinyInteger` nullable — `CreditReservation` enum (`1 = held`, `2 = advancePending`); NULL = free. Only ever non-null on an entry.
  - `reserved_at` datetime nullable, `reserved_by` via `MigrationHelper::userIdColumn(..., nullable: true)` — the last reservation change (set on reserve, kept on release so the last actor stays visible; `reservation = NULL` is what says "free").
  - `refund_operation_id` uuid nullable, FK to `credit_refund_operations.id`, `restrictOnDelete`, indexed — set on the REFUNDED exits a targeted refund creates.
- **New table `credit_refund_operations`:** `id` uuid v7 PK; `operation_key` string(191) **UNIQUE**; `billable_user_id` (`userIdColumn`); `currency` char(3); `amount` bigint (base-100, positive, with the AEAT money-domain CHECK on MySQL/MariaDB, same pattern as `credit_movements.amount`); `entry_ids` json (sorted list); `refunded_at` datetime; `method` tinyint; `reference` string nullable; `recorded_by` (`userIdColumn`, nullable); timestamps.
- **No data changes (owner decision, 2026-10-30):** the migration is schema-only. Every existing entry stays `reservation = NULL` (free), which is exactly how 9.1 treats it, and no existing invoice is touched. The advances issued before 9.2.0 and stuck at collection DRAFT are handled by a documented SQL the consumer runs on purpose (§9), not by the migration.

### 6.2 `CreditReservation` and the reservation primitives (G1)

New `@api` enum `AichaDigital\Larabill\Enums\CreditReservation` with cases `Held` and `AdvancePending`.

New `@api` methods on `CustomerCredit` (write face, unbound like `apply()`):

- `reserve(CreditMovement $entry, CreditReservation $reservation, ?string $reservedBy = null): CreditMovement`
- `release(CreditMovement $entry, ?string $releasedBy = null): CreditMovement`

Both: `OutermostTransaction::assert()` first; read the entry's `grouped_payment_id` outside the transaction (immutable); transaction opens with the X-lock of that payment row; re-read the entry under the lock; validate; one conditional UPDATE; return the refreshed entry.

Rules on the re-read entry:

- Refused with `InvalidCreditMovementException` when it is an exit, reversed, or advance-backed (`advance_invoice_id` set).
- `reserve()` accepts a free entry or an entry holding the OTHER reservation (re-route `held ↔ advancePending` in one write, the `clientes` "changing one's mind" path). Reserving with the reservation it already has is a stable no-op that returns the entry unchanged (audit untouched).
- `release()` on a free entry is a stable no-op.
- A fully consumed entry (`remaining() = 0`) may be reserved or released; it changes nothing that can be spent.

### 6.3 Reservation at birth (G2)

- `PaymentService::receive()` gains a trailing `?CreditReservation $creditReservation = null`, carried through `ReceiveRequest`. `recordRemainder()` writes it (with `reserved_at = receivedAt`, `reserved_by = recordedBy`) on the one entry it creates. With no remainder, the argument has no effect.
- `GroupedPaymentService::register()` (card path, `ReplayPolicy::Legacy`) does not take it.
- **Replay (my call):** the reservation is NOT part of the replay payload (`replayMatches()` unchanged). It is mutable state of the entry — a later `release()` would make every honest retry with the original intent fail as a conflict. A replay returns the payment as it is now. Documented in the docblock.
- `PaymentService::reverseAllocation()` gains a trailing `?CreditReservation $creditReservation = null`, written on the SURPLUS entry of the direct-funded branch. In the credit-funded branch no entry is created — the money goes back to the entries it came from, each keeping its own reservation — and the argument has no effect. The docblock says so; the caller can tell the branches apart by the SURPLUS entry carrying that `allocation_id`.

### 6.4 Spendable entries (G3)

One private predicate decides what each untargeted plan may touch, read **under the payment locks** in every case:

| Plan | free | held | advancePending | advance-backed |
|---|---|---|---|---|
| `apply()` / `inferCurrency()` | yes | no | no | no |
| `allocate()` (`consumeEntries()`) | yes | no | no | no |
| `refund()` (existing, untargeted) | yes | no | no | yes (unchanged) |
| `refundEntries()` (new, targeted) | named only | named only | refused | named only |

- `apply()`: an amount larger than the spendable balance throws `InsufficientCreditException::forBalance()` with the **spendable** balance as `available` (the existing message shape).
- `allocate()`: the availability check uses a new `GroupedPayment::spendableCredit()` (`@api`, same arithmetic as `availableCredit()` over spendable entries only). `availableCredit()` keeps its 9.1 meaning (every active entry) — it is `@api` and its readers may use it for display.
- `sourcePaymentIds()` may pre-filter by the same predicate (an entry released in between is just not used in this run — the existing "may leave credit unused, never over-consume" doctrine); the plan filters again under the locks regardless. The builder chooses.
- The untargeted `refund()` keeps consuming advance-backed entries, as in 9.1. It is marked `@deprecated` in favour of `refundEntries()` (removal no earlier than 10.0, `STABILITY.md` rule 3): a refund that does not name its entries cannot tell a surplus from an advance, and only the latter needs a rectificative. **(My call — see §11.)**

### 6.5 Targeted refund with an operation key (G4)

New `@api` method on `CustomerCredit`:

```php
public function refundEntries(
    string $billableUserId,
    array $entryIds,                 // non-empty list<string>
    FixedDecimal $amount,
    string $currency,
    DateTimeInterface $refundedAt,
    PaymentMethod $method,
    string $operationKey,            // non-empty
    ?string $reference = null,
    ?string $recordedBy = null,
): Collection                        // Collection<int, CreditMovement>: every REFUNDED exit of the operation
```

Steps:

1. `OutermostTransaction::assert()`; validate the amount (`validAmount()`), the key (non-empty) and the list (non-empty, no duplicates); sort the ids.
2. Outside the transaction: read the named entries' `grouped_payment_id`s (plain read). An id that does not exist is refused here with zero writes.
3. Transaction. FIRST statements: lock those payments by id ascending (`lockPayments()`).
4. **Adoption, after the locks:** read `credit_refund_operations` by key. If it exists: same payload (customer, currency, amount, sorted entry ids, `refunded_at` to the second UTC, method, reference) → return every exit with that `refund_operation_id`, ordered by tape position as `refund()` returns them; any difference → `IdempotencyConflictException::forKey()`. `recorded_by` is not part of the payload (a retry may come from another actor).
5. Validate every named entry, under the locks: belongs to the customer and currency, is an active ENTRY of a locked POSTED payment, is not `advancePending`. Any violation → `InvalidCreditMovementException` (a new named constructor per cause), zero writes.
6. Plan FIFO (`occurred_at`, then `id`) over the named entries only, with the existing band placement and split (`recordExit()`); Σ remaining of the named entries < amount → `InsufficientCreditException::forBalance()`.
7. Insert the operation row, then the exits, each carrying `refund_operation_id`.
8. **Race backstop:** two same-key calls naming entries of different payments do not serialize on the locks; the UNIQUE on `operation_key` makes the loser's insert fail (SQLSTATE 23000 naming `operation_key`). Catch it outside the transaction (it rolled back), re-read the winner, compare the payload → return the winner's exits or throw `IdempotencyConflictException::forKey()` — the same backstop shape as `PaymentService::process()`.

`refundEntries()` names the entries; inside each entry, placement on the tape stays the band rule of AID-1778 (lowest gap first, split per gap).

**No per-entry amounts, by design.** The consumer's two uses (its design §6.2 and §6.7) are "refund exactly this held entry" and "refund these advance-backed entries, oldest first" — both are an amount over a named set, consumed FIFO. The caller learns what each part needs from the returned exits: every exit carries `consumes_movement_id`, and an exit whose entry is advance-backed is the input of `AdvanceInvoiceBuilder::rectificativeLines()`. A caller that wants "X from A, Y from B" makes two operations.

### 6.6 The advance is PAID when issued (G5)

In `InvoiceService::issue()`, after the invoice lock and before the ISSUED write, read the entries linking this invoice as advance (`advance_invoice_id = invoice id`; at most one, AID-1778 O2). The read is race-free: `linkAdvanceInvoice()` serializes on the same invoice row lock.

- No linked entry → unchanged (collection stays DRAFT).
- Linked ACTIVE entry and collection `status` DRAFT → in the same transaction, `status = PAID`, `paid_at = entry.occurred_at` (the money arrived then). `recomputeCollectionState()` leaves it alone afterwards: the advance has no allocation rows (guard 3).
- Linked ACTIVE entry and any other collection `status` (a consumer stamped it before issuing) → left as the consumer wrote it. The package never overwrites a status it did not write.
- Linked REVERSED entry (the payment was reversed between link and issue) → `issue()` refuses with a new `InvoiceNotIssuableException::advanceEntryReversed()`, zero writes: issuing would declare VAT on money no longer held. **(My call — see §11.)**
- **Residual race, accepted and documented:** `issue()` holds the invoice lock, `PaymentService::reverse()` holds the payment lock, so a reversal can commit right after the check. That end state — an issued advance over a reversed entry — is the same one reachable by reversing after issuance, which 9.1 already allows. Its exit is `SettlementService::annul()` (the advance stays DRAFT on the collection axis, `rectify()` keeps refusing it): the money never stayed, so the document is annulled, not rectified. The refusal is a guard against the common case, not a lock-backed invariant; the docblock says so.

Upgrade: advances issued before 9.2.0 stay DRAFT on the collection axis until the consumer runs the documented SQL of §9; until then `rectify()` keeps refusing them, as in 9.1.

### 6.7 The advance rectificative is not debt (G6)

An **advance** is an invoice that some credit entry links through `advance_invoice_id` (the link is permanent: it survives the reversal of the entry). The money behind an advance lives in the ledger, so neither the advance nor its rectificatives are debt:

- `outstanding()` excludes every invoice whose `rectifies_invoice_id` is an advance (one extra `whereNotIn`/`whereNotExists` over `credit_movements.advance_invoice_id`, portable, no `UPDATE`).
- New `@api` `Invoice::isAdvanceRectificative(): bool` and query scope `Invoice::scopeExcludingAdvanceRectificatives()`, so a consumer that lists open invoices can apply the same rule (the `clientes` design needs it, its §8).
- `rectify()` itself is unchanged: the rectificative is still born SENT. Changing its persisted status was considered and rejected: it is a generic settlement artifact and its status is read by other readers.

Result: a refund of R (exit −R) lowers `balance()` by R and leaves `outstanding()` untouched, so `position()` drops by exactly R.

### 6.8 Breakdown (G7)

`CreditBreakdown` gains two trailing constructor parameters with zero defaults, `held` and `advancePending` (`FixedDecimal`), and `CreditEntryRemainder` gains a trailing `?CreditReservation $reservation = null`. In `breakdown()`:

- `consumable` — free entries with no advance invoice: exactly what `apply()` may spend (its 9.1 docblock: "spendable with `apply()`" stays true);
- `held`, `advancePending` — entries with that reservation;
- `advanceBacked` — unchanged;
- `total()` = the four parts = `balance()`.

On every ledger state 9.1 can produce (no reservation exists), `held = advancePending = 0` and every 9.1 value — `consumable`, `advanceBacked`, `total()` and the identity `consumable + advanceBacked == balance()` — is identical. The identity is restated in the docblock as the four-part sum. **(Option chosen without a major — see §11.)**

### 6.9 Link (G8)

`linkAdvanceInvoice()`:

- reads the entry's `grouped_payment_id` before the transaction and opens with the **payment row X-lock**, then the advance invoice row lock — the global order (payments, then invoices). This closes D6 (the link and every plan now serialize on the payment) and removes the documented deadlock cycle with `apply()`; test R27 is rewritten to assert serialization instead of a 40001 retry. **(My call — see §11.)**
- refuses a `held` entry (`InvalidCreditMovementException::held()`, before any write);
- links a free or `advancePending` entry; linking an `advancePending` entry clears its reservation in the same conditional UPDATE (`advancePending → advance-backed`).

Two calls racing to link and release the same `advancePending` entry now serialize on the payment lock; the conditional UPDATEs keep their guards.

**Caller context unchanged:** no `OutermostTransaction` guard is added (it would be a new refusal on a 9.x method). The docblock caller contract of 9.0 stays — outermost, or a wrapper with no prior read — and gains one sentence: a wrapper that already holds an invoice lock inverts the global order and reintroduces the deadlock with `apply()`.

## 7. Concurrency summary

Every write that changes what can be spent — `reserve()`, `release()`, `linkAdvanceInvoice()`, `apply()`, `allocate()`, `refund()`, `refundEntries()`, `reverse()`, `reverseAllocation()` — holds the payment row lock of every entry it reads or writes, taken as its first statements, payments by id ascending before any invoice. `receive()` creates the entry with its payment in one transaction, so no one can see it before its reservation exists. The only reads outside the locks are the immutable `grouped_payment_id`s used to know which payments to lock, plus the existing `sourcePaymentIds()` pre-read.

## 8. Tests (TDD, RED first)

- **Reservations:** reserve/release/re-route transitions, the refusals (exit, reversed, advance-backed), no-ops leave the audit untouched; a reservation at birth through `receive()` and through `reverseAllocation()` (direct branch), and its no-effect in the credit-funded branch.
- **Plans:** `apply()`, `inferCurrency()`, `allocate()` and `refund()` each skip what §6.4 says, on a ledger mixing the four kinds; `InsufficientCreditException` reports the spendable balance; `spendableCredit()` vs `availableCredit()`.
- **Targeted refund:** consumes only named entries, FIFO among them, split per band gap; refuses advance-pending, foreign customer/currency, exits, reversed entries, an unknown id; same-key same-payload adoption returns ALL exits (including a split); same key different payload refused; adoption checked after the locks.
- **Concurrency (`tests/Concurrency`, `RUN_CONCURRENCY_IT=1`, with the anti-skip guard and a sensitivity proof — `ad-concurrency-gate`):** `reserve()` vs `apply()` on the same entry (the plan never consumes a reserved entry); two same-key `refundEntries()` (one operation recorded); `linkAdvanceInvoice()` vs `apply()` (rewritten R27: serialization, no 40001).
- **Advance PAID:** issue of a linked advance → PAID with `paid_at`; reversed linked entry → refused; unlinked invoice unchanged; `rectify()` of an issued advance now succeeds.
- **Position:** refund R of an advance-backed entry + its partial rectificative (`rectificativeLines()` + `rectify()`) → `position()` drops by exactly R; `outstanding()` unchanged; `isAdvanceRectificative()` and the scope.
- **Breakdown:** four parts sum to `balance()`; on a ledger without reservations the 9.1 values are unchanged (regression).
- **Migration:** upgrade-path test (`tests/Integration/UpgradePath/`) seeding a 9.1 ledger with an issued advance at collection DRAFT: after migrate it is still DRAFT and every existing row is untouched (schema only, §9); after running the documented SQL of §9 it is PAID with `paid_at = entry.occurred_at`, and a second run changes nothing; `InstallMysql` counts; gated harnesses on MySQL 8.4 and MariaDB 11.4 (`.claude/CRITICAL_RULES.md` §A).
- **Contract:** snapshots regenerated through `bin/sync-contract-snapshots` (`Invoice` gains a method and a scope); `SurfaceTaxonomyTest` for the new enum and any new class; `docs/api-surface.md`.

## 9. Version and STABILITY classification — 9.2.0 (minor)

- **Additive (rule 5):** the enum, `reserve()`, `release()`, `refundEntries()`, `spendableCredit()`, `isAdvanceRectificative()` and its scope, the trailing parameters of `receive()` and `reverseAllocation()`, the breakdown fields, the new table and columns (rule 2: new table and nullable columns). None of them changes a value on a 9.1-reachable state: no reservation exists before this release.
- **Fixed, with old and new behaviour stated in the CHANGELOG (rule 5):**
  1. `apply()`, `inferCurrency()` and `allocate()` no longer consume advance-backed entries. Old: consumed FIFO like any entry, declaring VAT a second time. Measured: no consumer can depend on it — `clientes` `origin/main` does not create advances.
  2. Issuing a linked advance sets it PAID. Old: collection DRAFT, never rectifiable.
  3. `outstanding()` excludes advance rectificatives. Old: netted, so a refund did not move `position()`.
- **No data backfill (owner decision, 2026-10-30, Q1 of §12).** Rewriting the collection status of invoices already persisted would change stored data in a minor (`STABILITY.md` rule 2). Instead, the CHANGELOG entry and the README upgrade note carry an equivalent, portable SQL (select first, then update) that moves issued advances linked to an ACTIVE entry from collection DRAFT to PAID with `paid_at = entry.occurred_at`. The consumer runs it deliberately; the package never does. Precedent: lesson 2026-07-22 (SQL equivalent in CHANGELOG/UPGRADE). Exposure measured: advances exist since 8.4 (2026-10-26) and `clientes` `origin/main` creates none, so no known install has rows to fix. The migration ships schema only ("Ships migrations: yes", additive). A generic data-migration mechanism for the umbrella is out of scope here and tracked in AID-1826.
- **Deprecated:** untargeted `refund()` (§6.4), removal no earlier than 10.0.

## 10. Left to the builder

- Names of the private helpers, of the new exception constructors and of the migration's constants.
- Whether `sourcePaymentIds()` pre-filters (§6.4).
- The exact SQL shape of the `outstanding()` exclusion and of the documented upgrade SQL, within "portable, no `UPDATE … JOIN`"; the documented SQL is verified by a test that seeds a 9.1-shaped stuck advance, runs it, and asserts the result on MySQL 8.4 and MariaDB 11.4.
- Splitting the work into tasks, as long as each task lands with its RED test first.

## 11. Calls I made (owner to confirm while reading)

1. **Reservation is not part of the `receive()` replay payload** (§6.3).
2. **Untargeted `refund()` deprecated** rather than narrowed or kept as is (§6.4).
3. **`issue()` refuses a linked advance whose entry was reversed** (§6.6) — new refusal on a 9.1-reachable state, argued as a fix.
4. **`linkAdvanceInvoice()` takes the payment lock first** (§6.9), changing the documented lock order and test R27.
5. **`CreditBreakdown::$consumable` now excludes reserved entries** while the 9.1 identity holds on every 9.1 state (§6.8), so 9.2.0 and not 10.0.
6. **The advance rectificative is excluded in `outstanding()`**, not by changing its persisted status (§6.7).
7. **`issue()` sets PAID only over a DRAFT collection status**; a status the consumer stamped is left alone (§6.6).
8. **An issued advance over a reversed entry is annulled, not rectified**, and the issue-vs-reverse race is accepted and documented rather than closed with a cross lock (§6.6).
9. **`refundEntries()` takes one amount over the named set, FIFO**, no per-entry amounts (§6.5).
10. **`linkAdvanceInvoice()` gets no new guard**, only a docblock sentence on the inverted-order risk (§6.9).

## 12. Builder check (rev 1 → rev 2)

A fresh reviewer read rev 1 as the builder (2026-10-30), with read access to `src/`. It confirmed D1–D6 against the code and raised four questions. Q2 (advances outside the happy path), Q3 (per-entry amounts) and Q4 (caller context of the link) are settled above as calls 7–10. Q1 — whether a §6.1 data backfill fits `STABILITY.md` rule 2 in a minor — went to the owner, who chose (2026-10-30) to drop it and ship a documented SQL instead (§9).
