# Grouped Payments (AID-30) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an accounting-layer grouped payment to larabill — one external collection that settles N already-issued invoices, with strong idempotency, full-reversal, and DB-level double-pay protection.

**Architecture:** A `GroupedPayment` UUID entity + a `grouped_payment_invoice` pivot. A framework-agnostic `GroupedPaymentService` exposes `register()` and `reverse()` (PHP methods, no HTTP). Money is `FixedDecimal:2` over integer base-100 columns. Concurrency safety = pessimistic `lockForUpdate` (ordered) + a `unique(active_invoice_id)` DB backstop + a unique `idempotency_key`. Settling an invoice is a collection-state transition (`status`+`paid_at`) done through dedicated `Invoice` methods that work on immutable invoices.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Pest, lara100 `FixedDecimal`, Orchestra Testbench (SQLite in-memory + MySQL 8 integration).

**Source spec:** `docs/superpowers/specs/2026-06-26-grouped-payments-design.md` (updated with D1-D3).

## Global Constraints

- **UUID-first (ADR-006):** consumer `users.id` is UUID v7 char(36). User FKs via `MigrationHelper::userIdColumn($table, 'col', nullable: bool)` — emits `char(36)` + **its own index**, no hard FK. Never add a second `$table->index()` on the same column (duplicate-index error). Never `$table->foreignId()`.
- **Money = `FixedDecimal:2` over integer columns (ADR-009):** cast `FixedDecimalCast::class.':2'`; DB column is `integer` (base-100, €12.34 → 1234). The cast **rejects scalars** — build values with `FixedDecimal::ofUnscaled(int, 2)` or the test helper `cents(int)`.
- **Migration contract (ADR-007):** every package table has a timestamped `.php` (source of truth) **and** a byte-identical `.php.stub`. Never hand-edit a `.stub` — edit the `.php`, run `php bin/sync-migration-stubs`, commit both. Add one `$migrationOrder` entry per table in `LarabillInstallCommand`. `MigrationOrderConsistencyTest` validates 1:1 + byte-identity.
- **Immutable invoices (CRITICAL — Codex #1):** `Invoice::update()` (src/Models/Invoice.php:247) THROWS `Cannot update an immutable invoice` for any `status`/`paid_at` change. Settling/reversing MUST go through the dedicated `Invoice` methods from Task 5 (via `save()`), never `Invoice::update()`. Do not loosen the `update()` guard — its test (`tests/Unit/Models/InvoiceTest.php:70`) must stay green.
- **Factories live in `src/Database/Factories/`** (namespace `AichaDigital\Larabill\Database\Factories\`), NOT `database/factories/`. `InvoiceFactory` randomizes `is_immutable` (20%) and `paid_at` (30%) — test helpers MUST pin `'is_immutable' => false, 'paid_at' => null` for payable fixtures, or runs are flaky.
- **Test fixtures:** `TestCase::USER_UUID_1/2/3`; money helper `cents(int)` from `tests/Pest.php`.
- **Local PHP:** run Pest with `~/Library/Application\ Support/Herd/bin/php83` (PHP 8.4 local "table already exists" bug). CI covers PHP 8.3+8.4 × L12+L13 + MySQL 8.
- **Branch:** `abdelkarim/aid-30-grouped-payments` (off main v3.0.0).

## Design decisions (resolved after Codex adversarial review — review these)

- **D1 — Immutable settlement via dedicated model methods.** `Invoice::update()` blocks `status`/`paid_at` on immutable invoices. So `Invoice` gains `markAsPaidViaGroupedPayment(DateTimeInterface)` and `restoreStateViaGroupedPaymentReversal(InvoiceStatus, ?DateTimeInterface)` (via `save()`). Collection state is not fiscal content; the `update()` guard stays intact. (Task 5.)
- **D2 — Re-pay after reverse requires a fresh idempotency key.** The idempotency lookup only short-circuits a **posted** payment. A key mapping to a **reversed** payment is spent → `IdempotencyConflictException`. A real re-payment is a new transfer with its own key. The unique-`idempotency_key` `QueryException` is caught (outside the transaction) to close the concurrent-register race. (Task 8.)
- **D3 — Currency is validated, not assumed.** `billable_user_id` is the *payer*, NOT the issuer (`user_id` is). So `currency` is validated per-invoice against `invoice.companyFiscalConfig.currency`; mismatch → `currencyMismatch`. Invoices with no resolvable config currency are not checked (documented gap). (Task 7.)

## File Structure

| File | Responsibility |
|---|---|
| `src/Enums/GroupedPaymentStatus.php` | Int-backed status enum {POSTED=0, REVERSED=1} |
| `src/Exceptions/GroupedPaymentValidationException.php` | Eligibility failures (named constructors, incl. `currencyMismatch`) |
| `src/Exceptions/IdempotencyConflictException.php` | Same/ spent key, different payload |
| `database/migrations/2026_06_27_000001_create_grouped_payments_table.php` (+ `.stub`) | `grouped_payments` table |
| `database/migrations/2026_06_27_000002_create_grouped_payment_invoice_table.php` (+ `.stub`) | pivot table |
| `src/Console/LarabillInstallCommand.php` | +2 `$migrationOrder` entries (033, 034) |
| `src/Models/GroupedPayment.php` | Entity: casts, relations, factory hook |
| `src/Models/Invoice.php` | +`groupedPayments()` relation, +D1 collection-state methods |
| `src/Database/Factories/GroupedPaymentFactory.php` | Test factory + `reversed()` state |
| `src/Services/GroupedPaymentService.php` | `register()` + `reverse()` |
| `tests/Unit/Enums/GroupedPaymentStatusTest.php` | Enum |
| `tests/Unit/Exceptions/GroupedPaymentExceptionsTest.php` | Exceptions |
| `tests/Unit/Models/GroupedPaymentTest.php` | Schema, model, factory, relations |
| `tests/Unit/Models/InvoiceGroupedPaymentMethodsTest.php` | D1 methods (incl. immutable invoices) |
| `tests/Feature/GroupedPayment/RegisterTest.php` | register happy + eligibility + idempotency |
| `tests/Feature/GroupedPayment/ReverseTest.php` | reverse + restore + re-pay |
| `tests/Integration/Mysql/GroupedPaymentConstraintsTest.php` | MySQL: column types + unique constraints |

---

## Task 1: `GroupedPaymentStatus` enum

**Files:** Create `src/Enums/GroupedPaymentStatus.php`; Test `tests/Unit/Enums/GroupedPaymentStatusTest.php`

**Interfaces:** Produces `enum GroupedPaymentStatus: int { POSTED = 0; REVERSED = 1; }` with `label(): string`, `static toArray(): array<int,string>`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Enums/GroupedPaymentStatusTest.php
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;

it('has POSTED=0 and REVERSED=1', function () {
    expect(GroupedPaymentStatus::POSTED->value)->toBe(0)
        ->and(GroupedPaymentStatus::REVERSED->value)->toBe(1);
});

it('exposes non-empty labels and a 2-entry array', function () {
    expect(GroupedPaymentStatus::POSTED->label())->toBeString()->not->toBeEmpty()
        ->and(GroupedPaymentStatus::toArray())->toHaveKeys([0, 1])->toHaveCount(2);
});
```

- [ ] **Step 2: Run, verify it fails** — `~/Library/Application\ Support/Herd/bin/php83 vendor/bin/pest tests/Unit/Enums/GroupedPaymentStatusTest.php` → FAIL (class not found).

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

enum GroupedPaymentStatus: int
{
    case POSTED   = 0;
    case REVERSED = 1;

    public function label(): string
    {
        return match ($this) {
            self::POSTED   => __('larabill::enums.grouped_payment_status.posted'),
            self::REVERSED => __('larabill::enums.grouped_payment_status.reversed'),
        };
    }

    /** @return array<int, string> */
    public static function toArray(): array
    {
        return [
            self::POSTED->value   => self::POSTED->label(),
            self::REVERSED->value => self::REVERSED->label(),
        ];
    }
}
```

- [ ] **Step 4: Run, verify it passes** — same command → PASS.
- [ ] **Step 5: Commit** — `git add src/Enums/GroupedPaymentStatus.php tests/Unit/Enums/GroupedPaymentStatusTest.php && git commit -m "feat(grouped-payments): GroupedPaymentStatus enum (AID-30)"`

---

## Task 2: Domain exceptions

**Files:** Create `src/Exceptions/GroupedPaymentValidationException.php`, `src/Exceptions/IdempotencyConflictException.php`; Test `tests/Unit/Exceptions/GroupedPaymentExceptionsTest.php`

**Interfaces:**
- `GroupedPaymentValidationException` static ctors: `emptyInvoiceList()`, `duplicateInvoices(array $ids)`, `mixedUsers()`, `currencyMismatch(string $invoiceId, string $expected, string $got)`, `proformaNotPayable(string $invoiceId)`, `notPayableStatus(string $invoiceId, int $status)`, `alreadyActivelyPaid(string $invoiceId)`, `amountMismatch(int $expectedUnscaled, int $gotUnscaled)`, `invoicesNotFound(array $ids)`.
- `IdempotencyConflictException`: `forKey(string $key)`, `keySpentByReversal(string $key)`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Exceptions/GroupedPaymentExceptionsTest.php
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException as V;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException as C;

it('builds every validation failure', function () {
    expect(V::emptyInvoiceList())->toBeInstanceOf(V::class);
    expect(V::duplicateInvoices(['a', 'a'])->getMessage())->toContain('a');
    expect(V::mixedUsers())->toBeInstanceOf(V::class);
    expect(V::currencyMismatch('inv-1', 'EUR', 'USD')->getMessage())->toContain('EUR')->toContain('USD');
    expect(V::proformaNotPayable('inv-1')->getMessage())->toContain('inv-1');
    expect(V::notPayableStatus('inv-1', 0)->getMessage())->toContain('inv-1');
    expect(V::alreadyActivelyPaid('inv-1')->getMessage())->toContain('inv-1');
    expect(V::amountMismatch(1000, 999)->getMessage())->toContain('1000')->toContain('999');
    expect(V::invoicesNotFound(['x'])->getMessage())->toContain('x');
});

it('builds idempotency conflicts', function () {
    expect(C::forKey('k-1')->getMessage())->toContain('k-1');
    expect(C::keySpentByReversal('k-2')->getMessage())->toContain('k-2');
});
```

- [ ] **Step 2: Run, verify it fails.**

- [ ] **Step 3: Implement**

`src/Exceptions/GroupedPaymentValidationException.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use Exception;

class GroupedPaymentValidationException extends Exception
{
    public static function emptyInvoiceList(): self
    {
        return new self('Grouped payment requires at least one invoice.');
    }

    /** @param array<int, string> $ids */
    public static function duplicateInvoices(array $ids): self
    {
        return new self('Grouped payment invoice list contains duplicate ids: '.implode(', ', $ids));
    }

    public static function mixedUsers(): self
    {
        return new self('All invoices in a grouped payment must share the same billable_user_id.');
    }

    public static function currencyMismatch(string $invoiceId, string $expected, string $got): self
    {
        return new self("Invoice {$invoiceId} currency {$got} does not match the payment currency {$expected}.");
    }

    public static function proformaNotPayable(string $invoiceId): self
    {
        return new self("Invoice {$invoiceId} is a proforma and cannot be settled by a grouped payment.");
    }

    public static function notPayableStatus(string $invoiceId, int $status): self
    {
        return new self("Invoice {$invoiceId} has status {$status}; only SENT/OVERDUE/PENDING invoices are payable.");
    }

    public static function alreadyActivelyPaid(string $invoiceId): self
    {
        return new self("Invoice {$invoiceId} is already covered by an active grouped payment.");
    }

    public static function amountMismatch(int $expectedUnscaled, int $gotUnscaled): self
    {
        return new self("Grouped payment amount mismatch: expected sum {$expectedUnscaled}, got {$gotUnscaled} (base-100).");
    }

    /** @param array<int, string> $ids */
    public static function invoicesNotFound(array $ids): self
    {
        return new self('Grouped payment references invoices that do not exist: '.implode(', ', $ids));
    }
}
```

`src/Exceptions/IdempotencyConflictException.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use Exception;

class IdempotencyConflictException extends Exception
{
    public static function forKey(string $key): self
    {
        return new self("Idempotency key '{$key}' was reused with a different payload.");
    }

    public static function keySpentByReversal(string $key): self
    {
        return new self("Idempotency key '{$key}' maps to a reversed payment; a re-payment needs a new key.");
    }
}
```

- [ ] **Step 4: Run, verify it passes.**
- [ ] **Step 5: Commit** — `git add src/Exceptions/*GroupedPayment* src/Exceptions/IdempotencyConflictException.php tests/Unit/Exceptions/GroupedPaymentExceptionsTest.php && git commit -m "feat(grouped-payments): domain exceptions (AID-30)"`

---

## Task 3: Migrations (2 tables) + `$migrationOrder` + stub sync

**Files:** Create the 2 `.php` migrations; generate the 2 `.stub` via `bin/sync-migration-stubs`; modify `src/Console/LarabillInstallCommand.php` (entries 033, 034); Test `tests/Unit/Models/GroupedPaymentTest.php` (schema) + existing `MigrationOrderConsistencyTest`.

**Interfaces:** Produces `grouped_payments` and `grouped_payment_invoice` tables (columns per spec §Data model). Unique: pivot `(grouped_payment_id, invoice_id)` and `(active_invoice_id)`; `grouped_payments.idempotency_key`.

- [ ] **Step 1: Write the failing schema test** — create `tests/Unit/Models/GroupedPaymentTest.php`:

```php
<?php
// tests/Unit/Models/GroupedPaymentTest.php
use Illuminate\Support\Facades\Schema;

it('creates grouped_payments with the expected columns', function () {
    expect(Schema::hasTable('grouped_payments'))->toBeTrue();
    expect(Schema::hasColumns('grouped_payments', [
        'id', 'billable_user_id', 'amount', 'currency', 'paid_at', 'reference',
        'idempotency_key', 'status', 'reversed_at', 'reversed_by', 'reverse_reason', 'notes',
    ]))->toBeTrue();
});

it('creates grouped_payment_invoice pivot with the expected columns', function () {
    expect(Schema::hasTable('grouped_payment_invoice'))->toBeTrue();
    expect(Schema::hasColumns('grouped_payment_invoice', [
        'id', 'grouped_payment_id', 'invoice_id', 'applied_amount',
        'previous_status', 'previous_paid_at', 'active_invoice_id',
    ]))->toBeTrue();
});
```

- [ ] **Step 2: Run, verify it fails** (tables don't exist).

- [ ] **Step 3a: `create_grouped_payments_table` migration** — `database/migrations/2026_06_27_000001_create_grouped_payments_table.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grouped_payments', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('UUID v7 primary key');

            // Payer (user being billed). char(36) + index, no hard FK. (No extra index() — userIdColumn already adds one.)
            MigrationHelper::userIdColumn($table, 'billable_user_id');

            $table->integer('amount')->comment('Base-100 (FixedDecimalCast:2): €12.34 = 1234. Equals sum of settled invoice totals');
            $table->string('currency', 3)->default('EUR')->comment('ISO 4217 — validated against each invoice companyFiscalConfig currency (D3)');
            $table->dateTime('paid_at')->comment('Date the external collection happened');
            $table->string('reference')->nullable()->comment('Bank/accounting reference — metadata, not identity');
            $table->string('idempotency_key')->unique()->comment('Provided or derived; unique guard against duplicate collections');
            $table->unsignedTinyInteger('status')->default(0)->comment('GroupedPaymentStatus: 0=posted, 1=reversed');
            $table->dateTime('reversed_at')->nullable();

            // Reversal actor — char(36) + index, no hard FK (may be a system actor).
            MigrationHelper::userIdColumn($table, 'reversed_by', nullable: true);

            $table->string('reverse_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grouped_payments');
    }
};
```

> Codex #3 fix: `MigrationHelper::userIdColumn()` already indexes `billable_user_id`; do NOT add a second `$table->index('billable_user_id')` (duplicate-index error on MySQL).

- [ ] **Step 3b: `create_grouped_payment_invoice_table` migration** — `database/migrations/2026_06_27_000002_create_grouped_payment_invoice_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grouped_payment_invoice', function (Blueprint $table) {
            $table->id(); // internal pivot PK (not domain-exposed)

            $table->foreignUuid('grouped_payment_id')->constrained('grouped_payments')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices');

            $table->integer('applied_amount')->comment('Base-100 (FixedDecimalCast:2); v1 = invoice total');
            $table->unsignedTinyInteger('previous_status')->comment('InvoiceStatus before marking PAID (exact restore on reverse)');
            $table->dateTime('previous_paid_at')->nullable()->comment('Invoice paid_at before (exact restore)');

            // = invoice_id while posted, NULL when reversed. MySQL-safe one-active-payment backstop.
            $table->uuid('active_invoice_id')->nullable();

            $table->timestamps();

            $table->unique(['grouped_payment_id', 'invoice_id']); // one row per invoice per payment
            $table->unique('active_invoice_id');                  // one active payment per invoice
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grouped_payment_invoice');
    }
};
```

- [ ] **Step 3c: `$migrationOrder` entries** — in `src/Console/LarabillInstallCommand.php`, after `'032' => 'add_article_id_to_invoice_items_table',`:

```php
        // === GROUPED PAYMENTS (AID-30) ===
        '033' => 'create_grouped_payments_table',
        '034' => 'create_grouped_payment_invoice_table',
```

- [ ] **Step 3d: Generate stubs** — `~/Library/Application\ Support/Herd/bin/php83 bin/sync-migration-stubs` (prints `synced: create_grouped_payments_table.php.stub` + `synced: create_grouped_payment_invoice_table.php.stub`, exit 0).

- [ ] **Step 4: Run tests** — `... pest tests/Unit/Models/GroupedPaymentTest.php tests/Unit/Console/MigrationOrderConsistencyTest.php` → PASS (schema + 34-entry consistency).

- [ ] **Step 5: Commit** — `git add database/migrations/2026_06_27_* database/migrations/create_grouped_payment* src/Console/LarabillInstallCommand.php tests/Unit/Models/GroupedPaymentTest.php && git commit -m "feat(grouped-payments): tables + migrationOrder + stubs (AID-30)"`

---

## Task 4: `GroupedPayment` model + factory + Invoice inverse relation

**Files:** Create `src/Models/GroupedPayment.php`, `src/Database/Factories/GroupedPaymentFactory.php`; modify `src/Models/Invoice.php` (add `groupedPayments()` after `billableUser()`, ~line 316; add `use ...BelongsToMany;`); Test append to `tests/Unit/Models/GroupedPaymentTest.php`.

**Interfaces:** `GroupedPayment` — `use HasFactory, HasUuid;` casts `amount`→FixedDecimal:2, `status`→GroupedPaymentStatus, `paid_at`/`reversed_at`→datetime; `invoices(): BelongsToMany` (withPivot), `payer(): BelongsTo`; `isPosted()`, `isReversed()`. `Invoice::groupedPayments(): BelongsToMany`. Factory default `billable_user_id` = generated UUID (Codex #9).

- [ ] **Step 1: Write the failing test** — append:

```php
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\TestCase;

it('casts amount to FixedDecimal and status to the enum, with a valid default factory row', function () {
    $payment = GroupedPayment::factory()->create(['amount' => cents(10000)]); // no billable override → factory supplies a UUID
    expect($payment->amount)->toBeInstanceOf(FixedDecimal::class)
        ->and($payment->amount->unscaledValue())->toBe(10000)
        ->and($payment->status)->toBe(GroupedPaymentStatus::POSTED)
        ->and($payment->billable_user_id)->not->toBeNull();
});

it('relates a payment to its invoices and back', function () {
    $invoice = Invoice::factory()->sent()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'is_immutable' => false, 'paid_at' => null,
    ]);
    $payment = GroupedPayment::factory()->create(['billable_user_id' => TestCase::USER_UUID_2]);
    $payment->invoices()->attach($invoice->id, [
        'applied_amount' => 5000, 'previous_status' => $invoice->status->value, 'active_invoice_id' => $invoice->id,
    ]);
    expect($payment->invoices)->toHaveCount(1)
        ->and($invoice->fresh()->groupedPayments)->toHaveCount(1);
});
```

- [ ] **Step 2: Run, verify it fails** (GroupedPayment not found).

- [ ] **Step 3a: Model** — `src/Models/GroupedPayment.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Larabill\Concerns\HasUuid;
use AichaDigital\Larabill\Database\Factories\GroupedPaymentFactory;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * GroupedPayment — accounting record of one external collection settling N issued invoices.
 * Immutable once posted; lifecycle posted → reversed. Money is FixedDecimal:2 over integer base-100.
 *
 * @property string $id
 * @property string $billable_user_id
 * @property \AichaDigital\Lara100\ValueObjects\FixedDecimal $amount
 * @property string $currency
 * @property \Illuminate\Support\Carbon $paid_at
 * @property string|null $reference
 * @property string $idempotency_key
 * @property GroupedPaymentStatus $status
 * @property \Illuminate\Support\Carbon|null $reversed_at
 * @property string|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $notes
 */
class GroupedPayment extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'billable_user_id', 'amount', 'currency', 'paid_at', 'reference',
        'idempotency_key', 'status', 'reversed_at', 'reversed_by', 'reverse_reason', 'notes',
    ];

    public function casts(): array
    {
        return [
            'amount'      => FixedDecimalCast::class.':2',
            'status'      => GroupedPaymentStatus::class,
            'paid_at'     => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): GroupedPaymentFactory
    {
        return GroupedPaymentFactory::new();
    }

    /** @return BelongsToMany<Invoice, $this> */
    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'grouped_payment_invoice', 'grouped_payment_id', 'invoice_id')
            ->withPivot(['applied_amount', 'previous_status', 'previous_paid_at', 'active_invoice_id'])
            ->withTimestamps();
    }

    /** @return BelongsTo<Model, $this> */
    public function payer(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('larabill.user_model');

        return $this->belongsTo($userModel, 'billable_user_id');
    }

    public function isPosted(): bool
    {
        return $this->status === GroupedPaymentStatus::POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === GroupedPaymentStatus::REVERSED;
    }
}
```

- [ ] **Step 3b: Factory** — `src/Database/Factories/GroupedPaymentFactory.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Models\GroupedPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GroupedPayment> */
class GroupedPaymentFactory extends Factory
{
    protected $model = GroupedPayment::class;

    public function definition(): array
    {
        return [
            // billable_user_id is NOT NULL — supply a UUID by default (Codex #9).
            'billable_user_id' => (string) Str::orderedUuid(),
            'amount'           => FixedDecimal::ofUnscaled($this->faker->numberBetween(1000, 100000), 2),
            'currency'         => 'EUR',
            'paid_at'          => $this->faker->dateTimeBetween('-30 days', 'now'),
            'reference'        => $this->faker->optional()->bothify('TRF-#####'),
            'idempotency_key'  => (string) Str::orderedUuid(),
            'status'           => GroupedPaymentStatus::POSTED->value,
            'reversed_at'      => null,
            'reversed_by'      => null,
            'reverse_reason'   => null,
            'notes'            => null,
        ];
    }

    public function reversed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'         => GroupedPaymentStatus::REVERSED->value,
            'reversed_at'    => now(),
            'reverse_reason' => 'test reversal',
        ]);
    }
}
```

- [ ] **Step 3c: Invoice inverse relation** — add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` to imports, then after `billableUser()`:

```php
    /**
     * Grouped payments that settled (or did settle, if reversed) this invoice.
     *
     * @return BelongsToMany<GroupedPayment, $this>
     */
    public function groupedPayments(): BelongsToMany
    {
        return $this->belongsToMany(GroupedPayment::class, 'grouped_payment_invoice', 'invoice_id', 'grouped_payment_id')
            ->withPivot(['applied_amount', 'previous_status', 'previous_paid_at', 'active_invoice_id'])
            ->withTimestamps();
    }
```

- [ ] **Step 4: Run, verify it passes.**
- [ ] **Step 5: Commit** — `git add src/Models/GroupedPayment.php src/Database/Factories/GroupedPaymentFactory.php src/Models/Invoice.php tests/Unit/Models/GroupedPaymentTest.php && git commit -m "feat(grouped-payments): GroupedPayment model, factory, Invoice relation (AID-30)"`

---

## Task 5: `Invoice` collection-state methods (D1 — settle immutable invoices)

**Files:** Modify `src/Models/Invoice.php` (add 2 methods after the `makeImmutable()` block, ~line 239); Test `tests/Unit/Models/InvoiceGroupedPaymentMethodsTest.php`.

**Interfaces:** Produces on `Invoice`:
- `markAsPaidViaGroupedPayment(\DateTimeInterface $paidAt): void`
- `restoreStateViaGroupedPaymentReversal(\AichaDigital\Larabill\Enums\InvoiceStatus $status, ?\DateTimeInterface $paidAt): void`

Both set `status`+`paid_at` via `save()` (bypassing the `update()` immutability guard on purpose; collection state is not fiscal content).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Models/InvoiceGroupedPaymentMethodsTest.php
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\TestCase;

it('marks an IMMUTABLE invoice as paid without tripping the update() guard', function () {
    $invoice = Invoice::factory()->sent()->immutable()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'paid_at' => null,
    ]);
    expect($invoice->is_immutable)->toBeTrue();

    $invoice->markAsPaidViaGroupedPayment(now());

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($invoice->fresh()->paid_at)->not->toBeNull();
});

it('restores collection state on an immutable invoice', function () {
    $invoice = Invoice::factory()->sent()->immutable()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'paid_at' => null,
    ]);
    $invoice->markAsPaidViaGroupedPayment(now());
    $invoice->restoreStateViaGroupedPaymentReversal(InvoiceStatus::SENT, null);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and($invoice->fresh()->paid_at)->toBeNull();
});

it('still blocks a plain update() on an immutable invoice (guard intact)', function () {
    $invoice = Invoice::factory()->immutable()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000),
    ]);
    expect(fn () => $invoice->update(['status' => InvoiceStatus::PAID->value, 'paid_at' => now()]))
        ->toThrow(Exception::class);
});
```

- [ ] **Step 2: Run, verify it fails** — `... pest tests/Unit/Models/InvoiceGroupedPaymentMethodsTest.php` → FAIL (methods undefined; the third test already passes, the guard exists).

- [ ] **Step 3: Implement** — in `src/Models/Invoice.php` add `use DateTimeInterface;` (already imported) and add after `makeImmutable()`:

```php
    /**
     * Mark this invoice PAID as part of a grouped payment (AID-30).
     *
     * Collection state (status + paid_at) is NOT fiscal content, so this is a
     * permitted transition even on immutable invoices. It goes through save()
     * deliberately, bypassing the update() immutability guard, which protects
     * fiscal content (amounts, dates, snapshots) — not payment state.
     */
    public function markAsPaidViaGroupedPayment(DateTimeInterface $paidAt): void
    {
        $this->status  = InvoiceStatus::PAID;
        $this->paid_at = $paidAt;
        $this->save();
    }

    /**
     * Restore this invoice's collection state when its grouped payment is reversed.
     * Same rationale as markAsPaidViaGroupedPayment(): permitted on immutable invoices.
     */
    public function restoreStateViaGroupedPaymentReversal(InvoiceStatus $status, ?DateTimeInterface $paidAt): void
    {
        $this->status  = $status;
        $this->paid_at = $paidAt;
        $this->save();
    }
```

- [ ] **Step 4: Run, verify it passes** (all 3 green; the guard test confirms `update()` is untouched).
- [ ] **Step 5: Commit** — `git add src/Models/Invoice.php tests/Unit/Models/InvoiceGroupedPaymentMethodsTest.php && git commit -m "feat(grouped-payments): Invoice collection-state methods for immutable settle/reverse (AID-30)"`

---

## Task 6: `GroupedPaymentService::register()` — happy path

**Files:** Create `src/Services/GroupedPaymentService.php`; Test `tests/Feature/GroupedPayment/RegisterTest.php`.

**Interfaces:** Produces `register(string $billableUserId, array $invoiceIds, DateTimeInterface $paidAt, FixedDecimal $amount, string $currency, ?string $reference = null, ?string $idempotencyKey = null): GroupedPayment` and private `deriveIdempotencyKey(...)`. Uses `Invoice::markAsPaidViaGroupedPayment()` (Task 5), never `update()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/GroupedPayment/RegisterTest.php
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Tests\TestCase;

// Pinned non-immutable, unpaid SENT fixture (Codex #2: factory randomizes both).
function makeSentInvoice(int $totalCents): Invoice
{
    return Invoice::factory()->sent()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents($totalCents), 'is_immutable' => false, 'paid_at' => null,
    ]);
}

it('settles a set of issued invoices in one posted payment', function () {
    $a = makeSentInvoice(6000);
    $b = makeSentInvoice(4000);

    $payment = app(GroupedPaymentService::class)->register(
        billableUserId: TestCase::USER_UUID_2, invoiceIds: [$a->id, $b->id],
        paidAt: now(), amount: cents(10000), currency: 'EUR', reference: 'TRF-001',
    );

    expect($payment->status)->toBe(GroupedPaymentStatus::POSTED)
        ->and($payment->amount->unscaledValue())->toBe(10000)
        ->and($payment->invoices)->toHaveCount(2);
    expect($a->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($a->fresh()->paid_at)->not->toBeNull()
        ->and($b->fresh()->status)->toBe(InvoiceStatus::PAID);

    $pivot = $payment->invoices()->where('invoice_id', $a->id)->first()->pivot;
    expect((int) $pivot->previous_status)->toBe(InvoiceStatus::SENT->value)
        ->and($pivot->active_invoice_id)->toBe($a->id);
});
```

- [ ] **Step 2: Run, verify it fails** (service not found).

- [ ] **Step 3: Implement (happy path only)** — `src/Services/GroupedPaymentService.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class GroupedPaymentService
{
    /** @param list<string> $invoiceIds */
    public function register(
        string $billableUserId,
        array $invoiceIds,
        DateTimeInterface $paidAt,
        FixedDecimal $amount,
        string $currency,
        ?string $reference = null,
        ?string $idempotencyKey = null,
    ): GroupedPayment {
        $key = $idempotencyKey ?? $this->deriveIdempotencyKey($billableUserId, $invoiceIds, $currency, $amount);

        return DB::transaction(function () use ($billableUserId, $invoiceIds, $paidAt, $amount, $currency, $reference, $key): GroupedPayment {
            $orderedIds = $invoiceIds;
            sort($orderedIds); // deterministic lock order (deadlock-safe)

            $invoices = Invoice::whereIn('id', $orderedIds)
                ->with('companyFiscalConfig')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $payment = GroupedPayment::create([
                'billable_user_id' => $billableUserId,
                'amount'           => $amount,
                'currency'         => $currency,
                'paid_at'          => $paidAt,
                'reference'        => $reference,
                'idempotency_key'  => $key,
                'status'           => GroupedPaymentStatus::POSTED,
            ]);

            foreach ($invoices as $invoice) {
                $payment->invoices()->attach($invoice->id, [
                    'applied_amount'    => $invoice->total_amount->unscaledValue(),
                    'previous_status'   => $invoice->status->value,
                    'previous_paid_at'  => $invoice->paid_at,
                    'active_invoice_id' => $invoice->id,
                ]);

                $invoice->markAsPaidViaGroupedPayment($paidAt); // D1: works on immutable invoices
            }

            return $payment->load('invoices');
        });
    }

    /** @param list<string> $invoiceIds */
    private function deriveIdempotencyKey(string $billableUserId, array $invoiceIds, string $currency, FixedDecimal $amount): string
    {
        $ids = $invoiceIds;
        sort($ids);

        return hash('sha256', implode('|', [$billableUserId, implode(',', $ids), $currency, (string) $amount->unscaledValue()]));
    }
}
```

- [ ] **Step 4: Run, verify it passes.**
- [ ] **Step 5: Commit** — `git add src/Services/GroupedPaymentService.php tests/Feature/GroupedPayment/RegisterTest.php && git commit -m "feat(grouped-payments): register() happy path (AID-30)"`

---

## Task 7: `register()` eligibility (incl. currency D3)

**Files:** Modify `src/Services/GroupedPaymentService.php` (add `assertEligible()` inside the locked tx, before create); Test append to `tests/Feature/GroupedPayment/RegisterTest.php`.

**Interfaces:** private `assertEligible(string $billableUserId, string $currency, array $invoiceIds, Collection $invoices, FixedDecimal $amount): void`. Uses `GroupedPaymentValidationException` ctors (Task 2).

- [ ] **Step 1: Write the failing tests (one per rejection)**

```php
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;

it('rejects an empty invoice list', function () {
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [], now(), cents(0), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects duplicate invoice ids', function () {
    $a = makeSentInvoice(5000);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id, $a->id], now(), cents(10000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects a nonexistent invoice id', function () {
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [(string) \Illuminate\Support\Str::orderedUuid()], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects invoices belonging to different billable users', function () {
    $a = makeSentInvoice(5000);
    $b = Invoice::factory()->sent()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_3,
        'total_amount' => cents(5000), 'is_immutable' => false, 'paid_at' => null,
    ]);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id, $b->id], now(), cents(10000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects a currency that differs from the invoice fiscal config (D3)', function () {
    $config = CompanyFiscalConfig::factory()->create(['currency' => 'USD']);
    $usd = Invoice::factory()->sent()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'is_immutable' => false, 'paid_at' => null,
        'company_fiscal_config_id' => $config->id,
    ]);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$usd->id], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects a proforma', function () {
    $p = Invoice::factory()->proforma()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'is_immutable' => false,
    ]);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$p->id], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects a draft (not-payable status)', function () {
    $d = Invoice::factory()->draft()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'is_immutable' => false,
    ]);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$d->id], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects an amount that does not equal the sum of totals', function () {
    $a = makeSentInvoice(6000);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5999), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects an invoice already covered by an active payment', function () {
    $a = makeSentInvoice(5000);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'first');
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'second');
})->throws(GroupedPaymentValidationException::class);
```

> Note: `CompanyFiscalConfig::factory()->create()` may interact with the `Invoice` creating-hook `FiscalIntegrityChecker`. If creating an invoice with an explicit `company_fiscal_config_id` plus a separate active config trips an integrity error, adjust the currency test to use `CompanyFiscalConfig::factory()->active()` or seed a single config — keep the assertion (currency mismatch throws).

- [ ] **Step 2: Run, verify they fail** (no validation yet).

- [ ] **Step 3: Add validation** — add imports `use AichaDigital\Larabill\Enums\InvoiceSerieType;`, `use AichaDigital\Larabill\Enums\InvoiceStatus;`, `use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;`, `use Illuminate\Support\Collection;`. Inside `register()`, after loading `$invoices` and before `GroupedPayment::create`, insert `$this->assertEligible($billableUserId, $currency, $orderedIds, $invoices, $amount);`. Then:

```php
    /**
     * @param  list<string>  $invoiceIds
     * @param  Collection<int, Invoice>  $invoices
     */
    private function assertEligible(string $billableUserId, string $currency, array $invoiceIds, Collection $invoices, FixedDecimal $amount): void
    {
        if ($invoiceIds === []) {
            throw GroupedPaymentValidationException::emptyInvoiceList();
        }

        if (count($invoiceIds) !== count(array_unique($invoiceIds))) {
            $dupes = array_keys(array_filter(array_count_values($invoiceIds), fn (int $n): bool => $n > 1));
            throw GroupedPaymentValidationException::duplicateInvoices(array_values($dupes));
        }

        $found   = $invoices->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $missing = array_values(array_diff($invoiceIds, $found));
        if ($missing !== []) {
            throw GroupedPaymentValidationException::invoicesNotFound($missing);
        }

        $payableStatuses = [InvoiceStatus::SENT, InvoiceStatus::OVERDUE, InvoiceStatus::PENDING];
        $sum = FixedDecimal::zero(2);

        foreach ($invoices as $invoice) {
            if ((string) $invoice->billable_user_id !== $billableUserId) {
                throw GroupedPaymentValidationException::mixedUsers();
            }

            // D3: validate currency against the invoice's effective fiscal currency.
            $invoiceCurrency = $invoice->companyFiscalConfig?->currency;
            if ($invoiceCurrency !== null && $invoiceCurrency !== $currency) {
                throw GroupedPaymentValidationException::currencyMismatch((string) $invoice->id, $currency, $invoiceCurrency);
            }

            if ($invoice->serie === InvoiceSerieType::PROFORMA) {
                throw GroupedPaymentValidationException::proformaNotPayable((string) $invoice->id);
            }

            if (! in_array($invoice->status, $payableStatuses, true)) {
                throw GroupedPaymentValidationException::notPayableStatus((string) $invoice->id, $invoice->status->value);
            }

            $activelyPaid = DB::table('grouped_payment_invoice')->where('active_invoice_id', $invoice->id)->exists();
            if ($activelyPaid) {
                throw GroupedPaymentValidationException::alreadyActivelyPaid((string) $invoice->id);
            }

            $sum = $sum->plus($invoice->total_amount);
        }

        if ($sum->compareTo($amount) !== 0) {
            throw GroupedPaymentValidationException::amountMismatch($sum->unscaledValue(), $amount->unscaledValue());
        }
    }
```

- [ ] **Step 4: Run, verify they pass** (happy + 9 rejections green).
- [ ] **Step 5: Commit** — `git add src/Services/GroupedPaymentService.php tests/Feature/GroupedPayment/RegisterTest.php && git commit -m "feat(grouped-payments): register() eligibility incl. currency (AID-30)"`

---

## Task 8: `register()` idempotency (D2)

**Files:** Modify `src/Services/GroupedPaymentService.php`; Test append to `tests/Feature/GroupedPayment/RegisterTest.php`.

**Interfaces:** idempotency lookup at the top of the tx (posted-only short-circuit; reversed key → `keySpentByReversal`; posted+differ → `forKey`); a unique-violation `QueryException` catch **outside** the tx (race backstop) via private helpers `payloadMatches(...)` and `isIdempotencyKeyViolation(QueryException $e)`.

- [ ] **Step 1: Write the failing tests**

```php
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Models\GroupedPayment;

it('returns the same posted payment when a provided key is replayed', function () {
    $a = makeSentInvoice(5000);
    $svc = app(GroupedPaymentService::class);
    $first  = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'idem-1');
    $second = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'idem-1');
    expect($second->id)->toBe($first->id)->and(GroupedPayment::count())->toBe(1);
});

it('returns the same payment when the derived key matches', function () {
    $a = makeSentInvoice(5000);
    $svc = app(GroupedPaymentService::class);
    $first  = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR');
    $second = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR');
    expect($second->id)->toBe($first->id)->and(GroupedPayment::count())->toBe(1);
});

it('ignores a differing reference on replay (reference is not identity)', function () {
    $a = makeSentInvoice(5000);
    $svc = app(GroupedPaymentService::class);
    $first  = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', reference: 'TRF-A', idempotencyKey: 'idem-2');
    $second = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', reference: 'TRF-B', idempotencyKey: 'idem-2');
    expect($second->id)->toBe($first->id)->and($second->reference)->toBe('TRF-A');
});

it('throws on a reused posted key with a different payload', function () {
    $a = makeSentInvoice(5000);
    $b = makeSentInvoice(3000);
    $svc = app(GroupedPaymentService::class);
    $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'idem-3');
    $svc->register(TestCase::USER_UUID_2, [$b->id], now(), cents(3000), 'EUR', idempotencyKey: 'idem-3');
})->throws(IdempotencyConflictException::class);
```

(The reversed-key path is exercised in Task 9's re-pay tests.)

- [ ] **Step 2: Run, verify they fail.**

- [ ] **Step 3: Add the idempotency branch + race catch** — add imports `use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;`, `use Illuminate\Database\QueryException;`. Restructure `register()`: compute `$key`, then wrap the transaction in a try/catch:

```php
        try {
            return DB::transaction(function () use ($billableUserId, $invoiceIds, $paidAt, $amount, $currency, $reference, $key): GroupedPayment {
                $existing = GroupedPayment::where('idempotency_key', $key)->first();
                if ($existing !== null) {
                    if ($existing->isReversed()) {
                        throw IdempotencyConflictException::keySpentByReversal($key); // D2: spent key
                    }
                    if (! $this->payloadMatches($existing, $billableUserId, $invoiceIds, $amount, $currency)) {
                        throw IdempotencyConflictException::forKey($key);
                    }

                    return $existing->load('invoices');
                }

                $orderedIds = $invoiceIds;
                sort($orderedIds);

                $invoices = Invoice::whereIn('id', $orderedIds)->with('companyFiscalConfig')->orderBy('id')->lockForUpdate()->get();
                $this->assertEligible($billableUserId, $currency, $orderedIds, $invoices, $amount);

                $payment = GroupedPayment::create([
                    'billable_user_id' => $billableUserId, 'amount' => $amount, 'currency' => $currency,
                    'paid_at' => $paidAt, 'reference' => $reference, 'idempotency_key' => $key,
                    'status' => GroupedPaymentStatus::POSTED,
                ]);

                foreach ($invoices as $invoice) {
                    $payment->invoices()->attach($invoice->id, [
                        'applied_amount' => $invoice->total_amount->unscaledValue(),
                        'previous_status' => $invoice->status->value,
                        'previous_paid_at' => $invoice->paid_at,
                        'active_invoice_id' => $invoice->id,
                    ]);
                    $invoice->markAsPaidViaGroupedPayment($paidAt);
                }

                return $payment->load('invoices');
            });
        } catch (QueryException $e) {
            // Concurrency backstop: another tx won the unique idempotency_key race (the tx rolled back).
            if ($this->isIdempotencyKeyViolation($e)) {
                $raced = GroupedPayment::where('idempotency_key', $key)->first();
                if ($raced !== null && $raced->isPosted() && $this->payloadMatches($raced, $billableUserId, $invoiceIds, $amount, $currency)) {
                    return $raced->load('invoices');
                }
                throw IdempotencyConflictException::forKey($key);
            }
            throw $e;
        }
```

Add helpers:

```php
    /** @param list<string> $invoiceIds */
    private function payloadMatches(GroupedPayment $existing, string $billableUserId, array $invoiceIds, FixedDecimal $amount, string $currency): bool
    {
        if ((string) $existing->billable_user_id !== $billableUserId || $existing->currency !== $currency) {
            return false;
        }
        if ($existing->amount->compareTo($amount) !== 0) {
            return false;
        }
        $existingIds  = $existing->invoices->pluck('id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
        $requestedIds = collect($invoiceIds)->map(fn ($id): string => (string) $id)->sort()->values()->all();

        return $existingIds === $requestedIds;
    }

    private function isIdempotencyKeyViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 = integrity constraint violation; message names the column/index.
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'idempotency_key');
    }
```

- [ ] **Step 4: Run, verify they pass.**
- [ ] **Step 5: Commit** — `git add src/Services/GroupedPaymentService.php tests/Feature/GroupedPayment/RegisterTest.php && git commit -m "feat(grouped-payments): register() idempotency D2 (AID-30)"`

---

## Task 9: `GroupedPaymentService::reverse()`

**Files:** Modify `src/Services/GroupedPaymentService.php` (add `reverse()`); Test `tests/Feature/GroupedPayment/ReverseTest.php`.

**Interfaces:** `reverse(GroupedPayment $payment, string $reason, ?string $reversedBy = null): GroupedPayment`. Re-reads + `lockForUpdate`s the payment inside the tx (concurrency-safe no-op, Codex #8); restores invoices via `restoreStateViaGroupedPaymentReversal()` (Task 5); nulls pivot `active_invoice_id`. Re-pay after reverse needs a fresh key (D2).

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/GroupedPayment/ReverseTest.php
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Tests\TestCase;

function sentInvoice(int $cents): Invoice
{
    return Invoice::factory()->sent()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents($cents), 'is_immutable' => false, 'paid_at' => null,
    ]);
}

it('reverses a payment and restores each invoice to its prior state', function () {
    $a = sentInvoice(5000);
    $svc = app(GroupedPaymentService::class);
    $payment = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'r-1');
    expect($a->fresh()->status)->toBe(InvoiceStatus::PAID);

    $reversed = $svc->reverse($payment, 'customer refund', TestCase::USER_UUID_3);

    expect($reversed->status)->toBe(GroupedPaymentStatus::REVERSED)
        ->and($reversed->reverse_reason)->toBe('customer refund')
        ->and($reversed->reversed_by)->toBe(TestCase::USER_UUID_3);
    $a = $a->fresh();
    expect($a->status)->toBe(InvoiceStatus::SENT)->and($a->paid_at)->toBeNull();
    expect($reversed->invoices()->where('invoice_id', $a->id)->first()->pivot->active_invoice_id)->toBeNull();
});

it('is a stable no-op on a second reverse (audit fields untouched)', function () {
    $a = sentInvoice(5000);
    $svc = app(GroupedPaymentService::class);
    $payment = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'r-2');
    $first = $svc->reverse($payment, 'first reason', TestCase::USER_UUID_3);
    $again = $svc->reverse($first->fresh(), 'second reason', TestCase::USER_UUID_1);
    expect($again->reverse_reason)->toBe('first reason')->and($again->reversed_by)->toBe(TestCase::USER_UUID_3);
});

it('lets a reversed invoice join a NEW payment with a fresh key (D2)', function () {
    $a = sentInvoice(5000);
    $svc = app(GroupedPaymentService::class);
    $p1 = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 're-1');
    $svc->reverse($p1, 'undo', TestCase::USER_UUID_3);

    $p2 = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 're-2');
    expect($p2->status)->toBe(GroupedPaymentStatus::POSTED)->and($a->fresh()->status)->toBe(InvoiceStatus::PAID);
});

