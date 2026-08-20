# AID-974 — Unificación del resolver de `ArticleOverride` — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el paquete resuelva «el override vigente de este cliente para este artículo» por un único camino, con ventana de vigencia correcta, orden determinista y rechazo de solapes.

**Architecture:** La garantía vive en la capa de aplicación, como en ADR-012: una definición SQL única del solape y de la vigencia, un resolver que ordena, un hook `saving` como red y un servicio de escritura con lock sobre la fila padre de `articles` como garantía. Sin migraciones, sin backfill, sin cambios de esquema.

**Tech Stack:** PHP 8.3, Laravel 12/13, Pest 4, MySQL 8 / MariaDB 11.4 / SQLite.

**Spec:** `docs/superpowers/specs/2026-08-19-aid-974-override-resolver-unification.md` (rev 3, aprobado tras dos rondas adversariales).

## Global Constraints

- **Sin migraciones, sin backfill, sin cambios de esquema.** El unique `(customer_id, article_id, valid_from)` se queda como está.
- **Ninguna firma pública cambia.** `Article::getActiveOverrideFor(int|string $customerId): ?ArticleOverride` y los seis métodos de `PricingService` conservan firma exacta. Si una firma tuviera que cambiar, **parar**: decae la clasificación MINOR (spec D7).
- **PHP local: `php83`.** `"$HOME/Library/Application Support/Herd/bin/php83"`. `composer` vive en `~/Library/Application Support/Herd/bin/composer`.
- **Suite:** `php83 vendor/bin/pest` (el `memory_limit=1G` ya está en `phpunit.xml.dist`).
- **Desempate canónico:** `valid_from` DESC, `id` DESC (igual que ADR-012).
- **Vigencia:** inclusiva y de grano **día** en ambos extremos.
- **Nombres de helpers de test:** prefijo `aid974` — los helpers file-level de Pest viven en el namespace global de la suite y colisionan entre ficheros.

---

## File Structure

| Fichero | Responsabilidad | Acción |
|---|---|---|
| `src/Models/ArticleOverride.php` | Definición SQL única de vigencia y solape; hook `saving` | Modificar |
| `src/Models/Article.php` | Resolver único con orden determinista | Modificar |
| `src/Services/PricingService.php` | Delegar la resolución | Modificar |
| `src/Services/ArticleOverrideService.php` | Escritura con lock — la garantía | **Crear** |
| `src/Exceptions/OverlappingArticleOverrideException.php` | Contrato público del rechazo | **Crear** |
| `tests/Unit/Models/ArticleOverrideValidityTest.php` | Ventana inclusiva y paridad query↔modelo | **Crear** |
| `tests/Unit/Models/ArticleOverrideOverlapTest.php` | Definición de solape y hook | **Crear** |
| `tests/Unit/Models/ArticleOverrideResolutionTest.php` | Resolver: vigencia + orden | **Crear** |
| `tests/Unit/Services/ArticleOverrideServiceTest.php` | Servicio: caso válido, rechazo, rollback | **Crear** |
| `tests/Concurrency/ArticleOverrideConcurrencyTest.php` | Fork test STD-004 | **Crear** |
| `CHANGELOG.md`, `docs/api-surface.md` | Contrato con el consumidor | Modificar |

---

### Task 1: La ventana de vigencia es inclusiva por día

Primero, porque todo lo demás depende de que la semántica temporal sea correcta. Hoy no lo es **en ningún motor**, y los dos discrepan entre sí (spec D2bis).

**Files:**
- Modify: `src/Models/ArticleOverride.php` (`scopeValidAt`, `:106`)
- Test: `tests/Unit/Models/ArticleOverrideValidityTest.php` (crear)

**Interfaces:**
- Produces: `scopeValidAt(Builder $query, Carbon $date)` — firma intacta, semántica corregida a grano día.

- [ ] **Step 1: Escribir los tests que fallan**

