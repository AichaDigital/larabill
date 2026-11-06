---
title: Fail closed when a credit exit write is vetoed (AID-1864)
date: 2026-11-06
status: rev 3 — direction approved by the owner on 2026-11-06 (scope of §2 confirmed, AID-1856 decided as option A, §7). Rev 3 applies round 1 of the spec gate; round 2 passed over rev 3 (§9, with what the verdicts cover and what they do not). Implementation follows §8; push, MR, merge and tag each wait for the owner's word.
base: v9.3.1 (c9a5e85)
origin: AID-1864
---

# Fail closed when a credit exit write is vetoed

## 1. The defect, measured

Eloquent's `save()` returns `false` without throwing when a `saving`/`creating` listener vetoes the write, and `Builder::create()` swallows that return: it hands back the unsaved model. The package already turns a vetoed write into an exception through `Support\CheckedWrite` on the invoice, payment, allocation and voucher paths (AID-1825). The APPLIED and REFUNDED credit exits are the writes that were left out.

Measured on v9.3.1 with a throwaway probe (SQLite suite, deleted after the run; 5000 of TOP_UP credit, one SENT invoice of 3000, a `CreditMovement::saving` listener that vetoes one exit kind):

| Call | Exception | What is committed | Result |
| -- | -- | -- | -- |
| `CustomerCredit::apply()` 3000, APPLIED vetoed | none | 1 allocation, 0 exits, invoice `PAID` | balance still 5000: the invoice is paid and the credit was not spent |
| then `PaymentService::reverseAllocation()` of that row | none | a new SURPLUS entry of 3000 | balance **8000**: the reversal reads an allocation with no exits as a direct one and mints credit |
| `CustomerCredit::refund()` 2000, REFUNDED vetoed | none | nothing | returns an **empty** collection (`Eloquent\Collection::merge()` drops models without a key); balance still 5000 while the consumer believes the refund is recorded |
| `PaymentService::allocate()` 3000 from the payment's own entry, APPLIED vetoed | none | 1 allocation, 0 exits, invoice `PAID` | `availableCredit()` still 5000 |

A partial veto (one exit of several) is the same defect with a different symptom: the allocation is funded for less than its amount, and a later `reverseAllocation()` aborts on its own funding check.

The same three calls fail the same way on an entry that carries a legacy NULL-band exit — the other branch of each writer (§2). Measured with a second probe during the spec gate (§9, finding 1): `apply()` and `allocate()` commit the allocation with no exit and no exception; `refund()` returns an empty collection and the entry's remaining balance does not move.

`clientes` registers no listener or observer on `CreditMovement` or `PaymentAllocation`. Measured read-only on its `origin/main` at `6a34fee1` (2026-11-05): `git -C <clientes checkout> grep -nE "CreditMovement::(saving|creating|saved|created|observe)|PaymentAllocation::(saving|creating|saved|created|observe)|ObservedBy|CreditMovementObserver|PaymentAllocationObserver" origin/main -- app` returns nothing. The defect is latent there, not active. Any consumer that does register one is exposed today.

## 2. Scope

Every write of an APPLIED or REFUNDED exit — four `create()` calls in two methods, each method with two branches:

| Write | Branch | Reached from |
| -- | -- | -- |
| `CustomerCredit::recordExit()` `src/Services/CustomerCredit.php:594` | NULL band (the entry has an active exit with no band; `ExitBands::segmentsFor()` returns `null`) | `apply()`, `refund()` |
| `CustomerCredit::recordExit()` `src/Services/CustomerCredit.php:603` | one row per segment | `apply()`, `refund()` |
| `PaymentService::consumeEntries()` `src/Services/PaymentService.php:622` | NULL band | `allocate()` |
| `PaymentService::consumeEntries()` `src/Services/PaymentService.php:636` | one row per segment | `allocate()` |

The ticket names `apply()` and `refund()` and asks for «every APPLIED/REFUNDED credit-output write». `consumeEntries()` writes the same APPLIED exit and fails the same way (row 4 of §1), so it is in. There is no other writer of an APPLIED or REFUNDED exit in `src/`: the remaining `CreditMovement` writers produce SURPLUS/TOP_UP entries (`PaymentService.php:497`, `:671`) or CONSUMED voucher exits (`VoucherSaleService.php:712`, already checked). Confirmed by both samples of round 1 (§9).

