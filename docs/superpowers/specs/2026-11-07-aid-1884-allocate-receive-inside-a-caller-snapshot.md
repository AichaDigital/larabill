---
title: receive(), allocate() and register() inside a caller transaction that has already read (AID-1884)
date: 2026-11-07
status: rev 3 — owner chose option A on 2026-11-07 («Opcion A»); scope widened to GroupedPaymentService::register() (§1). Spec gate round 1 FAIL applied; round 2 PASS over d651066..e3a5c4a (§7). Implemented (f24c8e9); implementation gate round 1 PASS over c3a37b9..68f401c, one P3 applied (§7).
base: v9.4.0 (913484b)
origin: AID-1884 (declared limit of AID-1856, spec 2026-11-06 §6.7)
---

# receive(), allocate() and register() inside a caller transaction that has already read

## 1. The defect, measured

`PaymentService::receive()` and `allocate()` run their own `DB::transaction()`. Called inside a caller's transaction, theirs is a savepoint, and every plain read they make is served from the snapshot the caller's first consistent read fixed (REPEATABLE READ, InnoDB). The row locks they take (`lockForUpdate()` on payments, the invoice, and since 9.4.0 the entries) return the latest committed row, but the sums that decide how much is left — `Invoice::amountPaid()` over the pivot, `CreditMovement::remaining()` and `ExitBands::segmentsFor()` over the exits — are plain reads and come from that old snapshot.

Measured on v9.4.0 (`913484b`) with a throwaway two-connection probe, kept at `~/.gstack/projects/larabill/gates/AID-1884/probe-v9.4.0.php` and removed from the tree:

| Sequence | MySQL 8.4.10 | MariaDB 11.4.13 |
| -- | -- | -- |
| Caller opens a transaction and makes one plain read. A second connection runs `receive()` of 60,00 allocated to a SENT invoice of 100,00 and commits. The caller then runs `receive()` of 80,00 allocated to the same invoice and commits. | Both commit; **140,00** allocated against 100,00; status `PARTIALLY_PAID` (the recomputation also read the old snapshot). | Same. |
| Same shape with `allocate()`: one TOP_UP entry of 100,00; the second connection allocates 60,00 from it to invoice A; the caller allocates 80,00 from it to invoice B. | Both commit; the entry carries **140,00** of active exits (bands `[0, 6000)` and `[0, 8000)` overlap). | Same. |
| Same shape with `GroupedPaymentService::register()`: the second connection runs `receive()` of 60,00 on a SENT invoice of 100,00; the caller runs `register()` of 100,00 for that invoice. | Both commit; **160,00** allocated against 100,00; status `PAID`. | Same. |

Without the prior read in the caller (outermost call, or a wrapper that only wraps), `receive()` and `allocate()` behave correctly: the first read of the snapshot happens after the locks. This is why the docblock of `process()` calls it «the caller's contract» and says a wrapper with no prior read «is tolerated» (`src/Services/PaymentService.php:102-109`).

**`register()` is a new finding of rev 2.** Its docblock (`src/Services/GroupedPaymentService.php:42-53`) says a caller transaction around it is «fail-safe» because the status precondition refuses the race. That held in 8.3, when a partially paid invoice was not payable through `register()`. Since **9.0.0** (AID-1778; `CHANGELOG.md:124`, and `Ledger::legacyPayableStatuses()` at tags `v8.3.0` vs `v9.0.0` — the source comments say «8.4», a working name that never shipped as a tag) `register()` accepts `PARTIALLY_PAID`: the status is read on the locked row (current), the outstanding from the snapshot (stale), so the race passes both checks and over-collects. `register()` always reads (key pre-check) before `process()`, so it is never in the tolerated class either.

`apply()`, `refund()`, `reverse()`, `reverseAllocation()`, `sellVoucher()` and `consumeVoucher()` are not affected: they call `OutermostTransaction::assert()` before any read and refuse to run above `larabill.payments.base_transaction_level` (`CustomerCredit.php:267`, `:357`; `PaymentService.php:373`, `:471`; `VoucherSaleService.php:93`, `:566`).

**Why not `process()`:** `VoucherSaleService::claim()` reads before it calls `process()` inside its own transaction (`VoucherSaleService.php:122-189`), but that request carries no allocations and no remainder entry (`ReceiveRequest.php:67-72`), so it never reaches the stale sums; the later backing locks before it reads (`VoucherSaleService.php:294-323`). Guarding `process()` would refuse that legitimate call (measured by both gate samples: the voucher happy path fails at level 2 / base 1).