```php
<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-974 D2bis: valid_from/valid_to son columnas `date`. La ventana es
 * INCLUSIVA y de grano DÍA en ambos extremos.
 */
beforeEach(function () {
    $this->article  = Article::factory()->withoutPrices()->create();
    $this->customer = TestUser::factory()->create();
});

/** Siembra un override activo con el rango dado. */
function aid974SeedOverride(Article $article, string|int $customerId, ?string $from, ?string $to): ArticleOverride
{
    return ArticleOverride::factory()->for($article)->create([
        'customer_id'  => $customerId,
        'custom_price' => FixedDecimal::ofUnscaled(2400, 2),
        'valid_from'   => $from,
        'valid_to'     => $to,
        'is_active'    => true,
    ]);
}

it('keeps an override valid for the whole of its last day', function () {
    aid974SeedOverride($this->article, $this->customer->id, null, '2026-08-19');

    // 21:08 del último día: sigue vigente. Hoy devuelve CADUCADO en MySQL y
    // en SQLite, y por motivos distintos.
    $found = ArticleOverride::query()
        ->validAt(Carbon::parse('2026-08-19 21:08:00'))
        ->get();

    expect($found)->toHaveCount(1);
});

it('keeps an override valid from the very start of its first day', function () {
    aid974SeedOverride($this->article, $this->customer->id, '2026-08-19', null);

    expect(ArticleOverride::query()->validAt(Carbon::parse('2026-08-19 00:00:01'))->get())->toHaveCount(1);
});

it('expires an override the day after its last day', function () {
    aid974SeedOverride($this->article, $this->customer->id, null, '2026-08-19');

    expect(ArticleOverride::query()->validAt(Carbon::parse('2026-08-20 00:00:01'))->get())->toBeEmpty();
});

it('agrees with the model predicate on all three borders', function () {
    $override = aid974SeedOverride($this->article, $this->customer->id, '2026-08-10', '2026-08-19');

    foreach (['2026-08-10 09:00:00', '2026-08-19 21:08:00', '2026-08-20 00:00:01'] as $instant) {
        $at      = Carbon::parse($instant);
        $byQuery = ArticleOverride::query()->whereKey($override->getKey())->validAt($at)->exists();

        expect($byQuery)->toBe($override->isValidAt($at), "desacuerdo query vs modelo en {$instant}");
    }
});
```

- [ ] **Step 2: Correr y ver el rojo correcto**

```bash
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest tests/Unit/Models/ArticleOverrideValidityTest.php
```

Esperado: el primero falla (0 encontrados donde se espera 1). Si falla otro distinto, **leer el mensaje antes de tocar nada** — puede ser el fixture.

- [ ] **Step 3: Normalizar el scope a grano día**

En `src/Models/ArticleOverride.php`, sustituir el cuerpo de `scopeValidAt()`:

```php
public function scopeValidAt(Builder $query, Carbon $date): void
{
    // Las columnas son `date`: comparar contra un instante con hora hace que
    // un override caduque durante su último día, y con resultados distintos
    // por motor (AID-974 D2bis). Se normaliza aquí, en un único punto.
    $day = $date->toDateString();

    $query->where(function ($q) use ($day) {
        $q->whereNull('valid_from')
            ->orWhereDate('valid_from', '<=', $day);
    })
        ->where(function ($q) use ($day) {
            $q->whereNull('valid_to')
                ->orWhereDate('valid_to', '>=', $day);
        });
}
```

- [ ] **Step 4: Verde en SQLite**

```bash
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest tests/Unit/Models/ArticleOverrideValidityTest.php
```

- [ ] **Step 5: Verde también en MySQL — obligatorio, los motores discrepaban**

```bash
PW=$(grep -iE "^[[:space:]]*password[[:space:]]*=" ~/.my.cnf | head -1 | sed -E 's/^[[:space:]]*password[[:space:]]*=[[:space:]]*//I; s/^["'"'"']//; s/["'"'"']$//')
LARABILL_TEST_MYSQL_HOST=127.0.0.1 LARABILL_TEST_MYSQL_PORT=3306 \
LARABILL_TEST_MYSQL_DATABASE=larabill_test LARABILL_TEST_MYSQL_USERNAME=root \
LARABILL_TEST_MYSQL_PASSWORD="$PW" \
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest tests/Integration/Mysql
```

- [ ] **Step 6: Commit**