Not in scope, and recorded in §6: the credit **entry** writes, the invoice collection-state write, and the bulk `update()` calls that never fire model events.

**Owner decision (2026-11-06):** `PaymentService::allocate()` is in scope — same vetoable APPLIED write, same integrity failure. The entry writes and the invoice collection-state write stay out; this change is not widened with those defects.

## 3. Design

One checked writer, next to the one that already exists for allocations:

```php
// Services\Payments\Ledger (@internal)
public static function createExit(array $attributes): CreditMovement
{
    $exit = new CreditMovement($attributes);
    CheckedWrite::orFail($exit->save(), 'a credit exit');

    return $exit;
}
```

- The four `CreditMovement::query()->create([...])` calls of §2 become `Ledger::createExit([...])` with the same attribute arrays.
- **Row equivalence, for these four calls.** `Builder::create()` is `newModelInstance($attributes)` followed by `save()` (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:1277-1281`), and `new CreditMovement($attributes)` fills the same way. Every key the four arrays pass is in `$fillable` (`src/Models/CreditMovement.php:68-72`), the casts are model-level (`:74-82`), the UUID is set in `creating` (`src/Concerns/HasUuid.php:29-37`), and all four calls use the model's default connection and table. The claim is limited to that: it is not a statement about a custom builder connection.
- A veto throws the `LogicException` of `CheckedWrite` — the exception the package already uses for every other vetoed write. No new exception class, no new public surface.
- **What rolls back.** The four writes run inside the `DB::transaction()` of `apply()` (`CustomerCredit.php:262-308`), `refund()` (`:337-348`) and `allocate()` (`PaymentService.php:290-318`), and nothing between the write and that boundary catches `LogicException`. The exception leaves the closure and the transaction rolls back every row written on that connection in the call: the allocation, the exits already written and — in `apply()` and `allocate()` — the invoice state, which is recomputed only after the exits. When the caller holds an outer transaction (`allocate()` does not assert outermost), the rollback is to the savepoint and the exception keeps propagating.
- `apply()`, `refund()` and `allocate()` gain `@throws LogicException` in their docblocks. No signature changes.

**What this fix guarantees, and what it does not.** The guarantee is exactly: when a `saving` or `creating` listener halts an exit write, the call throws and leaves no row behind on the transaction's connection. Outside that guarantee, and unchanged by this fix:

- A listener that does not veto but **mutates** the exit's attributes, or a `created`/`saved` listener that deletes or rewrites the row after the insert (`Model.php:1649-1663`: `created` fires after the SQL, and `save()` still returns `true`). The bool cannot see either.
- **Effects outside the connection.** In a partial veto, the exits written before the vetoed one have already fired `created` and `saved`. A consumer listener that wrote to a cache, called an external service or used another connection is not undone by the rollback. `afterCommit` callbacks are not affected: they are discarded with the transaction. This limit is the one every checked write of the package already has.

Alternatives discarded: checking `$model->exists` after `create()` (works, but leaves two idioms for the same rule); merging `recordExit()` and `consumeEntries()` into one method (a refactor the fix does not need).

## 4. Classification

**Patch — 9.3.2.** No signature, schema, migration or config change, and no change on any path that succeeds today. The change is an intentional correction of the **failure path**: with a vetoing listener, v9.3.1 commits an allocation and an invoice state without their exit (or reports a refund that was not recorded); 9.3.2 throws and commits nothing. That is a difference in persisted state a consumer can observe, so it is documented under **Fixed** with the old and the new behaviour (`STABILITY.md` rule 5). CONSTITUTION §8 is met by the first compatible option (internal fix).

## 5. Tests (TDD — each one red on v9.3.1 before the fix)

Rules for every test below:

- The listener is registered **after** the fixture is built (the fixtures themselves write `CreditMovement` rows), vetoes only the exit kind under test, and is removed in a `finally` with `CreditMovement::flushEventListeners()` + `CreditMovement::clearBootedModels()` — the existing pattern of `tests/Feature/Payments/VoucherConsumptionTest.php:248-262`.
- Every assertion of «nothing was written» is made on rows and on state: allocation count, exit rows of the kind, the entry's `remaining()`, invoice status and `paid_at`, `balance()`.

SQLite suite, `tests/Feature/Payments/`:

- **T1 — `apply()`, single exit vetoed:** throws `LogicException`; zero allocations, zero APPLIED exits, invoice still `SENT` with `paid_at` null, `balance()` and `breakdown()` unchanged.
- **T2 — `apply()`, partial veto:** two fresh TOP_UP entries from two payments with distinct, deterministic `occurred_at`, and an amount that spans both, so that `plan()` yields exactly one segment per entry in a known order. The listener counts the APPLIED attempts and vetoes the **second**. The first payment's allocation and exit are rolled back too.
- **T3 — `refund()`, single exit vetoed:** throws; zero REFUNDED rows; `balance()` unchanged.
- **T4 — `refund()`, partial veto:** the fixture shape of T2; the listener vetoes the second REFUNDED attempt; the first REFUNDED row is rolled back.
- **T5 — `allocate()`, exit vetoed:** throws; zero allocations, zero exits, invoice unchanged, `availableCredit()` unchanged.
- **T6 — no drift after a refused call:** after T1's refusal, the same `apply()` without the listener succeeds and the funding invariant holds (allocation amount = Σ of its exits).
- **T8 — `apply()` on a NULL-band entry, exit vetoed:** the entry carries an active legacy exit with `band_start = NULL` (seeded before the listener, as `tests/Feature/Payments/CreditMovementBandsTest.php:215-229` does) and enough remaining credit. Throws; no allocation, no new exit, invoice and the entry's `remaining()` unchanged.
- **T9 — `refund()` on a NULL-band entry, exit vetoed:** same seed; throws; no new REFUNDED row, `remaining()` unchanged.
- **T10 — `allocate()` on a NULL-band entry, exit vetoed:** same seed; throws; no allocation, no new exit, invoice unchanged.
- **T11 — the guard reads the bool, not the model:** a vetoing listener that also sets `$movement->exists = true` before returning `false`; `apply()` still throws and keeps nothing. It is the test that tells the checked `save()` from a guard that looked at `exists` (implementation gate, §9).

Which test goes red if one of the four writes is left as `create()`:

| Write left unchecked | Tests that must go red |
| -- | -- |
| `recordExit()` segments (`:603`) | T1, T2, T3, T4 |
| `recordExit()` NULL band (`:594`) | T8, T9 |
| `consumeEntries()` segments (`:636`) | T5 |
| `consumeEntries()` NULL band (`:622`) | T10 |

That mapping is not taken on faith: §8 step 3 proves it by mutation.

Real engines, `tests/Integration/Mysql/`:

- **T7 — the transactional regression on real engines:** the vetoed `apply()` and `allocate()` of T1 and T5, and the **partial** `refund()` of T4, each asserting that the transaction left nothing behind (no allocation, no exit, invoice state and balance unchanged). The refund case must be the partial one: a refund whose only exit is vetoed runs no SQL, so it would pass with no rollback at all; with two entries the first REFUNDED row is really inserted before the veto, and only a rollback removes it. The directory is gated by the five `LARABILL_TEST_MYSQL_*` variables (`tests/Integration/Mysql/MysqlIntegrationTestCase.php:21-48`), not by `RUN_CONCURRENCY_IT`; each test calls `$this->bootstrap()` as the existing ones do. It is run and must be green on **MySQL 8.4** and on **MariaDB 11.4** — both, not either — with **zero skips** on each run: a run that skips proved nothing. The SQLite suite is not accepted as proof of the rollback. In CI the `db-integration` jobs run this directory on both engines in every MR and fail on any skip (`.gitlab-ci.yml:249-258`).

Regression: the full suite, plus the four gated harnesses — `tests/Integration/{Mysql,InstallMysql,UpgradePath}` (gated by `LARABILL_TEST_MYSQL_*`) and `tests/Concurrency` (those variables plus `RUN_CONCURRENCY_IT=1`) — on MySQL 8.4 and on MariaDB 11.4.

## 6. Out of scope — measured, recorded, not touched

Unchecked writes of the same family that this ticket excludes by its own text («other unchecked writes unrelated to credit apply/refund»):

- **Credit entries:** `PaymentService::recordRemainder()` (`:671`, the SURPLUS/TOP_UP of `receive()`) and the SURPLUS that `reverseAllocation()` writes for a direct allocation (`:497`). A veto there loses the customer's balance record while the payment or the reversal commits.
- **Invoice collection state:** `Invoice::recomputeCollectionState()` ends in an unchecked `$this->save()` (`src/Models/Invoice.php:1012`) and has five callers. A veto leaves allocations committed over a stale status.

Both deserve their own ticket. Whether to open it is the owner's call. Leaving them out does not weaken §3 or §5: the listeners of the tests veto exit kinds only, and neither excluded write is an exit.

## 7. AID-1856 is not designed here

AID-1856 asks that `plan()`, `sourcePaymentIds()` and `refund()` select only consumable entries (neither voucher nor advance). Measured against the tree, that request collides with published 9.0 surface:

- `AdvanceInvoiceBuilder::rectificativeLines()` (`@api`, 9.0.0) needs an ACTIVE **REFUNDED** exit that consumes an advance-backed entry. The only writer of such an exit is `CustomerCredit::refund()`. Excluding advance-backed entries from `refund()` leaves that method unreachable through the package, and with it the one fiscally complete way to give an advance back (refund → rectificative of the advance). `tests/Unit/Services/AdvanceProRataTest.php:421` pins that path today.
- With `apply()` closed as well and AID-1815 not delivered, advance-backed balance would have **no exit at all**.
- The owner-accepted minimal scope of AID-1825 (`2026-11-01-aid-1825-minimal-scope.md` §4) lists «advance-backed balance can be applied as payment» as an existing defect **kept as in 9.1**, with destination **AID-1815**.

The two halves of the request are not alike. Applying advance-backed credit as a payment is never correct (VAT declared twice, and the deduction line cannot enter an issued invoice — the hole AID-1815 describes). Refunding it is the documented 9.0 flow. Removing a published capability without a substitute is a utility reduction under CONSTITUTION §9 and needs the owner's explicit decision; it is not a patch.

Options that were put to the owner:

- **A.** Close only the payment side: `apply()` and `inferCurrency()` stop selecting advance-backed entries; `refund()` keeps today's behaviour. Whether `PaymentService::allocate()` (the same hole through the payment's own entries) joins is part of the decision. Own spec, after AID-1864.
- **B.** The ticket as written. Needs a targeted refund as the substitute for `rectificativeLines()`'s feeder — new public surface, a minor, in practice part of the AID-1815 family.
- **C.** Fold AID-1856 into AID-1815, as the accepted scope of 2026-11-01 already did, and do nothing before it.

**Owner decision (2026-11-06): option A.** An advance and a voucher are not the same thing: using advance-backed balance as an ordinary payment is blocked, its published fiscal refund is not removed.

- `refund()` keeps consuming advance-backed entries: the published flow (refund of the advance, then its rectificative) is preserved.
- Advance-backed balance must not be usable as an ordinary payment, through `CustomerCredit::apply()` **and** through `PaymentService::allocate()`.
- That scope is designed in a separate spec, after AID-1864 is closed. Nothing of it is implemented under this spec.
- AID-1815 remains the destination for how an advance is treated in future invoices.

## 8. Delivery

0. Spec gate: adversarial review of this spec, read-only, with every finding measured against the tree and adjudicated in §9. No code before that.
1. Branch `abdelkarim/aid-1864-fail-closed-when-credit-movement-writes-are-vetoed` from `main`.
2. TDD: T1–T11 red on v9.3.1, then the fix, then green.
3. **Sensitivity proof, one write at a time.** With the fix committed, each of the four `Ledger::createExit()` calls is put back to `CreditMovement::query()->create()` on its own, the tests of its row in the §5 table are run and must go red, and the file is restored from a saved copy (never with `git checkout`). A write whose tests stay green is not covered, and the delivery stops there.
4. `CHANGELOG.md` → `[Unreleased]` / **Fixed**, old and new behaviour stated.
5. Local gates: `pint --test` twice, PHPStan level 8, the full suite in parallel, the four gated harnesses on MySQL 8.4 and on MariaDB 11.4 with the skip count checked. The parallel run is a local gate; CI runs the same suite sequentially with coverage (`pest --ci --coverage --min=83`, `.gitlab-ci.yml:134`) and the `db-integration` jobs on both engines.
6. Adversarial gate before merge (money path).
7. Conformance gate against `clientes` on the exact candidate before tagging 9.3.2.

Push, MR, merge and tag each wait for the owner's word.

## 9. Spec gate — adjudication

The reviewer is Codex (`codex exec -s read-only`, reasoning effort high) over this checkout at `c9a5e85`, suite green before launch. Evidence per round under `~/.gstack/projects/larabill/gates/AID-1864/`. Reviewed-by: Codex <noreply@openai.com>.

### Round 1 — the whole spec, two identical samples, union taken

- **Scope:** rev 2 of this document over the tree at `c9a5e85`; unbounded; method judged first.
- **Sessions:** `01a1af81-6a55-70f1-bd1c-f1e2d2f39589` (sample A) and `01a1af82-0d24-7330-8c66-871ae1547f2a` (sample B).
- **Method verdict, both samples:** real with conditions — the bool check is real for a `saving`/`creating` veto and the rollback is real in the three entry points; it is not a general guarantee that the row exists with the intended contents.
- **Verdict, both samples:** GATE FAIL.

| # | Finding | Sample | Decision | Landed in |
| -- | -- | -- | -- | -- |
| 1 | P1 — T1–T7 exercise only the per-segment branch; the NULL-band writes (`CustomerCredit.php:594`, `PaymentService.php:622`) can stay unchecked with every test green | A, B | **Accepted.** Measured: `ExitBands::segmentsFor()` returns `null` only when an active exit of the entry has `band_start = NULL` (`ExitBands.php:74-80`), which no fixture of T1–T7 has; and a probe on v9.3.1 showed the three calls failing silently on that branch | §1 (measurement), §2 (table of branches), §5 T8–T10 and the write→test table, §8 step 3 |
| 2 | P2 — T7 names the wrong gate: `tests/Integration/Mysql/` is gated by `LARABILL_TEST_MYSQL_*`, not by `RUN_CONCURRENCY_IT` | A, B | **Accepted.** Measured at `MysqlIntegrationTestCase.php:21-48` and `.gitlab-ci.yml:249-263` | §5 T7 and the regression line |
| 3 | P2 — «nothing else is needed for the rollback» is too broad: earlier exits fire `created`/`saved` before a later veto; effects outside the connection are not undone | A, B | **Accepted** as a limit to declare, not as a change of mechanism (`Model.php:1649-1663`) | §3, «What this fix guarantees, and what it does not» |
| 4 | P2 — §4 said «no persisted-semantics change»; the failure path does change what is persisted | B | **Accepted.** Wording corrected; the classification stands (both samples support patch under `STABILITY.md` rule 5) | §4 |
| 5 | P3 — the equivalence `new Model($attributes)` + `save()` ≡ `create()` holds for these calls, not in general | A, B | **Accepted** | §3, row equivalence bullet |
| 6 | P3 — T2/T4 are constructible but must pin their fixture and count only the kind under test | A, B | **Accepted** | §5 T2, T4 |
| 7 | P3 — the listener must be registered after the fixture and removed in `finally` | A, B | **Accepted** | §5, rules |
| 8 | P3 — the inventory of four writers is complete | A, B | Confirmed; matches the author's own search of `src/` | §2 |
| 9 | Suspected — the `clientes` «no listener» claim could not be verified from this checkout | A, B | **Measured by the author**, read-only, with the command now stated | §1 |
| 10 | Suspected — no engine-specific rollback divergence visible in the source; not executed | B | Stands as a suspicion until T7 runs on both engines | §5 T7 |
| 11 | Suspected — «full suite in parallel» is not enforced by CI | B | **Accepted.** Measured at `.gitlab-ci.yml:134`; stated as a local gate | §8 step 5 |

No finding was discarded. No finding asked to change the method or the settled scope.

### Round 2 — revision 3, two identical samples, union taken

- **Scope:** rev 3 of this document over the tree at `c9a5e85`. Part A: does each of the eleven corrections land, plus four checks of the test plan. Part B, **not bounded to the diff**: the path after the guard in `apply()`, `refund()` and `allocate()` — in-memory state after a throw, rows written before it, what the callers need from the returned model, and sequences that would still end inconsistent.
- **Sessions:** `01a1af8e-6a6e-7112-ae26-cf58439e945c` (sample A) and `01a1af8e-6a6d-7213-a903-30d2cbbed7c8` (sample B).
- **Method, both samples:** rev 3 changed the precision, not the method; no gap inside the wording of the §3 guarantee.
- **Verdict, both samples:** GATE: PASS over rev 3. Part A: eleven of eleven land. Part B: no finding in either sample.

| # | Item | Sample | Decision | Landed in |
| -- | -- | -- | -- | -- |
| 12 | Suspected — rollback on real engines not executed (no access to MySQL or MariaDB, by instruction) | A, B | Stands; T7 on both engines is the proof | §5 T7 |
| 13 | Note — T7 needs `bootstrap()`, `seedUser()` for issuer and customer, and no fiscal config (no issuance happens) | A, B | Taken as implementation guidance; consistent with `MysqlIntegrationTestCase.php:70-166` | §5 T7 (no text change needed) |

What the reviewer executed, in an ephemeral copy outside this checkout (probes preserved under `round-2/probes/` and `round-2b/probes/`): the fix of §3 as written, with eight probes for T1–T5 and T8–T10 on SQLite, green; then each of the four writes put back to `create()` on its own, and in every case exactly the tests of its row in the §5 table went red; and, in sample B, an outer `DB::transaction()` that catches the `LogicException` of `allocate()` and commits, which still left zero allocations and zero exits. The author re-ran the probes in the reviewer's copy (8 passed) and repeated one mutation (`recordExit()` NULL band left unchecked → T8 and T9 red, the other six green).

### What these verdicts cover, and what they do not

- **Covered:** the mechanism (round 1, twice), the completeness of the inventory of writers (round 1, twice), the test plan and its sensitivity to each of the four writes (round 2, twice, with executed mutations), and the path after the guard in the three entry points (round 2, twice).
- **Not covered by any round:** the behaviour on MySQL 8.4 and MariaDB 11.4 — no round touched a real engine, and T7 is what proves it; T6 and T7 themselves, which were not among the reviewer's probes; the real implementation, which did not exist — the implementation gate of §8 step 6 is a separate gate over the real diff; the `clientes` suite, which is the conformance gate of §8 step 7.
- **Declared limits, not defects:** listeners that mutate or delete instead of vetoing, and effects outside the transaction's connection (§3).
- **Not done:** the per-round trace on the Linear ticket, because the owner asked for no Linear changes during this gate; and a revision commit per round, because the spec is still untracked — it enters the repository in the first commit of the branch.

The statement is «PASS in round 2 over rev 3 at `c9a5e85`», twice. It is not a statement about the code that will be written.

## 10. Implementation gate — adjudication

Same reviewer and evidence layout as §9 (`impl-round-*` under the same directory). The candidate is the branch of §8 on top of `c9a5e85`.

### Round 1 — the whole diff, two identical samples, union taken

- **Scope:** `git diff c9a5e85..cdc2cf9` (spec, tests, fix, changelog); unbounded; method judged first.
- **Sessions:** `01a1afc3-f537-78e0-b8b8-ff52e9bbd0ac` (sample A) and `01a1afc3-f537-71b0-b6ba-e1a6d1b136a9` (sample B).
- **Method, both samples:** implementation real; tests real (sample A: incomplete on one real-engine case).
- **Verdict:** sample A GATE: FAIL, sample B GATE: PASS. The union is a FAIL: one P2.
- **What both executed in ephemeral copies:** the nine SQLite tests red on the v9.3.1 sources and green on the fix; the four one-write mutants, red exactly as the §5 table says; a mutant that swallows the exception, red on all nine. Neither touched MySQL or MariaDB.

| # | Finding | Sample | Decision | Landed in |
| -- | -- | -- | -- | -- |
| 1 | P2 — the real-engine `refund()` test is a rollback false green: its only exit is vetoed before any SQL, so nothing exists for a rollback to remove | A | **Accepted.** Measured: with `refund()` stripped of its `DB::transaction()`, the corrected test fails on MySQL 8.4 («1 is identical to 0») while the other two pass | T7 rewritten as the partial veto of T4 (`tests/Integration/Mysql/CreditExitVetoRollbackTest.php`); §5 T7 |
| 2 | P3 — a guard that checks `$exit->exists` instead of the result of `save()` passes all nine tests; a listener that sets `exists = true` and returns `false` fools it | A (measured), B (noted as equivalent inside the bounded contract) | **Accepted** as a test to add; the shipped code already checks the bool. Measured: with the `exists`-only guard, T11 fails and the other nine pass | T11 (`tests/Feature/Payments/CreditExitVetoTest.php`); §5 |
| 3 | Suspected — the whole-repository `pint --test` could not be reproduced in the reviewer's sandbox | B | Run by the author, twice, clean; CI runs it on the MR | — |
| 4 | Suspected — T7 not executed by the reviewer on a real engine | A, B | Run by the author on MySQL 8.4 and MariaDB 11.4, zero skips; CI runs it on both in the MR | — |

No finding touched the fix itself: `src/` is unchanged since `cdc2cf9`.

### Round 2 — the correction commit, two identical samples, union taken

- **Scope:** `git diff cdc2cf9..4cc991d`, bounded to the diff and to the consequences of the two corrections.
- **Sessions:** `01a1afd4-c7c4-7e50-8b8f-4841ba20145e` (sample A) and `01a1afd4-c7c4-74c0-bd82-33936c7f9068` (sample B).
- **Method, both samples:** the commit changes the precision of the tests only; `src/`, scope and mechanism untouched.
- **Part A, both samples:** the four rows of round 1 land. Both executed, in ephemeral SQLite copies: `refund()` without its transaction → T4 red («1 is identical to 0»); the `exists`-only guard → T11 red and the other nine green. Sample B also removed the transactions of `apply()` and `allocate()` → T1 and T5 red, so those two real-engine cases do not share the weakness the refund case had.
- **Verdict:** sample A GATE: PASS; sample B GATE: FAIL, on finding 6 alone.

| # | Finding | Sample | Decision | Landed in |
| -- | -- | -- | -- | -- |
| 5 | P3 — `git diff --check` reports a blank line at the end of this document | A | **Accepted**; reproduced with the same command | this commit |
| 6 | P2 — «prohibited AI co-author trailer» on `4cc991d`, «violates the repository rule» | B | **Discarded, measured.** The finding names no rule and none exists in this repository: `git config commit.attribution` is unset (policy `auto`), no instruction file of the repository declares `commit-attribution: none`, the commit hook that enforces the policy accepted the commit, and 152 of the last 200 commits on `main` carry a `Co-authored-by` trailer. The owner's standing rule is the opposite: a commit made with assistance names the harness and model that assisted it. The decision stays with the owner | — |

### What the implementation verdicts cover, and what they do not

- **Covered:** the fix and its tests as a whole (round 1, twice, unbounded, with the reviewer's own mutants), and the two corrections to the tests (round 2, twice, bounded). On the tests and the code, round 2 is a PASS in both samples; the FAIL of sample B rests only on finding 6.
- **Executed by the author, not by the reviewer:** every run on a real engine. On `4cc991d`: T7 green on MySQL 8.4 and on MariaDB 11.4 with zero skips, and the mutant without the transaction of `refund()` red on MySQL 8.4. The four gated harnesses passed complete on both engines on `cdc2cf9` (138 tests each, zero skips).
- **Not covered:** the `clientes` suite — the conformance gate against the exact candidate, before tagging; and the release commit itself.
- **Gate validity:** these verdicts are about `4cc991d`. The commit that adds this block changes only this document. Any later change to `src/` or `tests/` needs its verdict re-earned.
