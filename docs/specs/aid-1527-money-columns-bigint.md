# Spec — AID-1527: money columns int → bigint + AEAT numeric domain

> Status: APPROVED for TDD — adversarial gate closed at round 6 (verdict PASS;
> session 01a14e71-3e64-76d1-ad2f-4606d9907a24; rounds r1-r6 reviewed
> fe176f3, 82bbb90, 4d4ad67, 058250e, b9af2ca, 88729c1). Round-6 regression
> probes: 18 rounding/precision checks across precision=6/14/17, six
> fractional compatibility checks, 3,000 seeded old-domain cases with zero
> differences. Every finding across the six rounds measured and adjudicated
> in §6/§6-bis/§6-ter/§6-quater/§6-quinquies/§6-sexies.
> Reviewed-by: Codex <noreply@openai.com>.
> Ticket: AID-1527. Unblocks clientes AID-1354.

## §1 Scope and column inventory

Money columns are declared `->integer()` (signed int, ceiling 2,147,483,647
cents = 21,474,836.47 €). No later ALTER touched any of them. No index, unique
or foreign key references any of them — the ALTER is index-neutral. The
migration widens to **signed `bigInteger`** (negatives MUST be preserved:
rectificatives/credit lines carry negative amounts).

Widen (the migration's ALTER list — **18 columns across 9 tables**):

| Table | Columns | Declared in |
|---|---|---|
| `invoices` | `taxable_amount`, `total_tax_amount`, `total_amount` | `2024_12_01_000003_create_invoices_table.php:55-57` |
| `invoice_items` | `quantity`, `unit_price`, `taxable_amount`, `total_tax_amount`, `total_amount` | `2024_12_01_0004_create_invoice_items_table.php:27,31-35` |
| `article_prices` | `price` | `2025_01_20_000004_create_article_prices_table.php:26` |
| `article_overrides` | `custom_price` | `2025_01_20_000002_create_article_overrides_table.php:28` |
| `article_service_status` | `effective_price` | `2025_01_20_000003_create_article_service_status_table.php:50` |
| `articles` | `cost_price` (nullable) | `2025_01_20_000001_create_articles_table.php:29` |
| `commissions` | `min_amount`, `max_amount` (nullable), `rate` (dual-purpose, G1) | `2025_01_25_000006_create_commissions_table.php:32,44-45` |
| `eu_sales_thresholds` | `total_amount` (default 0), `threshold_amount` (default 1000000) | `2026_02_16_000003_create_eu_sales_thresholds_table.php:22-23` |
| `article_guarantee_operations` | `amount` | `2026_10_09_000007_add_guarantee_capability_and_registry.php:83-85` — added to the ticket's list: money-shaped, same cast |

Already bigint, NOT widened (gate round 1, finding 5 — the package already
made the per-document vs aggregate distinction in June 2026):
`grouped_payments.amount` (`2026_06_27_000001:20`, "aggregate of N invoices
can exceed a 32-bit int"). Its pivot detail `grouped_payment_invoice.applied_amount`
(`2026_06_27_000002:19`, already bigint) is NOT widened either, but IS
AEAT-bounded — see the matrix below (round-2 correction: it is a per-document
copy of one invoice's total, `GroupedPaymentService.php:73-79`, not an
aggregate).

Percentage columns stay `integer` deliberately (CLAUDE.md: `tax_rates.rate` is
base-100 of a %, núcleo fiscal, deliberately out of scope): `tax_rates.rate`,
`tax_categories.default_rate`, `vat_categories.vat_rate`,
`country_vat_rates.standard_rate`.

## §2 The numeric domain (S5/S8) — a PER-COLUMN matrix, not one blanket bound

Gate finding 5 (P1, verified): a per-document bound is not an aggregate bound.
`eu_sales_thresholds.total_amount` ACCUMULATES sales across transactions and
countries — two individually valid amounts can legitimately exceed the AEAT
document bound; capping it would reject valid accumulation. The domain is
therefore classified per column:

| Class | Columns | Domain |
|---|---|---|
| **Fiscal document amounts** (one document's money) | `invoices.*` (3), `invoice_items.unit_price/taxable_amount/total_tax_amount/total_amount`, `article_prices.price`, `article_overrides.custom_price`, `article_service_status.effective_price`, `articles.cost_price`, `commissions.min_amount/max_amount`, `article_guarantee_operations.amount` | **AEAT symmetric**: `\|cents\| ≤ 99_999_999_999_999` (12+2 digits). Cast guard + engine CHECK. |
| **Accumulated / policy values** | `eu_sales_thresholds.total_amount` (accumulates N valid amounts), `eu_sales_thresholds.threshold_amount` (business policy input), `grouped_payments.amount` (aggregate) | **No AEAT cap** — bigint column bound only, documented. An accumulator of valid documents has no per-document ceiling. |
| **Per-document payment pivot** | `grouped_payment_invoice.applied_amount` (already bigint; copies ONE invoice's total) | **AEAT symmetric CHECK added in this migration** (no widening needed) — round-2 correction: "it is an aggregate" was not evidence-supported; the value is per-document, the cast cannot intercept relation inserts (`InteractsWithPivotTable.php:336-346`), so the engine clause is its only real guard. |
| **Quantity** (`invoice_items.quantity`, ×100) | widened, **no AEAT cap** (G2): a scale-2 quantity is not money; the money columns it feeds are bounded. Line-total math is SAFE: FixedDecimal multiplication is arbitrary-precision and its int extraction throws typed (`InvalidFixedDecimal`) — verified by the round-2 probes | bigint column bound only. |
| **Dual-purpose `commissions.rate`** (G1) | widened WITH the AEAT symmetric bound — a base-100 percentage value (even 999999 %) can never approach 1e14, so the bound never bites % semantics, and it protects FIXED rows (which ARE money: `src/Models/Commission.php:143-148`) | AEAT symmetric. |

- Zero is valid everywhere (AID-956: cero es un precio válido).
- Negatives: symmetric bound preserves rectificative/credit semantics
  (`SettlementService.php:347-355`); the EXISTING operation-specific sign
  rules stay untouched (`GuaranteeService.php:118-121` positive-only).

### §2.1 The cast: composition, not inheritance (gate finding 1, P1, verified)

`FixedDecimalCast` is `final` (`vendor/aicha.../lara100/src/Casts/FixedDecimalCast.php:25`)
— the spec's original subclass cannot load. New `@api` surface:

```php
// src/Casts/AichaMoneyCast.php — final class, implements CastsAttributes
final class AichaMoneyCast implements CastsAttributes
{
    public bool $withoutObjectCaching = true; // gate finding 4, P1, verified

    public function __construct(
        private readonly int $scale = 2,
    ) {}
    // NO domain-toggle parameter: Laravel passes cast parameters as STRINGS
    // (HasAttributes.php:1927-1942) — round-2 probe: 'AichaMoneyCast:2,false'
    // does NOT produce a false flag. This cast ALWAYS enforces, and it is
    // applied ONLY to AEAT-class columns; non-AEAT columns keep the original
    // FixedDecimalCast:2 (no widening or ambiguity).

    public function get($model, string $key, $value, array $attributes): ?FixedDecimal
    {
        return (new FixedDecimalCast($this->scale))->get($model, $key, $value, $attributes);
    }

    public function set($model, string $key, $value, array $attributes): ?int
    {
        $cents = (new FixedDecimalCast($this->scale))->set($model, $key, $value, $attributes); // NORMALIZED to scale 2 (finding 3, P1, verified: a scale-0 VO of '1e12' normalizes to 1e14 cents)
        if ($cents !== null) {
            MoneyDomain::assertValidMoneyCents($cents);
        }
        return $cents;
    }
}
```

- **Normalization before validation** (finding 3): the guard sees the
  SCALE-2 storage cents, never the input VO's original scale.
- **`$withoutObjectCaching = true`** (finding 4): Eloquent caches the VO and
  re-invokes `set()` while merging cached attributes during
  `toArray()`/`getAttributes()` — without disabling object caching, an
  innocent read of a legacy over-domain row (bulk-written, pre-widening)
  throws on SERIALIZATION. Measured by the gate: first read OK,
  `toArray()` DomainException without the flag, OK with it. The flag keeps
  `get()` unguarded AND keeps the write guard effective on assignment.
- Rejection surfaces: `MoneyDomainOverflowException` (`@api`) on Eloquent
  ASSIGNMENT paths (model writes through the cast); on BYPASS paths
  (`where()->update()`, `upsert()`, raw SQL, relation pivots) the engine CHECK
  raises Laravel's `QueryException` — both documented, neither rewritten
  (round-2 correction of the §5 wording).

### §2.2 `MoneyDomain` (`src/Support/MoneyDomain.php`, `@api`, final)

```php
final class MoneyDomain
{
    public const MAX_MONEY_CENTS = 99_999_999_999_999; // AEAT 12+2 digits
    public static function isValidMoneyCents(int $cents): bool;   // symmetric
    public static function assertValidMoneyCents(int $cents): void;
}
```

### §2.3 Engine CHECKs are MANDATORY on supported engines (gate finding 2, P1, verified — G3)

Assignment casts are NOT database invariants. The gate executed probes with
zero cast invocations through `Model::where()->update()`,
`Model::upsert()`, `setRawAttributes()->save()`, raw `DB::table()` writes and
the grouped-payment pivot insert (`GroupedPaymentService.php:73-79`,
`InteractsWithPivotTable.php:336-346`). Therefore:

- The SAME migration adds named CHECK constraints per AEAT-class column:
  `CHECK (col BETWEEN -99999999999999 AND 99999999999999)` (MySQL/MariaDB
  named CHECKs, ADR-016 §8.2 precedent).
- SQLite cannot ALTER-add a CHECK: parity via the application-layer enforcer
  is NOT raw-write parity and is DOCUMENTED AS SUCH (the §8.2 honest limit,
  verbatim style: bulk/SQL writes on SQLite remain outside the guarantee;
  on MySQL/MariaDB the CHECK closes it).
- Non-AEAT classes (accumulators, quantity) get NO CHECK.

## §3 Migration machinery (S4/S7) — corrected numbers

One new migration `2026_10_18_000001_convert_money_columns_to_bigint.php`
(named `convert_money_columns_to_bigint`). **DDL execution is DELIBERATE and
hand-written** (round-2 finding 4, P2, verified: Laravel compiles ONE statement
per `->change()` column — `MySqlGrammar.php:397-419` — it does NOT group them;
the spec's original claim was false):

1. `.php`: **one grouped `DB::statement('ALTER TABLE ... MODIFY col bigint ..., ADD CHECK ...')` per table** (hand-written SQL, the deliberate operational unit), covering all §1 columns preserving nullable/default/comment; named CHECK clauses for the AEAT class incl. `grouped_payment_invoice.applied_amount` (§2.3);
   **resumable at POSTCONDITION level, not table level** (round-3 finding 3,
   P2, verified — the pivot makes mixed states immediate: its column is
   ALREADY bigint while its CHECK is missing, so skip-on-column-type would
   lose the enforcement and replay would double-add): introspect EACH required
   column/constraint independently, build ONLY the missing clauses into the
   grouped statement, skip the table only when all its postconditions hold:

   | Existing state | Remaining work |
   |---|---|
   | Int columns, no CHECK | MODIFY + ADD CHECK |
   | Bigint columns, no CHECK | ADD CHECK only |
   | Int columns, CHECK present | MODIFY only |
   | Everything satisfied | Skip |
   | Mixed within a table | Only the missing clauses |

   Plus a LOUD PREFLIGHT before any DDL (ADR-016 preflight pattern): a
   read-only count of rows exceeding the AEAT bound per AEAT-class column —
   especially `grouped_payment_invoice.applied_amount`, which was ALREADY
   bigint on standard schemas (round-3 SUSPECTED: over-domain pivot rows are
   possible on standard consumers, unlike the old-int columns) — aborting the
   migration with per-column counts and the repair path when non-zero.
   (Laravel records completion only after `up()`; grammar is non-transactional
   DDL — `Migrator.php:253-258,448-451`.) `down()` throws loud (rollback
   after widening is impossible without data loss once values exceed int —
   documented, never silent).
2. `.stub` byte-identical (`bin/sync-migration-stubs`), `$migrationOrder`
   gains `'052' => 'convert_money_columns_to_bigint'` — **48 existing entries**
   (keys '001'..'051', gaps '020','024','025' — round-1 correction of my
   52-entry claim), `LarabillInstallCommand.php:28-97`.
3. `InstallCommandSchemaTest.php:91`: applied count 48 → 49 (round-1
   correction of the :96 locator); table/FK assertions unchanged.
4. Manifest regen (`bin/sync-upgrade-manifest`); stamp + fixture travel
   together (v8.1.0 lesson).
5. CHANGELOG `[Unreleased]` BEFORE any snapshot regen (AID-601).

**Upgrade-path consequence:** post-release `upgrade_base` = v8.1.0 with this
migration as the only non-`in_base` entry. The int-era seed cannot hold values
above int-max — upgrade assertions are integrity round-trips; the
above-int-max proof lives in §4 conformance, not in the upgrade seed.
(The two grouped-payments bigint columns are already bigint at base v8.1.0 —
the upgrade fixture's "all money columns are integer" wording must not claim
that; round-1 correction.)

**DDL execution notes (round-1 finding 7, P2, accepted; round-3 corrected):**
Laravel compiles per-column `modify` statements — it does NOT group them
(`Blueprint.php:199-205`, `MySqlGrammar.php:397-419`), which is why §3
prescribes deliberate hand-written grouped `DB::statement` per table. MySQL
8.4 type changes require COPY (no concurrent DML); MariaDB documents online
COPY with FK exceptions and brief exclusive locks — NOT identical locking on
both engines (verified against primary docs by the reviewer). UPGRADE/CHANGELOG
note must state: rebuild per table, time scales with rows, maintenance ritual
as in UPGRADE-8.0, and recovery = re-run (resumable by introspection).

## §3-bis Arithmetic conformance (round-1 finding 6 + round-2 findings 1-2 — IN SCOPE)

The widened range EXPOSES integer/float arithmetic that is wrong or overflows
at magnitudes now legal. THREE sites, one discipline — EXACT arithmetic, with the rounding API stated
EXECUTABLY (round-3 finding 2, P2, verified: lara100's enum is
`RoundingMode::HalfUp` — `vendor/aichadigital/lara100/src/RoundingMode.php:13-21`
— Brick's `dividedBy` accepts only Brick's enum, and the two are NOT
interchangeable; both mis-calls were executed as errors). For fixed-scale
cent math, use FixedDecimal operations carrying lara100's
`RoundingMode::HalfUp` (PHP `round()` = half-away-from-zero — the semantics
MATCH, round-3 verified). For raw BigDecimal intermediates, use Brick's own
case (`Brick\Math\RoundingMode::HalfUp`, `vendor/brick/math/src/RoundingMode.php:59`).
Never mix the two enums.

1. **`VatCalculationStrategy.php:19-29`** — computes
   `(int) round($baseAmount * ($rate / 10000))` in FLOAT. The public contract
   takes `float $baseAmount` (`TaxCalculationStrategy.php:20`) — do NOT
   casually narrow it. **Normalization must be precision-INDEPENDENT**
   (round-3 finding 1, P1, verified: `(string) $baseAmount` depends on PHP's
   `precision` ini — at `precision=6` the probe turned base=123456789 into a
   WRONG result INSIDE the old domain, a new regression; and a fractional
   float `123456789.499999` formats to `'123456789.5'` at the default
   `precision=14`, changing today's result. Note `FixedDecimal::ofFloat()`
   shares the `(string)` trap — `FixedDecimal.php:66-70` — and is NOT a fix).
   Exact form, FOUR branches classified IN ORDER, before any int cast
   (round-4 finding, P1, verified: the `== (int)` classification's false
   branch is NOT only fractional — it also captures NAN/INF and integral
   floats beyond int range, where the legacy expression WRAPS TO ZERO
   silently: probe base=(float)PHP_INT_MAX rate=10000 → tax
   -9223372036854775808; base=2^64 → 0; NAN/INF → 0; and a genuinely
   fractional in-widened input base=92233720368547.77 rate=2000000000 →
   wrapped 0, which no amount-bound CHECK can reject.
   `FixedDecimal.php:169-174`'s checked extraction cannot help — this branch
   bypasses it):

   - **Non-finite** (`is_nan || is_infinite`): loud `InvalidArgumentException`
     naming the input — invalid money, never a rounding question.
   - **Magnitude cutoff, BEFORE any integer cast** (round-5 finding 1, P1,
     verified: `2^53`, `2^53 + 2` and `-2^63` all cast to EXACT ints and
     slipped through the exact branch when the cutoff came after the cast):
     `abs($baseAmount) >= 2**53` → loud `InvalidArgumentException`. This is a
     CONSERVATIVE CUTOFF, not a claim that every float above 2^53 is inexact —
     the contract refuses to guess where exactness ends.
   - **Integral float** (`$baseAmount == (int) $baseAmount` — only reachable
     below the cutoff, and the int cast is precision-independent there):
     normalize via `(string) (int) $baseAmount`, then `BigDecimal::of($normalized)`
     × `BigDecimal::of($rate)` ÷ `10000` with HALF-AWAY-FROM-ZERO (PHP
     `round()` semantics), materialized through the lara100 path so an
     out-of-int result throws the typed `InvalidFixedDecimal`. `-0.0` lands
     here → 0.
   - **Fractional float** (a legacy surface the contract admits): keep the
     historical float expression VERBATIM for the value, but CHECK the output
     boundary before the final `(int)` cast against the NATIVE int interval
     with an EXCLUSIVE upper bound (round-5 finding 2, P1, verified:
     `abs($result) <= PHP_INT_MAX` promotes PHP_INT_MAX to 2^63 in the float
     comparison, so a rounded result of exactly 2^63 passed the guard and
     `(int)` wrapped it to −9223372036854775808; boundary probes rejected
     +2^63 and accepted −2^63 and 2^63−1024):
     `is_finite($result) && $result >= (float) PHP_INT_MIN && $result <
     -(float) PHP_INT_MIN` or loud `InvalidArgumentException`. The golden
     master then pins old == new for every input class in the old domain —
     where no branch ever fires its guard — both branches included.

   The rate column has NO ≤10000 ceiling (round-2 verified) — the loud typed
   overflow is the correct contract for absurd rates.
2. **`SettlementService.php:523-529`** — repeats the float formula; same fix.
3. **`SettlementArithmetic.php:93`** — `intdiv(2 * $base * $unconsumedDays +
   $totalDays, 2 * $totalDays)`: the product overflows int with legal bigint
   inputs (round-2 probe: base = MAX, 2000→2200 period → TypeError). Exact
   form via BigDecimal; the intermediate `2 * base * days` needs arbitrary
   precision (2e14 × 73k days ≈ 1.5e19 > PHP_INT_MAX). **Semantic note:** the
   combined form `(2·b·u + t) / (2t)` is a rounding TRICK — with `intdiv` it
   truncates TOWARD ZERO on the combined numerator (round-3 verification:
   this is exactly PHP `round()` half-away-from-zero for POSITIVE bases, and
   asymmetric for negatives). The BigDecimal replacement reproduces the
   combined form with truncation-toward-zero division
   (`RoundingMode::Down` on the exact quotient) so legacy behavior is
   bit-identical for every sign in the old domain — the golden master is the
   proof, not prose.

Golden-master obligation: for a sampled set of in-int-range inputs across all
three sites, the new implementation returns EXACTLY the old values — INCLUDING
fractional-float inputs and across `precision` ini settings (round-3: the
stated pins alone did NOT detect the string-formatting defect; the pin set
must run with `ini_set('precision', 6)` as a sensitivity mutation target).
Conformance additions (RED first):
`base=80000000000007, rate=2100 → 16_800_000_000_001` (the round-1 probe);
signed HalfUp pins `base=50 → 11`, `-50 → -11`, `99 → 21`, `-99 → -21`
(round-2: a naive intdiv mutant passes the large case alone and fails these —
the pin set exists to kill it; round-3 sign semantics VERIFIED correct:
50→11, -50→-11, 99→21, -99→-21 with the properly-invoked implementation, and
10,000 sampled signed in-range inputs at 21% matched the current behavior
under precision=14); long-period large-base `unconsumedBase()` case;
round-4 classification pins: NAN/INF/-INF → loud, integral 2^63 and 2^64 →
loud (not a silent 0), fractional large in-widened case
(`92233720368547.77` × rate 2e9) → loud (not a wrapped 0), `-0.0` → 0 via the
exact branch, `2^53` / `2^53+2` / `-2^63` inputs → loud via the PRE-cast
magnitude cutoff (round-5: they cast to exact ints and bypassed the
post-cast cutoff), and the fractional output boundary: rounded result
exactly 2^63 → loud (float promotion of PHP_INT_MAX — the exclusive upper
bound), −2^63 and 2^63−1024 → accepted by the checked conversion — each
verified by the gate's probes as silently wrong today.
INDEPENDENT settlement-path conformance (through
`SettlementService::differenceItems()` with a frozen-tax snapshot), not only
the VAT strategy (round-2 sensitivity probe B).

## §4 Tests (S6) — boundary matrix per column class

- **`MoneyColumnTypesTest.php` (extend — round-1 correction: it today covers
  only `commissions.rate` + the two threshold amounts, `:17-27`):** per-column
  `DATA_TYPE` assertions for ALL §1 columns + CHECK presence for the AEAT
  class, on MySQL 8.4 and MariaDB 11.4 (driver matrix, zero skips).
- **Conformance (both engines):** every AEAT-class column at exactly
  ±99_999_999_999_999 persists and reads integral; one cent beyond is
  REJECTED by the CHECK (raw DB insert probe — proves the engine clause);
  above-int-max (e.g. 3 × 2_147_483_648) persists integral; zero persists.
- **Cast-guard tests (SQLite suite):** `MAX`, `MAX+1` → typed exception,
  `-MAX`, `-MAX-1` → typed exception (round-1 mutation caught a naive
  `> MAX` check accepting `-MAX-1`), zero, cross-scale input
  (`FixedDecimal::ofDecimalString('1000000000000')` → normalized 1e14 →
  REJECTED — round-1 probe), rounding-boundary inputs (x.x5 at scale 3),
  bypass-path probes (`where()->update()` and `upsert()` on MySQL harness:
  accepted in-range, rejected by CHECK out-of-range — and the rejected
  failure is the `QueryException`, not the domain exception, per §2.1),
  unguarded factory creation (casts still apply — round-1 confirmed they
  do), real relation `attach()` on the payment pivot against the
  `applied_amount` CHECK (round-2: raw attach stored 1e14 unguarded today).
- **Arithmetic (§3-bis):** the full pin set above (signed HalfUp
  boundaries INCLUDED — the large case alone accepts a truncating mutant),
  golden-master equivalence, independent settlement-path conformance.
- **Legacy-read sequence (finding 4):** a forced-raw over-domain row reads
  via property access AND survives `toArray()`/`getAttributes()` (the
  `withoutObjectCaching` regression test).
- **Arithmetic (§3-bis):** real-strategy RED→GREEN at the probe's case.
- **Upgrade-path:** base v8.1.0 + this migration as the new slice; seeded
  int-era values round-trip; fixture wording corrected (§3).

## §5 Version, stability, release

Target **v8.2.0**. Widening is a minor under `STABILITY.md:24`; the domain
surface is additive. The enforcement changes observable behaviour: the
CHANGELOG carries an explicit **Fixed** entry (old: over-ceiling writes
silently clipped on non-strict engines / rejected at the int ceiling on
strict ones; new: typed `MoneyDomainOverflowException` on Eloquent ASSIGNMENT
paths, engine `QueryException` on CHECK-guarded bypass paths — §2.1 — plus
the migration/maintenance note). **No config escape** (gate concurs — AID-632
governs config propagation, not a blanket escape requirement). Compatibility
claim QUALIFIED (round-2 SUSPECTED 1): standard old-int columns cannot hold
over-domain values, so the widening is safe; a consumer with a hand-widened
or otherwise anomalous schema may hold over-domain rows — the CHECK added in
the same migration rejects them loudly at migrate time and the upgrade note
states the repair path; the guarantee is NOT "neutralized" for arbitrary
consumer schemas. JSON amount payloads (`taxes_applied`) stay OUT of scope,
documented as a declared limit (round-1 SUSPECTED 2). Real-engine DDL timing
and lock durations are release-harness measurements (§3), not doc claims
(round-2 SUSPECTED 2).

## §6 Gate adjudications (round 1 — verdict FAIL → all findings dispositioned)

| # | Finding (P) | Disposition | Landing |
|---|---|---|---|
| 1 | `FixedDecimalCast` is `final`; subclass cannot exist (P1) | **ACCEPTED** — measured (`:25`) | §2.1: composition `AichaMoneyCast` delegating to the cast |
| 2 | Eloquent bypasses defeat assignment guards (update/upsert/setRaw/pivot) (P1) | **ACCEPTED** — measured (zero cast calls, 5 paths) | §2.3: engine CHECKs MANDATORY on MySQL/MariaDB; SQLite parity documented as narrower; §4 bypass probes |
| 3 | Validate NORMALIZED cents, not the VO's original scale (P1) | **ACCEPTED** — measured (scale-0 '1e12' → 1e14 storage) | §2.1: guard after delegation/normalization |
| 4 | Cached VO re-invokes `set()` on serialization; legacy reads brick (P1) | **ACCEPTED** — measured | §2.1: `$withoutObjectCaching = true` + §4 legacy-read regression |
| 5 | Per-document bound ≠ aggregate bound; matrix needed (P1) | **ACCEPTED** — measured (grouped_payments precedent) | §2: per-column domain matrix; accumulators/quantity/rate classified |
| 6 | Float arithmetic off-by-one-cent at large magnitudes (P1) | **ACCEPTED** — in scope (only reachable after widening) | §3-bis: integer math + real-path conformance |
| 7 | DDL recovery/rollback/engine-lock underspecified (P2) | **ACCEPTED** | §3: resumable migration, loud `down()`, engine notes |
| 8 | Behaviour change needs STABILITY Fixed entry; strict-mode nuance; no config escape (P2) | **ACCEPTED** | §5 |
| A | Corrections: 48 entries not 52 (gaps 020/024/025); InstallCommandSchemaTest:91 not :96; MoneyColumnTypesTest covers only 3 columns today; "all money columns are integer" false (grouped_payments already bigint); stub bootstrap order | **ACCEPTED** — measured | §1, §3, §4 |
| G1 | commissions.rate | **Widen + AEAT bound** (never bites %; protects FIXED money) | §2 |
| G2 | quantity | **Widen, no AEAT cap** | §2 |
| G3 | enforcement | **Cast + mandatory engine CHECKs; SQLite honestly narrower** | §2.3 |
| G4 | migration shape | **One migration, resumable, loud down()** | §3 |
| G5 | cast ownership | **Larabill, composition** (AEAT is larabill domain; no lara100 change) | §2.1 |

SUSPECTED (from the gate, unverified — dispositions): consumer data above
domain (QUALIFIED per rounds 2-3: widening is safe on standard old-int
columns; `grouped_payment_invoice.applied_amount` was ALREADY bigint, so its
over-domain rows are possible on standard schemas — covered by the §3 loud
preflight with per-column counts and repair path); JSON
monetary payloads (declared limit, §5); engine DDL timing on real tables
(release-harness measurements, §3).

## §6-ter Round-3 adjudications (verdict FAIL — corrections applied)

| # | Finding (P) | Disposition | Landing |
|---|---|---|---|
| 1 | `(string) $float` is precision-dependent — a NEW regression inside the old domain at `precision=6`; fractional-float surface changes results; `FixedDecimal::ofFloat` shares the trap (P1) | **ACCEPTED** — measured (probes at precision 6/14) | §3-bis site 1: two-branch normalization (integral → precision-independent int path; fractional → verbatim legacy float branch) + golden master across ini settings |
| 2 | Rounding API mixed incompatible enums: lara100 `HalfUp` ≠ `HALF_UP`, Brick accepts only its own (P2) | **ACCEPTED** — measured (both mis-calls error) | §3-bis: executable API stated — FixedDecimal ops with lara100 `RoundingMode::HalfUp`; Brick intermediates with Brick's own case; sign semantics verified (50→11, -50→-11, 99→21, -99→-21) |
| 3 | Resumability needs per-clause postconditions, not table skip; the pivot is the immediate mixed state; surviving contradictory grouping statement at Spec:181-183 (P2) | **ACCEPTED** — measured | §3: per-clause introspection matrix + loud AEAT preflight; contradiction removed |
| 4 | `applied_amount` over-domain rows possible on STANDARD schemas (it was already bigint) (SUSPECTED) | **ACCEPTED** as preflight coverage | §3 preflight counts + repair path |

## §6-bis Round-2 adjudications (verdict FAIL — corrections applied)

| # | Finding (P) | Disposition | Landing |
|---|---|---|---|
| 1 | §3-bis intdiv truncates (no HalfUp); float contract; unbounded rate overflows the intermediate product (P1) | **ACCEPTED** — measured (TypeError probes; 50→10 vs 11) | §3-bis: BigDecimal + lara100 RoundingMode HALF_UP, float via exact string conversion, loud typed overflow for absurd rates |
| 2 | `SettlementArithmetic.php:93` `2*base*days` overflows with legal inputs (P1) | **ACCEPTED** — measured (TypeError at base=MAX) | §3-bis site 3 + long-period conformance; quantity×price hypothesis RETIRED (FixedDecimal multiply is safe/typed) |
| 3 | `applied_amount` is per-document, not aggregate; cast cannot reach pivots; CHECK failures are `QueryException` not the domain exception (P2) | **ACCEPTED** — measured (attach stored 1e14) | §2 matrix: AEAT CHECK added to `applied_amount`; §2.1/§5 rejection-surface wording split |
| 4 | Laravel compiles per-column ALTERs; "one ALTER per table" was false (P2) | **ACCEPTED** — measured (grammar probe) | §3: deliberate hand-written grouped `DB::statement` per table, resumable by introspection |
| 5 | Cast parameters arrive as strings ('false' is truthy) (nonblocking) | **ACCEPTED** — measured | §2.1: no toggle param; non-AEAT columns keep `FixedDecimalCast:2` |
| 6 | Inventory counts 18 columns, not 17 (nonblocking) | **ACCEPTED** | §1 |
| 7 | Single large VAT pin accepts a truncating mutant; settlement needs independent conformance (sensitivity) | **ACCEPTED** — measured (mutant probes A and B) | §4: signed HalfUp pin set + independent settlement-path test |
| 8 | Compatibility claim over-broad; DDL timing is unmeasured (SUSPECTED) | **ACCEPTED** as qualification | §5 |

## §6-quater Round-4 adjudications (verdict FAIL — one blocker, correction applied)

| # | Finding (P) | Disposition | Landing |
|---|---|---|---|
| 1 | The `(== (int))` false branch is not only fractional: NAN/INF and out-of-int-range integral floats take the legacy path and WRAP TO ZERO silently — including a genuinely fractional in-widened case; no amount CHECK can reject a zero (P1) | **ACCEPTED** — measured (five-case probe table) | §3-bis: branch classification ordered BEFORE the int cast (non-finite loud / integral-representable exact / integral-unrepresentable loud / fractional legacy with checked output boundary); §4 classification pins |
| — | The operator's `1e15` example was mislabeled (below 2^53, casts exactly) | **ACCEPTED** | this row; the 2^53 boundary statement in §3-bis stands |

SUSPECTED after round 4: none beyond the standing items (§6-ter).

## §6-quinquies Round-5 adjudications (verdict FAIL — two blockers, corrections applied)

| # | Finding (P) | Disposition | Landing |
|---|---|---|---|
| 1 | The magnitude cutoff came after the int cast: 2^53, 2^53+2, −2^63 cast exactly and bypassed the rejection (P1) | **ACCEPTED** — measured (three-case probe) | §3-bis: `abs($baseAmount) >= 2**53` rejection FIRST (conservative cutoff, stated as such) |
| 2 | `abs($result) <= PHP_INT_MAX` promotes PHP_INT_MAX to 2^63 in float comparison; positive 2^63 passed and wrapped negative (P1) | **ACCEPTED** — measured (boundary probes: +2^63 rejected, −2^63 and 2^63−1024 accepted) | §3-bis: exclusive upper bound `$result < -(float) PHP_INT_MIN` |

## §6-sexies Round-6 adjudication (verdict PASS — ready for TDD)

| # | Check | Result |
|---|---|---|
| 1 | Pre-cast magnitude cutoff landed (non-finite → ≥2**53 → int classification, in that order, conservative policy stated) | VERIFIED — executed probes: NAN/±INF/2^53/2^53+2/±2^63/2^64 all loud; −0.0 → 0 |
| 2 | Exclusive output boundary [PHP_INT_MIN, −PHP_INT_MIN) landed | VERIFIED — fractional +2^63 rejected, −2^63 and 2^63−1024 accepted exactly; exact path uses lara100's checked extraction (FixedDecimal.php:169-174) |
| 3 | Regression sweep | VERIFIED — 18 rounding/precision checks across precision=6/14/17, six fractional compat checks, 3,000 seeded old-domain cases: zero differences |

Standing obligations for the implementation and implementation-gate: the §4
test set (incl. every sensitivity pin the rounds introduced), the §3
per-clause resumability + preflight, and the release-harness engine
measurements (§3, §5). The gate expires per revision: implementation
commits need the implementation gate before merge.

## §7 Implementation gate — round 1 adjudications (verdict FAIL: 4xP2 test-sensitivity + 1 SQL fix + 1 pint; method judged REAL)

Reviewed 440fdd5 (session 01a14f20-89e2-71e3-b26e-d6450d5b6d92). Every §6*
adjudication verified LANDED by the reviewer; all five migration states
executed on both engines; the mutation campaign found test gaps, not code
gaps. Dispositions:

| # | Finding (P) | Disposition | Landing |
|---|---|---|---|
| 1 | Preflight `ABS(col) > ?` overflows BIGINT on a legacy applied_amount = -2^63 (SQLSTATE 22003/1690, both engines — fail-closed but loses the diagnostic) (P2) | **FIXED** — two-sided comparison (`col < -bound OR col > bound`), never ABS(); signed-minimum pivot fixture added to the test set | migration `preflightAeatDomain()` (orchestrator) + fixture (fix worker) |
| 2 | Per-column boundary matrix reduced to one representative column; upper-only-CHECK, pivot-tautology and Commission-cast-restore mutants survive (P2) | **FIXED** — per-column signed dataset, real pivot attach() cases, per-model cast-mapping coverage | fix worker (MoneyDomainConformanceTest, AichaMoneyCastTest) |
| 3 | Three arithmetic mutants survive: unchecked exact extraction saturates; missing fractional lower bound returns 0; the 2^63-1024 acceptance pin actually tests 2^63-2048 (P2) | **FIXED** — typed-overflow pins on both paths, fractional underflow pin, literal corrected to the promised 2^63-1024 | fix worker (TaxArithmeticConformanceTest) |
| 4 | Migration tests assert final state only: always-modify, split-DDL and silent-down mutants survive (P2) | **FIXED** — executed-DDL assertions via statement recorder: zero statements on satisfied state, ONE grouped statement per touched table, only-missing-clause emission, loud down() | fix worker (MoneyColumnTypesTest) |
| 5 | Manifest still 8.1.0/8.0.0 — §3 item 4 deferred (P2, disposition) | **DISPOSITIONED — release-time act**: the manifest regen is `bin/sync-upgrade-manifest` machinery gated by `bin/tag-release` (the v8.1.0 precedent: the stamp travels in the chore(release), with its fixture coupling verified by the upgrade test). The implementation-gate obligation is that the upgrade test derives its delta correctly WITHOUT the regen — verified. The regen happens in the v8.2.0 release chore, per §5 | v8.2.0 release chore; tracked in odd/tasks T5 |
| 6 | Pint: binary_operator_spaces on the new conformance test (P2) | **FIXED** | fix worker |
| S | Manually-widened UNSIGNED column stays unmodified and rejects -1 (SUSPECTED) | **DECLARED LIMIT** — introspection checks DATA_TYPE, not signedness; repairing anomalous consumer schemas is outside the qualified compatibility promise (§5); the CHECK still guards writes once added | this row; upgrade note may reference it |

Standing after this gate: the corrections (findings 1-4, 6) need a re-audit
round before merge; finding 5's regen is audited at the release chore.

## §7-bis Implementation gate — round 2/3: PASS (merge-ready)

Round 2 (FAIL): the signed-minimum pivot regression fixture had not landed
(the ABS() mutant survived it) and the CI pint command failed on the
migration/models — both corrected (commit 0323701: the fixture seeds
`applied_amount = PHP_INT_MIN` over a raw parent chain with the §8.5
stamped invoice shape; full-tree pint pass; stub re-derived byte-identical).

Round 3 (PASS, reviewed 0323701): the reviewer re-executed the ABS() mutant
against MySQL 8.4 — it fails with the 22003/1690 overflow instead of the
per-column diagnostic, proving the fixture kills the exact regression —
the CI pint command passes, and the stub is byte-identical.
**The implementation gate is closed; the branch is merge-ready.**
Remaining release-time obligations: MR, manifest regen in the v8.2.0
chore(release) (§7 finding 5 disposition), owner acts for merge and tag.