```bash
git add src/Models/ArticleOverride.php tests/Unit/Models/ArticleOverrideValidityTest.php
git commit -m "fix(overrides): the validity window is day-grain inclusive (AID-974)"
```

---

### Task 2: `scopeOverlapping()` como definición única del solape

**Files:**
- Modify: `src/Models/ArticleOverride.php`
- Test: `tests/Unit/Models/ArticleOverrideOverlapTest.php` (crear)

**Interfaces:**
- Produces: `scopeOverlapping(Builder $query, int|string $customerId, int $articleId, ?Carbon $validFrom, ?Carbon $validTo)` — filas **activas** de ese par cuyo intervalo interseca el rango; `NULL` es extremo abierto en ambos lados.

- [ ] **Step 1: Escribir los tests que fallan**

```php
it('detects two intersecting ranges', function () {
    aid974SeedOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    $conflicts = ArticleOverride::query()
        ->overlapping($this->customer->id, $this->article->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'))
        ->get();

    expect($conflicts)->toHaveCount(1);
});

it('does not treat disjoint ranges as a conflict', function () {
    aid974SeedOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect(ArticleOverride::query()
        ->overlapping($this->customer->id, $this->article->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-12-31'))
        ->get())->toBeEmpty();
});

it('treats NULL as an open end on both sides', function () {
    aid974SeedOverride($this->article, $this->customer->id, null, null);

    expect(ArticleOverride::query()
        ->overlapping($this->customer->id, $this->article->id, Carbon::parse('2030-01-01'), null)
        ->get())->toHaveCount(1);
});

it('ignores inactive rows', function () {
    $o = aid974SeedOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');
    $o->update(['is_active' => false]);

    expect(ArticleOverride::query()
        ->overlapping($this->customer->id, $this->article->id, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'))
        ->get())->toBeEmpty();
});

it('ignores other customers and other articles', function () {
    $other = TestUser::factory()->create();
    aid974SeedOverride($this->article, $other->id, '2026-01-01', '2026-06-30');

    expect(ArticleOverride::query()
        ->overlapping($this->customer->id, $this->article->id, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'))
        ->get())->toBeEmpty();
});
```

- [ ] **Step 2: Correr y ver el rojo**

```bash
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest tests/Unit/Models/ArticleOverrideOverlapTest.php
```

Esperado: FAIL, `Call to undefined method ...::overlapping()`.

- [ ] **Step 3: Implementar el scope**

```php
/**
 * Overlap condition — the SINGLE definition shared by the saving hook and
 * ArticleOverrideService (AID-974 D1). NULL is an open end on both sides.
 *
 * @param  Builder<static>  $query
 */
public function scopeOverlapping(
    Builder $query,
    int|string $customerId,
    int $articleId,
    ?Carbon $validFrom,
    ?Carbon $validTo,
): void {
    $from = $validFrom?->toDateString();
    $to   = $validTo?->toDateString();

    $query->where('customer_id', $customerId)
        ->where('article_id', $articleId)
        ->where('is_active', true)
        ->when($to !== null, fn (Builder $q) => $q->where(
            fn (Builder $inner) => $inner->whereNull('valid_from')->orWhereDate('valid_from', '<=', $to)
        ))
        ->when($from !== null, fn (Builder $q) => $q->where(
            fn (Builder $inner) => $inner->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from)
        ));
}
```

- [ ] **Step 4: Verde**

```bash
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest tests/Unit/Models/ArticleOverrideOverlapTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Models/ArticleOverride.php tests/Unit/Models/ArticleOverrideOverlapTest.php
git commit -m "feat(overrides): single overlap definition as a model scope (AID-974)"
```

---

### Task 3: El hook `saving` como red, con autoexclusión

**Files:**
- Create: `src/Exceptions/OverlappingArticleOverrideException.php`
- Modify: `src/Models/ArticleOverride.php` (añadir `booted()`)
- Test: `tests/Unit/Models/ArticleOverrideOverlapTest.php` (añadir)

**Interfaces:**
- Consumes: `scopeOverlapping()` de Task 2.
- Produces: `OverlappingArticleOverrideException` (`@api`), lanzada al guardar una fila activa que solapa.

