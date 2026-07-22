# AID-601 — Invariante de no-solape en `article_prices`: plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** garantizar en la escritura del paquete que, para toda fecha, exista a lo sumo un precio activo por `(article_id, billing_frequency)` — la invariante que la lectura ya presupone y que el índice físico no da.

**Architecture:** la garantía vive en la capa de aplicación, no en DDL. Una condición de solape única (`scopeOverlapping`) alimenta dos consumidores: un hook `saving` en `ArticlePrice` (red de seguridad en todo camino Eloquent) y un servicio de escritura que añade transacción y lock sobre la fila padre de `articles` (la garantía real bajo concurrencia). Se completa con lectura determinista en `getPriceFor()` y un comando de diagnóstico read-only para datos preexistentes. **Cero cambios de esquema.**

**Tech Stack:** PHP 8.3 (`php83` en local — ver constraints), Laravel 12/13, Pest 4, Eloquent, MySQL 8 para integración.

**Spec:** `docs/superpowers/specs/2026-07-21-aid-601-article-price-overlap-invariant-design.md` (decisiones D1–D8).

## Global Constraints

- **Binario PHP local:** SIEMPRE `php83` (bug conocido de SQLite in-memory en 8.4). También para `composer`: `php83 "$(which composer)" …`.
- **Ninguna migración se toca.** Ni DDL ni comentarios (D6). `bin/sync-migration-stubs` NO se ejecuta. `$migrationOrder`, `release-migration-manifest.json` y los conteos fijados de instalación quedan intactos.
- **Dinero:** base-100 entero con `FixedDecimalCast:2` (`lara100`). Nunca float/decimal.
- **UUID-first:** `users.id` es UUID v7 `char(36)`; en fixtures usar `TestCase::USER_UUID_1/2/3`. `article_prices.article_id` es `bigint` (clave propia del paquete, no de usuario) y no cambia.
- **Idioma:** código, comentarios, docblocks, mensajes de commit y cuerpo del PR en **inglés**. La spec y el ADR, en español (precedente ADR-011).
- **Helpers de test:** cualquier función file-level nueva lleva nombre único y se comprueba con `grep -rn "function <nombre>(" tests/` antes de commitear (colisión de namespace global de Pest).
- **Verde por fichero no vale:** antes de cada push, `php83 vendor/bin/pest --parallel` completo.
- **Gates de calidad por tarea:** `php83 vendor/bin/pint` y `php83 vendor/bin/phpstan analyse --memory-limit=1G` (level 8) antes de cada commit.
- **Versión destino:** `6.8.0` (MINOR — superficie pública añadida). El bump y el tag NO forman parte de este plan.

---

## Estructura de ficheros

| Fichero | Responsabilidad |
|---|---|
| `src/Models/ArticlePrice.php` (modificar) | `scopeOverlapping()` puro + hook `saving` con auto-exclusión explícita |
| `src/Exceptions/OverlappingArticlePriceException.php` (crear) | Excepción de dominio `@api` con IDs, rangos y referencia al comando |
| `src/Services/ArticlePriceService.php` (crear) | Escritura segura: transacción + lock sobre `articles` + validación |
| `src/Models/Article.php` (modificar) | `getPriceFor()` determinista |
| `src/Console/DiagnosePriceOverlapsCommand.php` (crear) | Diagnóstico read-only de pares solapados, exit code 0/1 |
| `src/LarabillServiceProvider.php` (modificar) | Registro del comando |
| `tests/Unit/Models/ArticlePriceOverlapTest.php` (crear) | Matriz de intersección + comportamiento del hook |
| `tests/Unit/Services/ArticlePriceServiceTest.php` (crear) | Contrato del servicio |
| `tests/Unit/Console/DiagnosePriceOverlapsCommandTest.php` (crear) | Salida y exit codes |
| `tests/Unit/Models/ArticlePriceReadDeterminismTest.php` (crear) | `getPriceFor()` ante duplicados legacy |
| `docs/ADR-012-article-price-overlap-invariant.md` (crear) | Registro de la decisión |
| `docs/api-surface.md`, `CHANGELOG.md`, `README.md` (modificar) | Superficie y entrega |

---

### Task 0: Barrido de fixtures que ya violen la invariante

Antes de escribir código. Instalar el hook pondrá en rojo cualquier fixture que cree dos precios activos solapados de la misma frecuencia; descubrirlo a mitad de implementación confunde el diagnóstico.

**Files:**
- Solo lectura y, si aparece un fixture infractor, el fichero de test concreto.

**Interfaces:**
- Consumes: nada.
- Produces: la certeza de que la suite parte de un estado compatible. Ninguna firma.

- [ ] **Step 1: Comprobar que la factory de precios no puede solapar**

Run: `grep -n "article_id" src/Database/Factories/ArticlePriceFactory.php`

Expected: `'article_id' => Article::factory()->withoutPrices()` — cada precio de factory nace con artículo propio, así que los tests planos no pueden violar la invariante. Si esto ya no fuera cierto, PARAR y replantear con el humano.

- [ ] **Step 2: Comprobar los estados de `ArticleFactory` que crean precios**

Run: `grep -n "afterCreating" -A 12 src/Database/Factories/ArticleFactory.php`

Expected: `configure()` crea un precio por defecto **solo si `prices()->count() === 0`**, y los estados (`monthly()`, `withPrices()`) hacen `$article->prices()->delete()` antes de crear. Verificar además que `withPrices()` no puede recibir dos entradas de la misma frecuencia (las claves del array son la frecuencia).