it('refuses re-pay after reverse when the spent/derived key is reused (D2)', function () {
    $a = sentInvoice(5000);
    $svc = app(GroupedPaymentService::class);
    $p1 = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'spent');
    $svc->reverse($p1, 'undo', TestCase::USER_UUID_3);
    $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'spent');
})->throws(IdempotencyConflictException::class);
```

- [ ] **Step 2: Run, verify they fail** (`reverse()` not defined).

- [ ] **Step 3: Implement** — add to `src/Services/GroupedPaymentService.php`:

```php
    public function reverse(GroupedPayment $payment, string $reason, ?string $reversedBy = null): GroupedPayment
    {
        return DB::transaction(function () use ($payment, $reason, $reversedBy): GroupedPayment {
            // Re-read + lock so two concurrent reversals can't both write (Codex #8).
            $locked = GroupedPayment::whereKey($payment->getKey())->lockForUpdate()->first() ?? $payment;

            if ($locked->isReversed()) {
                return $locked->load('invoices'); // stable no-op, audit fields untouched
            }

            $locked->update([
                'status'         => GroupedPaymentStatus::REVERSED,
                'reversed_at'    => now(),
                'reversed_by'    => $reversedBy,
                'reverse_reason' => $reason,
            ]);

            foreach ($locked->invoices()->get() as $invoice) {
                $invoice->restoreStateViaGroupedPaymentReversal(
                    InvoiceStatus::from((int) $invoice->pivot->previous_status),
                    $invoice->pivot->previous_paid_at !== null ? \Illuminate\Support\Carbon::parse($invoice->pivot->previous_paid_at) : null,
                );

                DB::table('grouped_payment_invoice')
                    ->where('grouped_payment_id', $locked->id)
                    ->where('invoice_id', $invoice->id)
                    ->update(['active_invoice_id' => null]);
            }

            return $locked->load('invoices');
        });
    }