- [ ] **Step 1: Escribir los tests que fallan**

```php
it('refuses to save an overlapping active override', function () {
    aid974SeedOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect(fn () => aid974SeedOverride($this->article, $this->customer->id, '2026-06-01', '2026-12-31'))
        ->toThrow(OverlappingArticleOverrideException::class);
});

it('allows saving disjoint ranges', function () {
    aid974SeedOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect(fn () => aid974SeedOverride($this->article, $this->customer->id, '2026-07-01', '2026-12-31'))
        ->not->toThrow(OverlappingArticleOverrideException::class);
});

it('lets an existing override be saved again without changing its range', function () {
    // Sin autoexclusión, la fila se detectaría a sí misma como conflicto y
    // los overrides serían ineditables (AID-974 D4).
    $override = aid974SeedOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect(fn () => $override->update(['reason' => 'renegociado']))
        ->not->toThrow(OverlappingArticleOverrideException::class);
});

it('refuses to reactivate an inactive override onto an occupied range', function () {
    $old = aid974SeedOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');
    $old->update(['is_active' => false]);
    aid974SeedOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect(fn () => $old->update(['is_active' => true]))
        ->toThrow(OverlappingArticleOverrideException::class);
});

it('names the conflicting id and range in the message', function () {
    $first = aid974SeedOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    try {
        aid974SeedOverride($this->article, $this->customer->id, '2026-06-01', '2026-12-31');
        test()->fail('se esperaba OverlappingArticleOverrideException');
    } catch (OverlappingArticleOverrideException $e) {
        expect($e->getMessage())->toContain((string) $first->getKey())
            ->and($e->getMessage())->toContain('2026-06-30');
    }
});
```

- [ ] **Step 2: Correr y ver el rojo**

```bash
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest tests/Unit/Models/ArticleOverrideOverlapTest.php
```

- [ ] **Step 3: Crear la excepción**

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\ArticleOverride;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A customer/article pair may have at most ONE active override on any given
 * date (AID-974). Sister of OverlappingArticlePriceException (ADR-012).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class OverlappingArticleOverrideException extends RuntimeException
{
    /** @param  Collection<int, ArticleOverride>  $conflicts */
    public static function forRange(ArticleOverride $candidate, Collection $conflicts): self
    {
        $detail = $conflicts
            ->map(fn (ArticleOverride $c): string => sprintf(
                '#%s [%s .. %s]',
                $c->getKey(),
                $c->valid_from?->toDateString() ?? 'abierto',
                $c->valid_to?->toDateString()   ?? 'abierto',
            ))
            ->implode(', ');

        return new self(sprintf(
            'The override range [%s .. %s] for customer %s on article %s overlaps an active one: %s. '.
            'A customer/article pair may hold at most one active override on any given date.',
            $candidate->valid_from?->toDateString() ?? 'abierto',
            $candidate->valid_to?->toDateString()   ?? 'abierto',
            (string) $candidate->customer_id,
            (string) $candidate->article_id,
            $detail,
        ));
    }
}
```

- [ ] **Step 4: Añadir el hook con autoexclusión**

```php
protected static function booted(): void
{
    static::saving(function (self $override): void {
        if (! $override->is_active) {
            return;
        }

        $conflicts = self::query()
            ->overlapping(
                $override->customer_id,
                $override->article_id,
                $override->valid_from,
                $override->valid_to,
            )
            // Autoexclusión en update: sin esto, la fila se detectaría a sí
            // misma como conflicto y ningún override podría editarse.
            ->when($override->exists, fn (Builder $query) => $query->whereKeyNot($override->getKey()))
            ->get();

        if ($conflicts->isNotEmpty()) {
            throw OverlappingArticleOverrideException::forRange($override, $conflicts);
        }
    });
}
```

- [ ] **Step 5: Verde, y suite completa (el hook toca todas las siembras de override)**

```bash
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest tests/Unit/Models/ArticleOverrideOverlapTest.php
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest --parallel
```

Si otra suite se pone roja, casi seguro es una **fixture** que sembraba solapes sin saberlo: darles rangos disjuntos, no debilitar el hook.

- [ ] **Step 6: Commit**

```bash
git add src/Exceptions/OverlappingArticleOverrideException.php src/Models/ArticleOverride.php tests/Unit/Models/ArticleOverrideOverlapTest.php
git commit -m "feat(overrides): reject overlapping active overrides on save (AID-974)"
```

---

### Task 4: Resolver único con orden determinista

**Files:**
- Modify: `src/Models/Article.php` (`getActiveOverrideFor`, `:375`)
- Test: `tests/Unit/Models/ArticleOverrideResolutionTest.php` (crear)

**Interfaces:**
- Consumes: `scopeValidAt()` (Task 1).
- Produces: `Article::resolveOverrideFor(int|string $customerId, Carbon $at): ?ArticleOverride` — nuevo. `getActiveOverrideFor(int|string $customerId): ?ArticleOverride` **conserva firma** y delega con `now()`.

- [ ] **Step 1: Escribir los tests que fallan**

```php
it('ignores an expired override', function () {
    aid974SeedOverride($this->article, $this->customer->id, '2020-01-01', '2020-12-31');

    expect($this->article->getActiveOverrideFor($this->customer->id))->toBeNull();
});