- [ ] **Step 3: Localizar tests que compartan un `article_id` explícito entre precios**

Run: `grep -rn "ArticlePrice::factory()" tests/ | grep -c "for(" ; grep -rn "ArticlePrice::factory()" -A 4 tests/ | grep -n "article_id" | head -20`

Expected: una lista corta. Para cada aparición, comprobar a mano si dos precios activos comparten artículo **y** frecuencia con rangos que se tocan.

- [ ] **Step 4: Revisar los flujos que escriben precios de rebote**

Run: `grep -rln "prices()->create\|ArticlePrice::create" src/ tests/`

Expected: ninguno en `src/` fuera de factories (larabill no escribe precios hoy; los escribe el consumidor). Si aparece alguno en `src/`, es un consumidor interno del futuro servicio y debe anotarse para la Task 4.

- [ ] **Step 5: Registrar el resultado**

Sin commit si no hubo cambios. Si algún fixture infringe la invariante, corregir **el fixture** (nunca relajar la invariante) en un commit propio:

```bash
git add tests/<fichero>
git commit -m "test: give overlapping price fixtures disjoint ranges (AID-601)"
```

---

### Task 1: La condición de solape como fuente única (`scopeOverlapping`)

**Files:**
- Modify: `src/Models/ArticlePrice.php` (añadir scope junto a los scopes existentes, tras `scopeCurrentlyValid`)
- Test: `tests/Unit/Models/ArticlePriceOverlapTest.php` (crear)

**Interfaces:**
- Consumes: `BillingFrequency` (enum), `Carbon`.
- Produces: `ArticlePrice::query()->overlapping(int $articleId, BillingFrequency $frequency, ?Carbon $validFrom, ?Carbon $validTo)` — devuelve las filas **activas** de esa `(artículo, frecuencia)` cuyo intervalo interseca `[validFrom, validTo]`, con `NULL` como extremo abierto. **No excluye ninguna fila por clave**: la auto-exclusión es responsabilidad del llamador (Task 2).

- [ ] **Step 1: Escribir el test de la matriz de intersección**

Crear `tests/Unit/Models/ArticlePriceOverlapTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticlePrice;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-601: the overlap condition is the single source of truth shared by the
 * saving hook and the write service. NULL is an open end in both directions.
 */
beforeEach(function () {
    $this->article = Article::factory()->withoutPrices()->create();
});

/** Seeds one active MONTHLY price with the given range. */
function aid601SeedPrice(Article $article, ?string $from, ?string $to, bool $active = true): ArticlePrice
{
    return ArticlePrice::factory()->for($article)->create([
        'billing_frequency' => BillingFrequency::MONTHLY,
        'price'             => FixedDecimal::ofUnscaled(2900, 2),
        'valid_from'        => $from,
        'valid_to'          => $to,
        'is_active'         => $active,
    ]);
}

/** Runs the scope for a MONTHLY candidate with the given range. */
function aid601Overlaps(Article $article, ?string $from, ?string $to): int
{
    return ArticlePrice::query()
        ->overlapping(
            $article->id,
            BillingFrequency::MONTHLY,
            $from === null ? null : Carbon\Carbon::parse($from),
            $to === null ? null : Carbon\Carbon::parse($to),
        )
        ->count();
}

it('treats two fully open ranges as overlapping', function () {
    aid601SeedPrice($this->article, null, null);

    expect(aid601Overlaps($this->article, null, null))->toBe(1);
});

it('treats an open-ended existing range as overlapping any later candidate', function () {
    aid601SeedPrice($this->article, '2026-01-01', null);

    expect(aid601Overlaps($this->article, '2027-06-01', null))->toBe(1);
});

it('treats an open-start candidate as overlapping a dated existing range', function () {
    aid601SeedPrice($this->article, '2026-01-01', '2026-12-31');

    expect(aid601Overlaps($this->article, null, '2026-03-01'))->toBe(1);
});

it('detects partially overlapping closed ranges', function () {
    aid601SeedPrice($this->article, '2026-01-01', '2026-06-30');

    expect(aid601Overlaps($this->article, '2026-06-01', '2026-12-31'))->toBe(1);
});

it('treats ranges touching on a single day as overlapping', function () {
    aid601SeedPrice($this->article, '2026-01-01', '2026-06-30');

    expect(aid601Overlaps($this->article, '2026-06-30', '2026-12-31'))->toBe(1);
});

it('allows disjoint closed ranges (legitimate price history)', function () {
    aid601SeedPrice($this->article, '2026-01-01', '2026-06-30');

    expect(aid601Overlaps($this->article, '2026-07-01', '2026-12-31'))->toBe(0);
});

it('allows a candidate that ends before an open-start existing range', function () {
    aid601SeedPrice($this->article, '2026-07-01', null);

    expect(aid601Overlaps($this->article, '2026-01-01', '2026-06-30'))->toBe(0);
});

it('ignores inactive rows entirely', function () {
    aid601SeedPrice($this->article, null, null, active: false);

    expect(aid601Overlaps($this->article, null, null))->toBe(0);
});

it('ignores rows of another billing frequency', function () {
    ArticlePrice::factory()->for($this->article)->create([
        'billing_frequency' => BillingFrequency::YEARLY,
        'price'             => FixedDecimal::ofUnscaled(29000, 2),
        'valid_from'        => null,
        'valid_to'          => null,
    ]);

    expect(aid601Overlaps($this->article, null, null))->toBe(0);
});

it('ignores rows of another article', function () {
    aid601SeedPrice(Article::factory()->withoutPrices()->create(), null, null);

    expect(aid601Overlaps($this->article, null, null))->toBe(0);
});
```