## 2. Decision

The owner chose **option A** on 2026-11-07: enforce the outermost-transaction contract with the guard the package already uses. Options B (locking reads for the sums; rejected for the AID-715 gap-lock deadlocks), C (running counters on the locked rows; schema change) and D (keep it documented) were laid out in rev 1 and are not reopened. Rev 1 applied A to `receive()` and `allocate()`; rev 2 applies the same decision to `register()`, which §1 shows has the same defect. Extending it is inside the chosen option; the owner reads the extension at this rev.

**What the escape is.** Raising `larabill.payments.base_transaction_level` does not repair a stale snapshot: it only stops the guard from refusing. It is a safety opt-out meant for a harness that itself opens that level (RefreshDatabase → 1), not a way to make a wrapper with a prior read safe.

## 3. Design

1. **`PaymentService::receive()`:** `OutermostTransaction::assert(__METHOD__);` as its **literal first statement**, before `ReceiveRequest::fromReceive()` (which validates the payload, `ReceiveRequest.php:99-140`). So the guard's refusal takes precedence over a validation error.
2. **`PaymentService::allocate()`:** the same, as its literal first statement, before `$appliedAt ??= now()` and the amount scaling.
3. **`GroupedPaymentService::register()`:** the same, as its literal first statement, before the key derivation and its own `DB::transaction()`. `process()` then runs after that transaction has returned, **at the caller's ambient level**: `0` in production (physical commit), the harness base level in a test suite (Laravel commits only at level 1 and a nested completion only decrements the counter, `ManagesTransactions.php:49-74,154-180`). The guard does not make register()'s two phases atomic: at level 0 the window between them remains, and `process()` re-locks and re-validates before writing (`PaymentService.php:147-181`), as today.
4. **Docblocks, comments and messages** — every place that states the old contract, in one pass:
   - `PaymentService::process()` (`:102-109`): drop «Not guarded … a wrapper with NO prior read is tolerated»; state that its public callers are guarded and that a direct (`@internal`) caller must be outermost or inside a guarded entry point.
   - `GroupedPaymentService::register()` (`:42-53`): drop the «fail-safe» paragraph; state the guard.
   - `PaymentService::allocate()`: keep the snapshot note, point to the guard.
   - `OutermostTransaction` class docblock: list all nine guarded methods.
   - `config/larabill.php:303-314`: list the nine methods; drop «receive() and allocate() are not guarded»; state that raising the level is an opt-out, not a repair.
   - `CreditMovement::linkAdvanceInvoice()` (`:253-261`): keep its own unenforced contract unchanged, but drop the comparison «same snapshot rule as `receive()`/`allocate()`», which stops being true.
   - `PaymentOutsideTransactionRequiredException`: the class docblock (`:13-19`) and the message of `forMethod()` (`:33`) say the rejected method «reads the ids to lock before opening its own transaction», which is false for `receive()` and `allocate()`. Generalise both to «a plain read made earlier in the caller's transaction pins the snapshot that the method's sums would read». Class, factory name and signature unchanged.
   - The «since 8.4» comments inside the docblocks this change rewrites (`PaymentService.php:122`, `:124`; `GroupedPaymentService.php:36`, `:45`, `:48`) become «since 9.0.0». `Ledger.php:135`, `:225` and `PaymentService.php:643` are outside this change and are left as they are.
5. **Exception and escape:** unchanged — `PaymentOutsideTransactionRequiredException::forMethod()` (already `@api`, `:25`) and `larabill.payments.base_transaction_level` (default `0`). No new class, no new config key, no migration. Service contract snapshots serialize signatures, not `@throws` (`tests/Support/Contract/ServiceContractSerializer.php:89-96`), so none is regenerated.
6. **`@throws`:** the three methods add `@throws PaymentOutsideTransactionRequiredException` with the same wording as `reverse()`.

## 4. Compatibility and release