it('ignores an override that is not valid yet', function () {
    aid974SeedOverride($this->article, $this->customer->id, '2099-01-01', null);

    expect($this->article->getActiveOverrideFor($this->customer->id))->toBeNull();
});

it('picks the most recent valid_from among valid candidates', function () {
    // Duplicado legacy: se siembra SIN eventos, porque el hook de la Task 3
    // rechazaría el segundo. Es justo el estado que la lectura determinista
    // existe para tolerar.
    $older = aid974SeedOverride($this->article, $this->customer->id, '2026-01-01', null);
    $newer = ArticleOverride::withoutEvents(fn () => aid974SeedOverride($this->article, $this->customer->id, '2026-06-01', null));

    expect($this->article->fresh()->getActiveOverrideFor($this->customer->id)->getKey())->toBe($newer->getKey())
        ->and($older->getKey())->not->toBe($newer->getKey());
});

it('breaks a tie on id when both have an open start', function () {
    // Dos valid_from NULL: el unique (customer_id, article_id, valid_from) no
    // colisiona con NULLs duplicados. Con dos fechas iguales NO NULAS sí
    // colisionaría, y el test sería insembrable.
    $first  = aid974SeedOverride($this->article, $this->customer->id, null, null);
    $second = ArticleOverride::withoutEvents(fn () => aid974SeedOverride($this->article, $this->customer->id, null, null));

    expect($this->article->fresh()->getActiveOverrideFor($this->customer->id)->getKey())
        ->toBe(max($first->getKey(), $second->getKey()));
});

it('resolves at an explicit instant, not only now', function () {
    aid974SeedOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect($this->article->resolveOverrideFor($this->customer->id, Carbon::parse('2026-03-15')))->not->toBeNull()
        ->and($this->article->resolveOverrideFor($this->customer->id, Carbon::parse('2027-03-15')))->toBeNull();
});
```

- [ ] **Step 2: Correr y ver el rojo**

```bash
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest tests/Unit/Models/ArticleOverrideResolutionTest.php
```

- [ ] **Step 3: Implementar el resolver y la delegación**

```php
/**
 * Resolve the override in force for a customer at a given instant.
 *
 * Single resolution path (AID-974 D3): validity comes from the shared scope,
 * and the order is deterministic — most recent start wins, id breaks ties.
 * NULL sorts lowest in MySQL and SQLite, so a dated row beats a legacy
 * open-start one. Predictability, not intent-guessing: the real fix is not
 * having the duplicate, which ArticleOverrideService guarantees.
 */
public function resolveOverrideFor(int|string $customerId, Carbon $at): ?ArticleOverride
{
    return $this->overrides()
        ->where('customer_id', $customerId)
        ->where('is_active', true)
        ->validAt($at)
        ->orderByDesc('valid_from')
        ->orderByDesc('id')
        ->first();
}

/**
 * Get active price override for a customer.
 */