- [ ] **Step 2: Comprobar que los helpers no colisionan**

Run: `grep -rn "function aid601SeedPrice(\|function aid601Overlaps(" tests/ | wc -l`
Expected: `2` (solo el fichero nuevo). Si sale más, renombrar.

- [ ] **Step 3: Ejecutar el test y verlo fallar**

Run: `php83 vendor/bin/pest tests/Unit/Models/ArticlePriceOverlapTest.php`
Expected: FAIL — `Call to undefined method Illuminate\Database\Eloquent\Builder::overlapping()`.

- [ ] **Step 4: Implementar el scope**

En `src/Models/ArticlePrice.php`, tras `scopeCurrentlyValid()`:

```php
    /**
     * Scope to the ACTIVE rows of one (article, frequency) whose validity range
     * intersects the candidate range. NULL is an open end on both sides.
     *
     * Single source of truth for the non-overlap invariant (AID-601): used by
     * the saving hook and by ArticlePriceService. Deliberately pure — it does
     * NOT exclude the candidate's own row; that is the caller's business, and
     * keeping it out lets the diagnose command reuse the same condition.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOverlapping(
        Builder $query,
        int $articleId,
        BillingFrequency $frequency,
        ?Carbon $validFrom,
        ?Carbon $validTo,
    ): void {
        $query->where('article_id', $articleId)
            ->where('billing_frequency', $frequency)
            ->where('is_active', true);

        // candidate.from <= existing.to  (either side NULL ⇒ always true)
        if ($validFrom !== null) {
            $query->where(function (Builder $q) use ($validFrom) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $validFrom);
            });
        }

        // existing.from <= candidate.to  (either side NULL ⇒ always true)
        if ($validTo !== null) {
            $query->where(function (Builder $q) use ($validTo) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $validTo);
            });
        }
    }
```

- [ ] **Step 5: Ejecutar el test y verlo pasar**

Run: `php83 vendor/bin/pest tests/Unit/Models/ArticlePriceOverlapTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 6: Gates y commit**

```bash
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G --no-progress
git add src/Models/ArticlePrice.php tests/Unit/Models/ArticlePriceOverlapTest.php
git commit -m "feat(pricing): add the article price overlap condition as a single source (AID-601)"
```

---

### Task 2: Excepción de dominio y hook `saving`

**Files:**
- Create: `src/Exceptions/OverlappingArticlePriceException.php`
- Modify: `src/Models/ArticlePrice.php` (método `booted()`)
- Test: `tests/Unit/Models/ArticlePriceOverlapTest.php` (ampliar)

**Interfaces:**
- Consumes: `scopeOverlapping()` de la Task 1.
- Produces: `OverlappingArticlePriceException::forCandidate(ArticlePrice $candidate, Collection<int, ArticlePrice> $conflicts): self`, lanzada desde el hook `saving`. Es la excepción que la Task 4 deja propagar.

- [ ] **Step 1: Escribir los tests del hook**

Añadir al final de `tests/Unit/Models/ArticlePriceOverlapTest.php`:

```php
it('rejects creating a price that overlaps an active one', function () {
    aid601SeedPrice($this->article, null, null);

    expect(fn () => aid601SeedPrice($this->article, null, null))
        ->toThrow(OverlappingArticlePriceException::class);
});

it('allows saving a row without changing its own range', function () {
    $price = aid601SeedPrice($this->article, null, null);

    $price->price = FixedDecimal::ofUnscaled(3900, 2);
    $price->save();

    expect($price->fresh()->price->unscaledValue())->toBe(3900);
});

it('rejects reactivating a row that overlaps an active one', function () {
    $dormant = aid601SeedPrice($this->article, null, null, active: false);
    aid601SeedPrice($this->article, null, null);

    $dormant->is_active = true;

    expect(fn () => $dormant->save())->toThrow(OverlappingArticlePriceException::class);
});

it('allows saving an inactive row that would overlap', function () {
    aid601SeedPrice($this->article, null, null);

    $dormant = aid601SeedPrice($this->article, null, null, active: false);

    expect($dormant->exists)->toBeTrue();
});

it('allows disjoint price history', function () {
    aid601SeedPrice($this->article, '2026-01-01', '2026-06-30');
    aid601SeedPrice($this->article, '2026-07-01', null);

    expect(ArticlePrice::query()->where('article_id', $this->article->id)->count())->toBe(2);
});

it('names the conflicting rows and the diagnose command in the message', function () {
    $existing = aid601SeedPrice($this->article, '2026-01-01', null);

    expect(fn () => aid601SeedPrice($this->article, '2026-06-01', null))
        ->toThrow(
            OverlappingArticlePriceException::class,
            "conflicts with active price(s) [#{$existing->id} 2026-01-01→open]",
        );
});