- **Narrowing, declared in full.** Above the base level, the three methods now refuse **every** call, not only the ones that produced wrong money:
  - a wrapper with a prior read (the defect);
  - a wrapper with **no** prior read, correct today for `receive()` and `allocate()`;
  - an allocation-free `receive()` (credit only) inside a wrapper;
  - a **same-key replay** inside a wrapper, which today returns the original payment (measured by both samples: `same-payment` → `PaymentOutsideTransactionRequiredException`);
  - and the **precedence of errors** changes: an invalid payload inside a wrapper now surfaces as `PaymentOutsideTransactionRequiredException`, not `GroupedPaymentValidationException`.

  The consumer's way out is to call them outside its transaction; raising `base_transaction_level` only turns the protection off (§2). Constitution §8: the compatible options (C keeps every pattern working) were evaluated and the owner chose A; §9: this narrowing is approved by the owner's choice of A, and its extension to `register()` by the approval of this rev.
- **`STABILITY.md` rule 5** (`:38-40`) lets a minor change observable behaviour as a fix documented under **Fixed** with old and new behaviour stated. The CHANGELOG entry of 9.5.0 puts this under **Fixed**, in bold, with the measured over-collection as the defect, each refused case above as «before / now», and the escape described as an opt-out. Precedent: 8.3.0 declared the same guard on `GroupedPaymentService::reverse()` as a narrowing in a minor (`CHANGELOG.md:169,177`; `governance/changes/2026-10-25-larabill-8-3-0-release.md`, «Límite 1»). `docs/api-surface.md:7` («changed semantics → major») is read together with rule 5, as in 8.3.0; the release record states it.
- **`clientes`** (`origin/main` `6a34fee1`): `receive()` from `app/Livewire/Invoices/InvoicePayments.php:363` and `allocate()` exposed in `app/Services/Payments/LarabillPaymentRecorder.php:52`, with no `DB::transaction()` around either; `register()` from `app/Services/InvoicePayment/SettleInvoicePayment.php:1076`, reached from the queued listener `app/Listeners/Via1/HandleStripeWebhook.php:85` (`ShouldQueue`, no wrapping transaction; the class's own transactions in `openForWork()`/`applySettlement()` do not enclose `settleGroup()`). Its tests set `base_transaction_level = 1` (`tests/TestCase.php:49`). Not affected in production; the conformance run (§6) confirms it.
- **ArabeConAicha:** no call to these methods to break (it does not use larabill payment code yet). The consumer-data notice this line cited when written was retired on 2026-11-08 (CONSTITUTION §13); it no longer applies.
- **Version:** **9.5.0**. «Ships migrations: no».

## 5. Tests

Levels named explicitly: **production level 0**; the package SQLite suite runs every test inside a harness transaction at **level 1** with `base_transaction_level = 1` (`tests/TestCase.php:91-95`, `tests/Pest.php:53-55`), so an extra `DB::transaction()` in a test is at **level 2**. Tests that need level 0 run with the harness transaction closed or on the MySQL harness without it.

**Regressions (each red on v9.4.0):**

- **S1** (SQLite) each of `receive()`, `allocate()`, `register()` called inside an extra `DB::transaction()` (level 2, base 1) → `PaymentOutsideTransactionRequiredException` whose message names that method and «transaction level is 2, the allowed base level is 1»; nothing written (payments, pivot, credit movements, invoice status unchanged).
- **S3** (MySQL 8.4 and MariaDB 11.4, two connections, base 0) the three sequences of §1, with this oracle: (i) the second connection's call **returns** and its commit is observed before the caller's call; (ii) the caller's call throws exactly `PaymentOutsideTransactionRequiredException` naming the method, level 1 and base 0; (iii) any other throwable — lock wait timeout, deadlock (`QueryException`), validation, a generic `RuntimeException` — **fails** the test; (iv) after the caller rolls back, a fresh read (new transaction) shows the invoice and the entry at the second connection's values only. Sensitivity: with each guard removed, the corresponding case must fail on the **monetary invariant** (over-allocation / over-consumption), not on another exception.
- **S5** (placement) a structural test parses the three methods (`nikic/php-parser`, present in `vendor/` transitively through the dev toolchain; if the TDD prefers no transitive dependency, `ReflectionMethod` + the method's source lines from `getStartLine()` with `token_get_all()` is the fallback) and asserts that the first statement of each body is the static call `OutermostTransaction::assert(__METHOD__)`. Plus one behavioural precedence test: `receive()` with an invalid amount inside an extra wrapper throws `PaymentOutsideTransactionRequiredException`, not `GroupedPaymentValidationException`. A query listener asserting no query before the exception stays as a **query-silence** check; it is not offered as proof of placement (both samples moved the guard after the request build and it still passed).
- **S6** (SQLite) a same-key replay of `receive()` inside an extra wrapper throws `PaymentOutsideTransactionRequiredException` (pins the declared replay narrowing).

**Preservation controls (green on v9.4.0 and after):**

- **S2** (SQLite) with `base_transaction_level` raised to 2, the same three calls inside an extra wrapper run as today (one happy path each) — pins that the escape still works.
- **S4** `sellVoucher()` → `process()` still works at level 1 (harness) and at level 0; `register()` reaches `process()` at the caller's ambient level, asserted by a spy at level 0 and at level 1. Sensitivity: with a guard added to `process()`, the voucher case must go red.

**Existing tests to change, never weakened:**

- `tests/Feature/Payments/OutermostTransactionGuardTest.php:93-99` («leaves receive() and allocate() unguarded inside a wrapper with no prior read») pins the old contract on purpose; it is rewritten to assert the refusal (it becomes part of S1).
- `tests/Integration/Mysql/AdvanceBackedCreditRaceTest.php:282,320-321`, dataset `allocate-in-wrapper`: the wrapper is removed or the case is asserted to refuse; its race coverage stays with the unwrapped `allocate` case.
- Any other call of the three methods under an extra wrapper is located (`rg` for `DB::transaction` / `beginTransaction` around them in `tests/`) and listed in the implementation MR with what was done to each.

## 6. Delivery

Spec gate round 2 (bounded to this rev's diff) → TDD → SQLite suite, four gated harnesses on both engines, pint twice, PHPStan level 8, antipatterns audit → implementation gate → conformance of the exact candidate against `clientes` in a disposable clean clone (`path` repo with `symlink: false` pointing at a clean clone of the candidate) → MR, merge, release 9.5.0 with its release record, each with the owner's word.

## 7. Gate adjudication

**Spec round 1** — two samples of the same prompt over `d651066` (rev 2), Codex read-only: `01a1b5bf-bd48-7930-94c0-5ea6320f90e7` (A) and `01a1b5bf-bd48-79d0-b62f-81626ecaf7a1` (B), 2026-11-07 12:23-12:32. Both: method (a) and (b) **real**, (c) S5 **cosmetic** as placement proof; `GATE: FAIL` on proof and declarations only; neither asks to change option A or the minor release. Probes copied to `~/.gstack/projects/larabill/gates/AID-1884/spec-round-1{,b}/probes/`. Every finding re-measured by the author against this tree before adjudication.

| # | Finding (sample) | Decision | Landed in |
| -- | -- | -- | -- |
| 1 | P1 — S5's query listener passes with the guard after the request build / key derivation (A1, B1) | Accepted | §3.1-3.3 «literal first statement»; §5 S5 structural + precedence |
| 2 | P1 — S3 «throws + unchanged» accepts an unrelated refusal (A2, B2) | Accepted | §5 S3 oracle (i)-(iv) and sensitivity on the monetary invariant |
| 3 | P2 — `process()` runs at the ambient level, not always 0; harness levels unnamed; `OutermostTransactionGuardTest.php:93-99` and `allocate-in-wrapper` conflict (A3, B3) | Accepted | §3.3; §5 levels paragraph, S1/S2/S4, «Existing tests to change» |
| 4 | P2 — narrowing understated: replay, empty wrapper, allocation-free receive, error precedence; the escape is an opt-out, not a repair (A4, B4) | Accepted | §2 «What the escape is»; §4 narrowing list; §5 S6 |
| 5 | P2 — `STABILITY.md:38-43` and `api-surface.md:7` wording not addressed (B4) | Accepted | §4 rule 5 paragraph |
| 6 | P3 — docs rewrite misses `config/larabill.php:303-314`, `CreditMovement.php:253-261`, exception docblock and message (A5, B5) | Accepted | §3.4 |
| 7 | P3 — S2/S4 are preservation controls, not red on v9.4.0 (A3, B6) | Accepted | §5 split «Regressions» / «Preservation controls» |
| 8 | P3 — «since 8.4» should be 9.0.0 (A6, B7) | Accepted | §1; §3.4 last bullet |
| 9 | SUSPECTED — engine S3 not rerun by the reviewer (B) | Accepted as a delivery obligation | §5 S3 runs on both engines in the TDD |

**Spec round 2** — bounded to `git diff d651066..e3a5c4a`, two samples of the same prompt, Codex read-only: `01a1b5c9-394e-7952-9b9f-19aa292f67a3` (A) and `01a1b5c9-394e-7a52-9a7f-ba6b29cc1789` (B), 2026-11-07 12:33-12:40. Both: rev 3 changes the proof method, not the design; every new mechanism (S5 structural + precedence, S3 oracle, level arithmetic, S6, exception message) **real**; all nine rows of the table above **land**; VERIFIED none, SUSPECTED none; `GATE: PASS`. Both executed the round-1 mutants (late guard, timestamp-only, `RuntimeException`, synthetic lock-wait `QueryException`, guard in `process()`) in a temp copy and saw each fail the corresponding rev-3 test; both confirmed level 0 is reachable in the SQLite harness by committing the harness transaction and setting base 0. Probes copied to `~/.gstack/projects/larabill/gates/AID-1884/spec-round-2{,b}/probes/`. Both note, without raising it as a finding, that the AID-1856 spec still states the old contract at `:97,171`; it is a version-bound historical document and is left as it is.

**Coverage.** Round 1 audited the whole artifact: the guard's placement and its reach over every supported entry point that feeds the stale sums (no other supported writer found; `process()`, `Ledger` and `recomputeCollectionState()` are `@internal`), plus the test plan and the compatibility declaration. Round 2 audited only the diff that applied round 1. The «path after the guard» is today's v9.4.0 code, unchanged by this design and covered by the AID-1856 gates; this spec adds no write after the guard. What **no** round executed: the engine sequences of §1 and S3 on MySQL 8.4 and MariaDB 11.4 (author-measured on v9.4.0; owed in the TDD with monetary sensitivity per guard) and the consumer conformance against `clientes`. PASS in round 2 over `d651066..e3a5c4a`.

**Implementation round 1** — over `c3a37b9..68f401c` (the TDD commit `f24c8e9` plus the docblock fix `68f401c`), two samples of the same prompt, Codex read-only: `01a1bf05-f062-7190-9704-33ffe77a08ee` (A) and `01a1bf05-f062-74b0-89cb-25f759194f01` (B), 2026-11-09 07:36-07:44. Both: (a) the three guards **real** (literal first statements; named arguments, defaults and container resolution add no earlier read, measured with zero queries); (b) the S5 structural test **real** (late-request, late-timestamp, late-key and read-before-guard mutants all red); (c) the S3 engine oracle **real by reading** (plain read before the second connection, money asserted before the exception, explicit skips without configuration). Both executed the author's claims in a temp copy: on the `src/` of `c3a37b9` exactly 9 of the 26 focused tests fail (S1 ×3, S5 structural ×3, precedence, S6, the rewritten refusal case) while S2 and S4 stay green; a guard in `process()` turns the voucher S4 case red; full suite 2123 passed, 113 skipped. Both also verified that S2 is not a no-op (10000 allocated, invoice `PAID`, entry remainder zero) and that no internal caller in `src/` calls the three methods from its own transaction. `GATE: PASS` in both. Probes copied to `~/.gstack/projects/larabill/gates/AID-1884/impl-round-1{,b}/probes/`.

| # | Finding (sample) | Decision | Landed in |
| -- | -- | -- | -- |
| 1 | P3 — the `process()` docblock says a same-key replay «never reads invoices»; it reads them (`Ledger::lockInvoices()` at `PaymentService.php:160` runs before the replay pre-check, and `loaded()` reloads the relations). Pre-existing since 9.0.0 (`8a1c99f6`), not introduced here (A1, B1) | Accepted — measured by the author: the replay path (`replay()` → `loaded()`) writes nothing, but the lock reads the invoices | `PaymentService::process()` docblock: «locks the invoices like any call, but skips the payability and outstanding checks and writes nothing» |

**Coverage of the implementation gate.** Round 1 audited the whole diff: guard placement and reach, the proof of each test (with executed mutants), the internal callers, the tests that changed, isolation, and every declaration (docblocks, exception message, config comment, CHANGELOG). The path after the guard is the v9.4.0 code, unchanged by this diff. What **no** round executed: S3 and its monetary sensitivity on MySQL 8.4 and MariaDB 11.4 (the author ran the four gated harnesses on both engines in throwaway containers, 153/153 each; the reviewers only read them) and the consumer conformance against `clientes`, which is the next delivery gate. PASS in implementation round 1 over `c3a37b9..68f401c`.