public function getActiveOverrideFor(int|string $customerId): ?ArticleOverride
{
    return $this->resolveOverrideFor($customerId, now());
}
```

- [ ] **Step 4: Verde + snapshot de contrato intacto**

```bash
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest tests/Unit/Models/ArticleOverrideResolutionTest.php
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest tests/Unit/Contract
```

El segundo es el gate que prueba que **no se tocó ninguna firma**. Si sale rojo, se cambió una firma: **parar** y revisar contra el spec D3/D7.

- [ ] **Step 5: Commit**

```bash
git add src/Models/Article.php tests/Unit/Models/ArticleOverrideResolutionTest.php
git commit -m "feat(overrides): one deterministic resolver, signatures untouched (AID-974)"
```

---

### Task 5: `PricingService` delega

**Files:**
- Modify: `src/Services/PricingService.php` (`getActiveOverride`, `:60`)
- Test: `tests/Unit/Models/ArticleOverrideResolutionTest.php` (añadir)

**Interfaces:**
- Consumes: `Article::getActiveOverrideFor()` (Task 4).
- Produces: los seis métodos públicos de `PricingService` heredan vigencia y orden. Firmas intactas.

- [ ] **Step 1: Escribir el test que falla — los SEIS métodos**

```php
it('applies validity through every public pricing entry point', function () {
    aid974SeedOverride($this->article, $this->customer->id, '2020-01-01', '2020-12-31'); // caducado
    $service = new PricingService;
    $article = $this->article->fresh();

    expect($service->getActiveOverride($article, $this->customer->id))->toBeNull()
        ->and($service->hasActiveOverride($article, $this->customer->id))->toBeFalse()
        ->and($service->getEffectivePrice($article, BillingFrequency::MONTHLY, $this->customer->id))
            ->toBe($article->getPriceFor(BillingFrequency::MONTHLY))
        ->and($service->createPricingDetails($article, BillingFrequency::MONTHLY, $this->customer->id)->pricingRule)
            ->toBe('base_price');
});
```

Los otros dos (`getEffectivePriceForService`, `createPricingDetailsForService`) delegan en los anteriores por construcción y se cubren con un `ArticleServiceStatus` en el mismo test.

- [ ] **Step 2: Correr y ver el rojo** — hoy devuelve el override caducado.

- [ ] **Step 3: Delegar**

```php
public function getActiveOverride(Article $article, int|string $customerId): ?ArticleOverride
{
    // Single resolution path (AID-974): validity + deterministic order.
    return $article->getActiveOverrideFor($customerId);
}
```

- [ ] **Step 4: Verde** y `pest --parallel`.

- [ ] **Step 5: Commit**

```bash
git add src/Services/PricingService.php tests/Unit/Models/ArticleOverrideResolutionTest.php
git commit -m "fix(pricing): quoting no longer applies expired overrides (AID-974)"
```

---

### Task 6: `ArticleOverrideService` — la garantía

**Files:**
- Create: `src/Services/ArticleOverrideService.php`
- Test: `tests/Unit/Services/ArticleOverrideServiceTest.php` (crear)

**Interfaces:**
- Consumes: `scopeOverlapping()` (Task 2), hook (Task 3).
- Produces: `setOverride(Article $article, int|string $customerId, FixedDecimal $customPrice, ?Carbon $validFrom = null, ?Carbon $validTo = null, ?string $reason = null): ArticleOverride` — **create-only**.

- [ ] **Step 1: Escribir los tests que fallan**

```php
it('creates the override under lock', function () {
    $override = (new ArticleOverrideService)->setOverride(
        $this->article, $this->customer->id, FixedDecimal::ofUnscaled(2400, 2),
    );

    expect($override->exists)->toBeTrue()
        ->and($override->is_active)->toBeTrue();
});

it('refuses an overlapping range and leaves no row behind', function () {
    aid974SeedOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');
    $before = ArticleOverride::count();

    expect(fn () => (new ArticleOverrideService)->setOverride(
        $this->article, $this->customer->id, FixedDecimal::ofUnscaled(1000, 2),
        Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'),
    ))->toThrow(OverlappingArticleOverrideException::class);

    // Rollback: el rechazo no deja fila escrita.
    expect(ArticleOverride::count())->toBe($before);
});