it('survives the consumer delete-then-create pattern', function () {
    // Regression for the reference consumer (clientes, ArticleEdit::save):
    // it deletes the ACTIVE rows and re-creates one per frequency.
    aid601SeedPrice($this->article, null, null);

    $this->article->prices()->where('is_active', true)->delete();
    $recreated = aid601SeedPrice($this->article, null, null);

    expect($recreated->exists)->toBeTrue();
});
```

Añadir los `use` que faltan en la cabecera del fichero:

```php
use AichaDigital\Larabill\Exceptions\OverlappingArticlePriceException;
```

- [ ] **Step 2: Ejecutar y ver fallar**

Run: `php83 vendor/bin/pest tests/Unit/Models/ArticlePriceOverlapTest.php`
Expected: FAIL — `Class "AichaDigital\Larabill\Exceptions\OverlappingArticlePriceException" not found`.

- [ ] **Step 3: Crear la excepción**

Crear `src/Exceptions/OverlappingArticlePriceException.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\ArticlePrice;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * An article price would have been active at the same time as another price of
 * the same article and billing frequency.
 *
 * Two prices live at once make billing non-deterministic: Article::getPriceFor()
 * resolves a single value, so one of them silently wins and reaches invoice
 * lines (AID-601, ADR-012). The database cannot express "at most one valid at
 * any date" — no exclusion constraints in MySQL — so the invariant is enforced
 * here, at write time.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class OverlappingArticlePriceException extends RuntimeException
{
    /**
     * @param  Collection<int, ArticlePrice>  $conflicts
     */
    public static function forCandidate(ArticlePrice $candidate, Collection $conflicts): self
    {
        $describe = static fn (ArticlePrice $price): string => sprintf(
            '#%s %s→%s',
            $price->getKey() ?? 'new',
            $price->valid_from?->toDateString() ?? 'open',
            $price->valid_to?->toDateString() ?? 'open',
        );

        return new self(sprintf(
            'Article %d, frequency %s: price %s conflicts with active price(s) [%s]. '
            .'At most one price per article and frequency may be active on any given date '
            .'(AID-601). Run `php artisan larabill:diagnose-price-overlaps` to list every '
            .'existing conflict.',
            $candidate->article_id,
            $candidate->billing_frequency->name,
            $describe($candidate),
            $conflicts->map($describe)->implode(', '),
        ));
    }
}
```

- [ ] **Step 4: Implementar el hook**

En `src/Models/ArticlePrice.php`, añadir tras las propiedades del modelo (antes de `article()`):

```php
    /**
     * Enforce the non-overlap invariant on every Eloquent write (AID-601).
     *
     * Safety net, not a guarantee: without a transaction two processes can
     * validate and write concurrently. The guaranteed path is
     * ArticlePriceService, which locks the parent article first.
     *
     * Only active rows are checked — an inactive row is disabled history and
     * cannot violate the invariant — which also covers reactivation
     * (is_active false→true), as real an overlap path as any create.
     *
     * Note: this runs on every save of an active row, and is_active defaults to
     * true, so a bulk seeder pays one extra query per price. Deliberate.
     */
    protected static function booted(): void
    {
        static::saving(function (self $price): void {
            if (! $price->is_active) {
                return;
            }

            $conflicts = static::query()
                ->overlapping(
                    $price->article_id,
                    $price->billing_frequency,
                    $price->valid_from,
                    $price->valid_to,
                )
                // Explicit self-exclusion on update. Not a bug fix:
                // whereKeyNot(null) would NOT emit `id != NULL` — the query
                // builder rewrites a null value with `!=` into `IS NOT NULL`
                // (Query/Builder.php) — but relying on that rewrite is
                // unreadable, so the condition is spelled out.
                ->when($price->exists, fn (Builder $query) => $query->whereKeyNot($price->getKey()))
                ->get();

            if ($conflicts->isNotEmpty()) {
                throw OverlappingArticlePriceException::forCandidate($price, $conflicts);
            }
        });
    }
```

Añadir el `use` de la excepción en la cabecera del modelo.

- [ ] **Step 5: Ejecutar y ver pasar**

Run: `php83 vendor/bin/pest tests/Unit/Models/ArticlePriceOverlapTest.php`
Expected: PASS, 17 tests.

- [ ] **Step 6: Suite completa — aquí es donde aparecen los fixtures infractores**

Run: `php83 vendor/bin/pest --parallel`
Expected: PASS. Si algo falla, es un fixture que violaba la invariante: **se corrige el fixture** dándole rangos disjuntos o artículos distintos, nunca se relaja el hook.

- [ ] **Step 7: Gates y commit**

```bash
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G --no-progress
git add src/Exceptions/OverlappingArticlePriceException.php src/Models/ArticlePrice.php tests/Unit/Models/ArticlePriceOverlapTest.php
git commit -m "feat(pricing): reject overlapping active article prices on save (AID-601)"
```

---

### Task 3: Lectura determinista en `getPriceFor()`

Commit propio: es el mismo defecto de dinero y es la única pieza que protege a quien todavía no ha limpiado sus duplicados.

**Files:**
- Modify: `src/Models/Article.php:307-311`
- Test: `tests/Unit/Models/ArticlePriceReadDeterminismTest.php` (crear)

**Interfaces:**
- Consumes: nada de tareas previas.
- Produces: `Article::getPriceFor(BillingFrequency $frequency): ?float` con orden estable. La firma no cambia.

- [ ] **Step 1: Escribir el test**

Crear `tests/Unit/Models/ArticlePriceReadDeterminismTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * AID-601: legacy databases may already hold overlapping prices. Reading must
 * be predictable there — most recent wins — instead of returning whatever the
 * index walk yields. Rows are seeded through the query builder on purpose:
 * the saving hook would (correctly) reject them.
 */