```

> `GroupedPayment` has no `update()` override, so `$locked->update([...])` is fine here (this is the payment, not an Invoice). `InvoiceStatus::from()` maps the captured tinyInteger back to the enum.

- [ ] **Step 4: Run, verify they pass.**
- [ ] **Step 5: Commit** — `git add src/Services/GroupedPaymentService.php tests/Feature/GroupedPayment/ReverseTest.php && git commit -m "feat(grouped-payments): reverse() with exact restore + D2 re-pay (AID-30)"`

---

## Task 10: MySQL integration — column types + constraints

**Files:** Create `tests/Integration/Mysql/GroupedPaymentConstraintsTest.php`.

**Interfaces:** Consumes `MysqlIntegrationTestCase` (`bootstrap()`, `getMysqlColumnType()`, `getMysqlColumnLength()`). Codex #7 fix: seed real `users` + `invoices` rows so the pivot FK to `invoices` is satisfied before the unique constraints under test are reached.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Integration/Mysql/GroupedPaymentConstraintsTest.php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('AID-30 — grouped payment schema on MySQL', function () {

    it('stores amount/applied_amount as integers and ids as char(36)', function () {
        $this->bootstrap();
        expect($this->getMysqlColumnType('grouped_payments', 'amount'))->toBe('int');
        expect($this->getMysqlColumnType('grouped_payment_invoice', 'applied_amount'))->toBe('int');
        expect($this->getMysqlColumnLength('grouped_payments', 'id'))->toBe(36);
        expect($this->getMysqlColumnLength('grouped_payments', 'billable_user_id'))->toBe(36);
        expect($this->getMysqlColumnLength('grouped_payment_invoice', 'active_invoice_id'))->toBe(36);
    });

    it('enforces one pivot row per (grouped_payment_id, invoice_id)', function () {
        $this->bootstrap();
        $paymentId = (string) Str::orderedUuid();
        $invoiceId = seedInvoice($this->seedUser());
        DB::table('grouped_payments')->insert(rowForPayment($paymentId));

        $insert = fn () => DB::table('grouped_payment_invoice')->insert(pivotRow($paymentId, $invoiceId, null));
        $insert();
        expect($insert)->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('allows many reversed (null) rows but one active payment per invoice', function () {
        $this->bootstrap();
        $userId = $this->seedUser();
        $invA = seedInvoice($userId); $invB = seedInvoice($userId); $invC = seedInvoice($userId);
        $p1 = (string) Str::orderedUuid(); $p2 = (string) Str::orderedUuid();
        DB::table('grouped_payments')->insert(rowForPayment($p1));
        DB::table('grouped_payments')->insert(rowForPayment($p2));

        // Two reversed rows (active_invoice_id NULL) for the same invoice → allowed.
        DB::table('grouped_payment_invoice')->insert(pivotRow($p1, $invA, null));
        DB::table('grouped_payment_invoice')->insert(pivotRow($p2, $invA, null));

        // Two ACTIVE rows pointing at invB → second rejected by unique(active_invoice_id).
        DB::table('grouped_payment_invoice')->insert(pivotRow($p1, $invB, $invB));
        expect(fn () => DB::table('grouped_payment_invoice')->insert(pivotRow($p2, $invC, $invB)))
            ->toThrow(\Illuminate\Database\QueryException::class);
    });

});

function rowForPayment(string $id): array
{
    return [
        'id' => $id, 'billable_user_id' => (string) \Illuminate\Support\Str::orderedUuid(),
        'amount' => 1000, 'currency' => 'EUR', 'paid_at' => now(),
        'idempotency_key' => (string) \Illuminate\Support\Str::orderedUuid(), 'status' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ];
}

function pivotRow(string $paymentId, string $invoiceId, ?string $activeInvoiceId): array
{
    return [
        'grouped_payment_id' => $paymentId, 'invoice_id' => $invoiceId, 'applied_amount' => 1000,
        'previous_status' => 1, 'active_invoice_id' => $activeInvoiceId,
        'created_at' => now(), 'updated_at' => now(),
    ];
}
```