it('refuses an inverted range', function () {
    expect(fn () => (new ArticleOverrideService)->setOverride(
        $this->article, $this->customer->id, FixedDecimal::ofUnscaled(2400, 2),
        Carbon::parse('2026-12-31'), Carbon::parse('2026-01-01'),
    ))->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Correr y ver el rojo** (clase inexistente).

- [ ] **Step 3: Implementar el servicio**

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Write path for customer price overrides — the GUARANTEE of the non-overlap
 * invariant (AID-974 D5), sister of ArticlePriceService (ADR-012 D3).
 *
 * The saving hook is the safety net; it cannot serialise concurrent writers,
 * because each checks before the other writes. This does: it locks the parent
 * `articles` row first. The parent is locked rather than the overrides,
 * because FOR UPDATE does not serialise an insert that matches zero rows.
 *
 * Create-only on purpose: replacing a live override silently would be
 * deciding the consumer's price.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class ArticleOverrideService
{
    /** @throws \AichaDigital\Larabill\Exceptions\OverlappingArticleOverrideException */
    public function setOverride(
        Article $article,
        int|string $customerId,
        FixedDecimal $customPrice,
        ?Carbon $validFrom = null,
        ?Carbon $validTo = null,
        ?string $reason = null,
    ): ArticleOverride {
        if ($validFrom !== null && $validTo !== null && $validFrom->gt($validTo)) {
            throw new InvalidArgumentException('valid_from cannot be later than valid_to.');
        }

        return DB::transaction(function () use ($article, $customerId, $customPrice, $validFrom, $validTo, $reason): ArticleOverride {
            // Serialise every override write of this article (see class docblock).
            Article::query()->whereKey($article->getKey())->lockForUpdate()->first();

            // The saving hook re-checks with the same condition; validating here
            // is what makes the check meaningful, because it happens under lock.
            return ArticleOverride::query()->create([
                'article_id'   => $article->getKey(),
                'customer_id'  => $customerId,
                'custom_price' => $customPrice,
                'valid_from'   => $validFrom,
                'valid_to'     => $validTo,
                'reason'       => $reason,
                'is_active'    => true,
            ]);
        });
    }
}
```

- [ ] **Step 4: Verde.**

- [ ] **Step 5: Commit**

```bash
git add src/Services/ArticleOverrideService.php tests/Unit/Services/ArticleOverrideServiceTest.php
git commit -m "feat(overrides): write service with parent-row lock as the guarantee (AID-974)"
```

---

### Task 7: Regresión de `updateEffectivePrice()`

Único cambio real en esa ruta: la elección determinista. **No** corrige caducidad ahí — ese camino ya filtraba fechas (spec §5).

**Files:**
- Test: `tests/Unit/Models/ArticleOverrideResolutionTest.php` (añadir)

- [ ] **Step 1: Escribir el test**

```php
it('freezes the deterministic winner into the contract on revision', function () {
    $article = Article::factory()->monthly(2900)->create();
    aid974SeedOverride($article, $this->customer->id, '2026-01-01', null);
    $newer = ArticleOverride::withoutEvents(fn () => aid974SeedOverride($article, $this->customer->id, '2026-06-01', null));

    $service = ArticleServiceStatus::factory()->create([
        'customer_id'       => $this->customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'effective_price'   => cents(2900),
    ]);

    $service->fresh()->updateEffectivePrice();

    expect($service->fresh()->current_override_id)->toBe($newer->getKey())
        ->and($service->fresh()->effective_price->unscaledValue())->toBe($newer->custom_price->unscaledValue());
});
```

- [ ] **Step 2: Correr, verde o rojo según el orden de índice.** Si sale verde a la primera, confirmarlo con la mutación: retirar los `orderBy` de Task 4 debe ponerlo rojo. Si no se pone rojo, el fixture no discrimina — cambiar el orden de inserción.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Models/ArticleOverrideResolutionTest.php
git commit -m "test(overrides): pin deterministic winner on contract revision (AID-974)"
```

---

### Task 8: Fork test bajo STD-004

**Files:**
- Create: `tests/Concurrency/ArticleOverrideConcurrencyTest.php`

**STD-004 es un gate real** (`tests/ConcurrencyGateContractTest.php`) que corre en la suite normal: exige barrera `time_sleep_until` (`:69`) y persistir la excepción real del hijo (`:91`). **No copiar `ArticlePriceConcurrencyTest`**: está declarado en `std004PendingBarrier()`, exento por deuda histórica.

- [ ] **Step 1: Escribir el fork test** siguiendo el patrón de `tests/Concurrency/RecurringBillingConcurrencyTest.php`: barrera en instante absoluto, `DB::purge()` en el hijo, resultado por exit code, y `file_put_contents($resultDir.'/'.getmypid().'.err', get_class($e).': '.$e->getMessage())` en el `catch`.

Los candidatos van con **`valid_from = null`**: con fechas distintas el unique `(customer_id, article_id, valid_from)` haría el trabajo del lock y enmascararía el defecto.

Aserción: de N hijos que llaman a `setOverride()` a la vez, **exactamente uno** persiste; los demás fallan con `OverlappingArticleOverrideException`.

- [ ] **Step 2: Correr con el gate activo**

```bash
PW=$(grep -iE "^[[:space:]]*password[[:space:]]*=" ~/.my.cnf | head -1 | sed -E 's/^[[:space:]]*password[[:space:]]*=[[:space:]]*//I; s/^["'"'"']//; s/["'"'"']$//')
RUN_CONCURRENCY_IT=1 LARABILL_TEST_MYSQL_HOST=127.0.0.1 LARABILL_TEST_MYSQL_PORT=3306 \
LARABILL_TEST_MYSQL_DATABASE=larabill_test LARABILL_TEST_MYSQL_USERNAME=root LARABILL_TEST_MYSQL_PASSWORD="$PW" \
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest tests/Concurrency/ArticleOverrideConcurrencyTest.php
```

- [ ] **Step 3: Prueba de sensibilidad — OBLIGATORIA**

Copiar `src/Services/ArticleOverrideService.php` al scratchpad **antes** de mutar (nunca restaurar con `git checkout`: se lleva trabajo sin commitear). Retirar la línea del `lockForUpdate()`, verificar por `grep` que la mutación **aplicó**, correr, y confirmar que **escriben varios**. Restaurar desde la copia.

- [ ] **Step 4: Calibrar el recuento**

Suelo: el mínimo N que discrimina. Techo: `(N-1) × coste_por_operación < innodb_lock_wait_timeout`. **Se mide por motor** — MySQL y MariaDB no discriminan igual (AID-836). Documentar ambos en el docblock.

- [ ] **Step 5: Verificar que el gate STD-004 pasa**

```bash
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest tests/ConcurrencyGateContractTest.php
```

- [ ] **Step 6: Commit**

```bash
git add tests/Concurrency/ArticleOverrideConcurrencyTest.php
git commit -m "test(overrides): fork test proving the write lock (AID-974)"
```

---

### Task 9: Contrato con el consumidor

**Files:**
- Modify: `CHANGELOG.md`, `docs/api-surface.md`

- [ ] **Step 1: `docs/api-surface.md`** — añadir `ArticleOverrideService` y `OverlappingArticleOverrideException` como `@api`.

- [ ] **Step 2: CHANGELOG bajo `[Unreleased]`** con `### Added` (las dos clases nuevas) y `### Fixed` con **viejo y nuevo** en las tres correcciones observables:

1. La cotización dejaba de aplicar overrides caducados o no vigentes.
2. Un override cuyo último día era hoy se ignoraba durante todo ese día, **con resultados distintos según el motor**.
3. Ante varios overrides vigentes, la elección dependía del índice; ahora es determinista.

Y una línea de aviso: guardar un override que solape con otro activo **pasa a fallar**, donde antes se persistía en silencio. Consulta de una línea para que el consumidor compruebe si tiene solapes antes de actualizar.

- [ ] **Step 3: Gates completos**

```bash
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pest --parallel
"$HOME/Library/Application Support/Herd/bin/php83" -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
"$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/pint
```

Más los cuatro harness gateados si algo tocó config o defaults.

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md docs/api-surface.md
git commit -m "docs: record the override resolver unification (AID-974)"
```