it('returns the most recent price when legacy duplicates exist', function () {
    $article = Article::factory()->withoutPrices()->create();

    $rows = [
        ['valid_from' => null,         'price' => 1000],
        ['valid_from' => '2026-01-01', 'price' => 2000],
        ['valid_from' => '2027-01-01', 'price' => 3000],
    ];

    foreach ($rows as $row) {
        DB::table('article_prices')->insert([
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'price'             => $row['price'],
            'valid_from'        => $row['valid_from'],
            'valid_to'          => null,
            'is_active'         => true,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    // getPriceFor() is typed ?float, and PHP widens the int from
    // unscaledValue(), so assert against a float — as ArticleTest does.
    expect($article->getPriceFor(BillingFrequency::MONTHLY))->toBe(3000.0);
});

it('is a no-op on valid single-price data', function () {
    $article = Article::factory()->monthly(2900)->create();

    expect($article->getPriceFor(BillingFrequency::MONTHLY))->toBe(2900.0);
});
```

- [ ] **Step 2: Ejecutar y ver fallar**

Run: `php83 vendor/bin/pest tests/Unit/Models/ArticlePriceReadDeterminismTest.php`
Expected: FAIL en el primero — devuelve `1000.0` (orden de inserción/índice) en vez de `3000.0`. Si por casualidad devolviera `3000.0`, el test sigue siendo válido: sin `ORDER BY` el resultado no está garantizado. Confirmarlo revisando que `getPriceFor()` no ordena.

- [ ] **Step 3: Implementar**

En `src/Models/Article.php`, dentro de `getPriceFor()`:

```php
        return $this->activePrices()
            ->where('billing_frequency', $frequency)
            // Deterministic pick when a legacy database still holds overlapping
            // rows (AID-601): most recent start wins, id breaks the tie. NULL
            // sorts lowest in MySQL and SQLite, so a dated row beats an
            // open-start legacy one. Aimed at predictability, not at guessing
            // the operator's intent — the real fix is removing the duplicate.
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->value('price')?->unscaledValue();
```

- [ ] **Step 4: Ejecutar y ver pasar**

Run: `php83 vendor/bin/pest tests/Unit/Models/ArticlePriceReadDeterminismTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Gates y commit**

```bash
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G --no-progress
git add src/Models/Article.php tests/Unit/Models/ArticlePriceReadDeterminismTest.php
git commit -m "fix(pricing): resolve article prices deterministically under legacy duplicates (AID-601)"
```

---

### Task 4: `ArticlePriceService` — la escritura garantizada

**Files:**
- Create: `src/Services/ArticlePriceService.php`
- Test: `tests/Unit/Services/ArticlePriceServiceTest.php` (crear)

**Interfaces:**
- Consumes: `scopeOverlapping()` (Task 1), `OverlappingArticlePriceException` (Task 2).
- Produces: `ArticlePriceService::setPrice(Article $article, BillingFrequency $frequency, FixedDecimal $price, ?Carbon $validFrom = null, ?Carbon $validTo = null, ?int $billingDaysInAdvance = null): ArticlePrice`.

- [ ] **Step 1: Escribir el test**

Crear `tests/Unit/Services/ArticlePriceServiceTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\OverlappingArticlePriceException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Services\ArticlePriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ArticlePriceService::class);
    $this->article = Article::factory()->withoutPrices()->create();
});

it('writes a price for a frequency that has none', function () {
    $price = $this->service->setPrice(
        $this->article,
        BillingFrequency::MONTHLY,
        FixedDecimal::ofUnscaled(2900, 2),
    );

    expect($price->exists)->toBeTrue()
        ->and($price->price->unscaledValue())->toBe(2900)
        ->and($price->is_active)->toBeTrue();
});

it('refuses to write a price overlapping an active one', function () {
    $this->service->setPrice($this->article, BillingFrequency::MONTHLY, FixedDecimal::ofUnscaled(2900, 2));

    expect(fn () => $this->service->setPrice(
        $this->article,
        BillingFrequency::MONTHLY,
        FixedDecimal::ofUnscaled(3900, 2),
    ))->toThrow(OverlappingArticlePriceException::class);
});

it('writes disjoint price history for the same frequency', function () {
    $this->service->setPrice(
        $this->article,
        BillingFrequency::MONTHLY,
        FixedDecimal::ofUnscaled(2900, 2),
        validFrom: Carbon\Carbon::parse('2026-01-01'),
        validTo: Carbon\Carbon::parse('2026-12-31'),
    );

    $next = $this->service->setPrice(
        $this->article,
        BillingFrequency::MONTHLY,
        FixedDecimal::ofUnscaled(3900, 2),
        validFrom: Carbon\Carbon::parse('2027-01-01'),
    );

    expect($next->exists)->toBeTrue()
        ->and($this->article->prices()->count())->toBe(2);
});

it('leaves nothing behind when the write is rejected', function () {
    $this->service->setPrice($this->article, BillingFrequency::MONTHLY, FixedDecimal::ofUnscaled(2900, 2));

    try {
        $this->service->setPrice($this->article, BillingFrequency::MONTHLY, FixedDecimal::ofUnscaled(3900, 2));
    } catch (OverlappingArticlePriceException) {
        // expected
    }

    expect($this->article->prices()->count())->toBe(1);
});
```

- [ ] **Step 2: Ejecutar y ver fallar**

Run: `php83 vendor/bin/pest tests/Unit/Services/ArticlePriceServiceTest.php`
Expected: FAIL — `Target class [AichaDigital\Larabill\Services\ArticlePriceService] does not exist`.

- [ ] **Step 3: Implementar el servicio**

Crear `src/Services/ArticlePriceService.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticlePrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Safe write path for article prices — the guaranteed side of the non-overlap
 * invariant (AID-601, ADR-012). Read counterpart: PricingService.
 *
 * The model's saving hook is a safety net: two processes can validate and write
 * concurrently. This service closes that window by locking first.
 *
 * WHY THE LOCK IS ON THE ARTICLE ROW: `FOR UPDATE` only locks rows matching the
 * WHERE clause. The first price of a frequency matches zero rows, so locking
 * "the prices of this (article, frequency)" would not serialise two concurrent
 * first inserts at all. The parent articles row always exists; locking it
 * serialises every price write of that article, and avoids gap locks over empty
 * ranges (the deadlock source of AID-390/AID-570).
 *
 * NO RETRY HERE, BY DESIGN: callers typically wrap this in their own
 * DB::transaction, where an inner `attempts` would be a savepoint and buy
 * nothing (AID-570). The lock is held until the OUTER transaction commits;
 * deadlock retry belongs to whoever opens it.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class ArticlePriceService
{
    /**
     * Create an active price for one article and billing frequency.
     *
     * @throws \AichaDigital\Larabill\Exceptions\OverlappingArticlePriceException
     *         when the range would be active at the same time as another price
     *         of the same article and frequency.
     */
    public function setPrice(
        Article $article,
        BillingFrequency $frequency,
        FixedDecimal $price,
        ?Carbon $validFrom = null,
        ?Carbon $validTo = null,
        ?int $billingDaysInAdvance = null,
    ): ArticlePrice {
        return DB::transaction(function () use ($article, $frequency, $price, $validFrom, $validTo, $billingDaysInAdvance): ArticlePrice {
            // Serialise every price write of this article (see class docblock).
            Article::query()->whereKey($article->getKey())->lockForUpdate()->first();

            // The saving hook re-checks with the same condition; validating here
            // is what makes the check meaningful, because it happens under lock.
            return ArticlePrice::query()->create([
                'article_id'              => $article->getKey(),
                'billing_frequency'       => $frequency,
                'price'                   => $price,
                'billing_days_in_advance' => $billingDaysInAdvance,
                'valid_from'              => $validFrom,
                'valid_to'                => $validTo,
                'is_active'               => true,
            ]);
        });
    }
}
```

- [ ] **Step 4: Ejecutar y ver pasar**

Run: `php83 vendor/bin/pest tests/Unit/Services/ArticlePriceServiceTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Gates y commit**

```bash
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G --no-progress
git add src/Services/ArticlePriceService.php tests/Unit/Services/ArticlePriceServiceTest.php
git commit -m "feat(pricing): add the locked write path for article prices (AID-601)"
```

---

### Task 5: Comando de diagnóstico

**Files:**
- Create: `src/Console/DiagnosePriceOverlapsCommand.php`
- Modify: `src/LarabillServiceProvider.php` (bloque `$this->commands([...])`, ~línea 70)
- Test: `tests/Unit/Console/DiagnosePriceOverlapsCommandTest.php` (crear)

**Interfaces:**
- Consumes: nada de tareas previas (query propia de pares).
- Produces: comando `larabill:diagnose-price-overlaps`, exit `0` sin solapes y `1` con solapes.

- [ ] **Step 1: Escribir el test**

Crear `tests/Unit/Console/DiagnosePriceOverlapsCommandTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * AID-601: the pre-upgrade gate for consumers. Rows are seeded through the
 * query builder because the saving hook rejects overlaps by design.
 */
function aid601InsertRawPrice(int $articleId, ?string $from, ?string $to, int $price = 2900, bool $active = true): void
{
    DB::table('article_prices')->insert([
        'article_id'        => $articleId,
        'billing_frequency' => BillingFrequency::MONTHLY->value,
        'price'             => $price,
        'valid_from'        => $from,
        'valid_to'          => $to,
        'is_active'         => $active,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

it('exits zero on a clean database', function () {
    Article::factory()->monthly(2900)->create();

    $this->artisan('larabill:diagnose-price-overlaps')
        ->expectsOutputToContain('No overlapping article prices found')
        ->assertExitCode(0);
});

it('exits non-zero and reports the overlapping pair', function () {
    $article = Article::factory()->withoutPrices()->create();
    aid601InsertRawPrice($article->id, null, null);
    aid601InsertRawPrice($article->id, '2026-01-01', null);

    $this->artisan('larabill:diagnose-price-overlaps')
        ->expectsOutputToContain('1 overlapping pair')
        ->assertExitCode(1);
});

it('does not report inactive rows', function () {
    $article = Article::factory()->withoutPrices()->create();
    aid601InsertRawPrice($article->id, null, null);
    aid601InsertRawPrice($article->id, null, null, active: false);

    $this->artisan('larabill:diagnose-price-overlaps')->assertExitCode(0);
});

it('does not report disjoint price history', function () {
    $article = Article::factory()->withoutPrices()->create();
    aid601InsertRawPrice($article->id, '2026-01-01', '2026-06-30');
    aid601InsertRawPrice($article->id, '2026-07-01', null);

    $this->artisan('larabill:diagnose-price-overlaps')->assertExitCode(0);
});
```

Comprobar colisión: `grep -rn "function aid601InsertRawPrice(" tests/ | wc -l` → `1`.

- [ ] **Step 2: Ejecutar y ver fallar**

Run: `php83 vendor/bin/pest tests/Unit/Console/DiagnosePriceOverlapsCommandTest.php`
Expected: FAIL — comando `larabill:diagnose-price-overlaps` no existe.

- [ ] **Step 3: Implementar el comando**

Crear `src/Console/DiagnosePriceOverlapsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lists active article prices that are valid at the same time as another price
 * of the same article and billing frequency (AID-601, ADR-012).
 *
 * Read-only. Exit code 1 when overlaps exist, so a consumer can wire it as a
 * pre-upgrade gate: after AID-601 the package refuses to save such rows, and
 * existing ones must be resolved by whoever owns the pricing decision — the
 * package will not pick a winner, because that is deciding money for the
 * consumer.
 *
 * Uses the query builder rather than Eloquent: this is a reporting self-join
 * over pairs, not domain logic.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class DiagnosePriceOverlapsCommand extends Command
{
    protected $signature = 'larabill:diagnose-price-overlaps';

    protected $description = 'List article prices that are active at the same time for the same article and billing frequency';

    public function handle(): int
    {
        $pairs = DB::table('article_prices as a')
            ->join('article_prices as b', function ($join) {
                $join->on('a.article_id', '=', 'b.article_id')
                    ->on('a.billing_frequency', '=', 'b.billing_frequency')
                    ->whereColumn('a.id', '<', 'b.id');
            })
            ->where('a.is_active', true)
            ->where('b.is_active', true)
            // a.from <= b.to  (NULL = open end)
            ->whereRaw('(a.valid_from IS NULL OR b.valid_to IS NULL OR a.valid_from <= b.valid_to)')
            // b.from <= a.to
            ->whereRaw('(b.valid_from IS NULL OR a.valid_to IS NULL OR b.valid_from <= a.valid_to)')
            ->orderBy('a.article_id')
            ->orderBy('a.billing_frequency')
            ->get([
                'a.article_id',
                'a.billing_frequency',
                'a.id as a_id', 'a.valid_from as a_from', 'a.valid_to as a_to', 'a.price as a_price',
                'b.id as b_id', 'b.valid_from as b_from', 'b.valid_to as b_to', 'b.price as b_price',
            ]);

        if ($pairs->isEmpty()) {
            $this->info('No overlapping article prices found.');

            return self::SUCCESS;
        }

        $this->table(
            ['article', 'frequency', 'price A', 'range A', 'price B', 'range B'],
            $pairs->map(fn (object $pair): array => [
                (string) $pair->article_id,
                (string) $pair->billing_frequency,
                "#{$pair->a_id} ({$pair->a_price})",
                ($pair->a_from ?? 'open').' → '.($pair->a_to ?? 'open'),
                "#{$pair->b_id} ({$pair->b_price})",
                ($pair->b_from ?? 'open').' → '.($pair->b_to ?? 'open'),
            ])->all(),
        );

        $this->error(sprintf(
            '%d overlapping pair(s) found. Larabill refuses to save overlapping active prices '
            .'(AID-601); resolve these before upgrading. Deciding which price survives is a '
            .'pricing decision and belongs to you, not to the package.',
            $pairs->count(),
        ));

        return self::FAILURE;
    }
}
```

- [ ] **Step 4: Registrar el comando**

En `src/LarabillServiceProvider.php`, añadir el `use` y la entrada:

```php
use AichaDigital\Larabill\Console\DiagnosePriceOverlapsCommand;
```

```php
            $this->commands([
                LarabillInstallCommand::class,
                DiagnosePriceOverlapsCommand::class,
            ]);
```

- [ ] **Step 5: Ejecutar y ver pasar**

Run: `php83 vendor/bin/pest tests/Unit/Console/DiagnosePriceOverlapsCommandTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 6: Gates y commit**

```bash
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G --no-progress
git add src/Console/DiagnosePriceOverlapsCommand.php src/LarabillServiceProvider.php tests/Unit/Console/DiagnosePriceOverlapsCommandTest.php
git commit -m "feat(pricing): add larabill:diagnose-price-overlaps as a pre-upgrade gate (AID-601)"
```

---

### Task 6: Concurrencia — fork test con sensibilidad demostrada

**Files:**
- Create: `tests/Concurrency/ArticlePriceConcurrencyTest.php`

**Interfaces:**
- Consumes: `ArticlePriceService::setPrice()` (Task 4).
- Produces: evidencia de que el lock cierra la ventana. Ninguna firma.

Gateado con `RUN_CONCURRENCY_IT=1` y fuera de los testsuites por defecto (patrón AID-390/AID-264): la suite normal no lo carga.

- [ ] **Step 1: Escribir el test**

Crear `tests/Concurrency/ArticlePriceConcurrencyTest.php` siguiendo el patrón de `tests/Concurrency/InvoiceNumberingConcurrencyTest.php` — leerlo primero y replicar su andamiaje literal (skip si falta el env, `migrate:fresh` con `--path` + `--realpath` explícitos, `DB::purge` en el hijo, resultado por exit code, `pcntl_waitpid` en el padre).

Escenario: N procesos llaman a `ArticlePriceService::setPrice()` sobre el **mismo artículo y frecuencia** a la vez. Aserción: exactamente una fila activa persistida; los demás procesos terminan con `OverlappingArticlePriceException`.

- [ ] **Step 2: Ejecutar contra MySQL**

Run: `RUN_CONCURRENCY_IT=1 LARABILL_TEST_MYSQL_DATABASE=larabill_test php83 vendor/bin/pest tests/Concurrency/ArticlePriceConcurrencyTest.php`
Expected: PASS.

- [ ] **Step 3: Probar la SENSIBILIDAD del test (obligatorio)**

Comentar temporalmente la línea `Article::query()->whereKey(...)->lockForUpdate()->first();` del servicio y repetir el paso 2.
Expected: FAIL — más de una fila activa. Si pasa igual, el test es teatro y hay que rehacerlo.

Restaurar la línea y confirmar: `git diff src/Services/ArticlePriceService.php` vacío.

- [ ] **Step 4: Confirmar que la suite por defecto no lo carga**

Run: `php83 vendor/bin/pest --parallel`
Expected: PASS, sin ejecutar el fichero de concurrencia.

- [ ] **Step 5: Commit**

```bash
git add tests/Concurrency/ArticlePriceConcurrencyTest.php
git commit -m "test(pricing): prove the article price lock serialises concurrent writes (AID-601)"
```

---

### Task 7: ADR-012, superficie y entrega

**Files:**
- Create: `docs/ADR-012-article-price-overlap-invariant.md`
- Modify: `docs/api-surface.md`, `CHANGELOG.md`, `README.md`, `CLAUDE.md`

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: el registro de la decisión y la entrega documentada.

- [ ] **Step 1: Escribir ADR-012**

Formato de `docs/ADR-011-*.md` (cabecera `Status: Accepted`, `Date`, `Relates`, secciones en español). Contenido obligatorio:

- **Contexto:** el índice promete en su comentario una garantía que no da; la invariante existe tres veces en lectura y una en la UI del consumidor, y falta en la escritura. El defecto es de dinero (`getPriceFor()` sin orden → `PricingService` → líneas de factura).
- **Decisión:** garantía en la capa de aplicación; hook como red, servicio con lock como garantía; rechazar sin modo de reemplazo (YAGNI — el price-history es expresable con dos escrituras explícitas).
- **Por qué el comentario del índice se queda como está:** la migración está en el manifiesto con su `sha256` y `ShippedMigrationImmutabilityTest` (AID-398/AID-412) exige byte-identidad; la inmutabilidad de lo publicado pesa más que la exactitud de un comentario. **Dejar constancia de que verificar ese cambio con `MigrationOrderConsistencyTest` da falso verde.**
- **Referencia a ADR-004**, cuya promesa de precios por frecuencia es la que aquí se cumple.
- **Coste declarado:** una consulta extra por `save` de precio activo (`is_active` es `true` por defecto).
- **Límite declarado:** el hook solo no garantiza nada bajo concurrencia; la garantía es el servicio.
- **Criterios de reapertura:** un consumidor real que pida reemplazo automático.

- [ ] **Step 2: Registrar la superficie**

En `docs/api-surface.md`, actualizar los conteos de `Exceptions/` (7 → 8) y `Console/` (1 → 2), y añadir `ArticlePriceService` a la tabla de servicios `@api`.

- [ ] **Step 3: Entrada de CHANGELOG bajo `[Unreleased]`**

En inglés, siguiendo el estilo de las entradas de v6.6.0/v6.7.0: `### Added` para las tres piezas nuevas, `### Fixed` para el determinismo de lectura, **cambio de comportamiento en negrita** (a partir de ahora se rechazan escrituras que antes se aceptaban) y mención explícita del comando como camino para datos existentes.

- [ ] **Step 4: README**

Documentar `larabill:diagnose-price-overlaps` junto al resto de comandos y una línea sobre la invariante en la sección de precios.

- [ ] **Step 5: CLAUDE.md**

Añadir a «Anti-patterns frecuentes»: sembrar dos precios activos solapados de la misma `(artículo, frecuencia)`; y a «Reglas inviolables»: la escritura de precios pasa por `ArticlePriceService` cuando importa la concurrencia.

- [ ] **Step 6: Suite completa y gates**

```bash
php83 vendor/bin/pest --parallel
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

Expected: todo verde, incluidos `ShippedMigrationImmutabilityTest`, `MigrationOrderConsistencyTest` y `SurfaceTaxonomyTest`.

- [ ] **Step 7: Commit y PR**

```bash
git add docs/ CHANGELOG.md README.md CLAUDE.md
git commit -m "docs(pricing): record ADR-012 and ship the article price invariant (AID-601)"
git push -u origin <rama>
```

PR en inglés, con: el defecto de dinero, la tabla de opciones descartadas, la evidencia de compatibilidad con el consumidor de referencia, y la nota de que no se toca ninguna migración.

---

## Verificación final antes de pedir revisión

- [ ] `php83 vendor/bin/pest --parallel` verde
- [ ] `RUN_CONCURRENCY_IT=1 … pest tests/Concurrency/ArticlePriceConcurrencyTest.php` verde **y con sensibilidad demostrada**
- [ ] `php83 vendor/bin/pint --test` y `phpstan` (level 8) limpios
- [ ] `git diff main --stat -- database/` **vacío** (ninguna migración tocada)
- [ ] `git diff main -- tests/Contract/release-migration-manifest.json` **vacío**
- [ ] CI en verde, incluido el job MySQL 8
- [ ] Conformidad de `clientes` contra el commit candidato exacto (constitución §14) antes de etiquetar `6.8.0`