Add these seed helpers to the same file (insert raw rows that satisfy NOT NULL columns of `users` + `invoices` — adjust the column set during implementation against the real `invoices` schema):

```php
function seedInvoice(string $userId): string
{
    $id = (string) \Illuminate\Support\Str::orderedUuid();
    DB::table('invoices')->insert([
        'id' => $id, 'fiscal_number' => 'FAC-'.substr($id, 0, 8), 'prefix' => 'FAC',
        'serie' => 1, 'series_number' => random_int(1, 1_000_000), 'fiscal_year' => 2026,
        'invoice_date' => now()->toDateString(), 'issued_at' => now(), 'status' => 1,
        'user_id' => $userId, 'taxable_amount' => 1000, 'total_tax_amount' => 0, 'total_amount' => 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
```

And add `protected function seedUser(): string` to `MysqlIntegrationTestCase` (or inline it here) inserting a `users` row with a UUID id and returning it.

> The `seedInvoice`/`seedUser` raw inserts must match the real NOT-NULL columns of `invoices`/`users`. If `invoices` has additional NOT-NULL columns without defaults, add them here. This replaces the previously-broken raw-`invoice_id` approach (Codex #7).

- [ ] **Step 2: Run (skips without MySQL env)** — `LARABILL_TEST_MYSQL_HOST=127.0.0.1 LARABILL_TEST_MYSQL_PORT=3306 LARABILL_TEST_MYSQL_DATABASE=larabill_test LARABILL_TEST_MYSQL_USERNAME=larabill_test LARABILL_TEST_MYSQL_PASSWORD=larabill_test ~/Library/Application\ Support/Herd/bin/php83 vendor/bin/pest tests/Integration/Mysql/GroupedPaymentConstraintsTest.php` → FAIL on constraints (or schema) before impl; without env vars → skipped.

- [ ] **Step 3: Implementation** — no new production code; constraints come from Task 3. If a test reveals a missing/incorrect index or seed column, fix the `.php` migration, re-run `bin/sync-migration-stubs`, re-run.

- [ ] **Step 4: Run, verify they pass** (3 passed against MySQL 8).
- [ ] **Step 5: Commit** — `git add tests/Integration/Mysql/GroupedPaymentConstraintsTest.php tests/Integration/Mysql/MysqlIntegrationTestCase.php && git commit -m "test(grouped-payments): MySQL types + unique constraints (AID-30)"`

---

## Final verification (before opening the PR)

- [ ] Full suite (SQLite): `~/Library/Application\ Support/Herd/bin/php83 vendor/bin/pest`
- [ ] MySQL integration: same with `LARABILL_TEST_MYSQL_*` set
- [ ] `composer phpstan` + `composer pint`
- [ ] `~/Library/Application\ Support/Herd/bin/php83 bin/sync-migration-stubs` prints `0 updated`; `MigrationOrderConsistencyTest` green
- [ ] Confirm `tests/Unit/Models/InvoiceTest.php` (the `update()` immutability guard test) is still green — D1 must not weaken it
- [ ] Update `CHANGELOG.md` (Unreleased → grouped payments AID-30) + bump `VERSION` per release policy
- [ ] After merge: create the separate **fiscal** "factura agrupada" (proforma consolidation) issue

## Self-Review notes

- **Codex findings closed:** #1 (immutable settle → Task 5 methods + Global Constraint), #2 (flaky factory → pinned helpers), #3 (duplicate index → removed), #4 (currency justification → D3 validates against fiscal config), #5 (re-pay vs idempotency → D2, Task 9 tests), #6 (concurrent register → QueryException catch, Task 8), #7 (MySQL FK → seeded rows, Task 10), #8 (reverse race → lock-in-tx, Task 9), #9 (factory billable default → UUID, Task 4), #10 (coverage → currency + invoicesNotFound tests).
- **Validated, kept:** `unique(active_invoice_id)` with many NULLs works on MySQL and SQLite (Codex confirmed empirically).
- **Open implementation risks (flagged in-task):** Task 7 currency test may interact with the `FiscalIntegrityChecker` creating-hook; Task 10 seed inserts must match the real `invoices`/`users` NOT-NULL columns. Both have adjustment notes.
- **D1/D2/D3** are the contract-level calls — top of plan + in the spec's Design decisions section.
