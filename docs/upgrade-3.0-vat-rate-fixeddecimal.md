# Upgrade guide: 2.x → 3.0 (VatCategory / CountryVatRate → FixedDecimal)

larabill 3.0 finishes the "no decimals / no float in rate systems" program
(AID-237 → AID-240 → AID-242 → AID-246). The two large VAT rate models swap
their legacy base-100 `int` / `float` API for the same `FixedDecimal` value
object the rest of the package already uses.

If your application does **not** read `VatCategory::vat_rate`,
`CountryVatRate::standard_rate`/`reduced_rates`, or call their rate helper
methods, this release is transparent — no code changes are required.

## Why

`VatCategory.vat_rate` and `CountryVatRate.standard_rate` were base-100 `int`
columns surfaced through hand-written `percentageToBase100` / `base100ToPercentage`
helpers. That parallel rate API was the last system still bypassing
`FixedDecimal`. Both columns were **already** `integer` base-100 (and
`reduced_rates` already `json`), so **there is no schema change and no data
migration** — only the model API changes.

## Database

Nothing to do. `vat_categories.vat_rate`, `country_vat_rates.standard_rate`
(both `integer`) and `country_vat_rates.reduced_rates` (`json`) are unchanged.
The stored unscaled integers (`2150` = `21.50%`) are reinterpreted by the
`FixedDecimal:2` cast.

## Breaking changes — VatCategory

### Reading the rate

```php
// 2.x — base-100 int
$category->vat_rate;        // 2150

// 3.0 — FixedDecimal:2
$category->vat_rate;                    // FixedDecimal (2150 unscaled)
$category->vat_rate->unscaledValue();   // 2150  (base-100)
$category->vat_rate->toDecimalString(); // "21.50"
$category->vat_rate->toFloat();         // 21.5  (lossy — display only)
```

### Writing the rate

The cast rejects scalars. Build a `FixedDecimal` explicitly:

```php
use AichaDigital\Lara100\ValueObjects\FixedDecimal;

VatCategory::create([
    'name'          => 'Standard',
    'country_code'  => 'ES',
    'vat_rate'      => FixedDecimal::ofUnscaled(2100, 2), // or ofFloat(21.00, 2)
    'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
]);
```

### Rate getters now return FixedDecimal

`getRate()`, and the statics `getVatRate()`, `getStandardRate()`,
`getReducedRate()`, `getSuperReducedRate()` return `?FixedDecimal` (were
`int`/`?float`). `findByRate(string $country, float $percentage)` still takes the
percentage as a `float`.

### Removed helpers

`percentageToBase100()`, `base100ToPercentage()`, `getVatRateAsPercentage()`,
`setVatRateFromPercentage()` are gone — `vat_rate` already exposes the value as a
`FixedDecimal`. Replace `setVatRateFromPercentage(15.5)` with
`update(['vat_rate' => FixedDecimal::ofFloat(15.5, 2)])`.

## Breaking changes — CountryVatRate

### Reading rates

```php
// 2.x
$rate->standard_rate;              // 2100 (int)
$rate->getRateForCategory('food'); // 1000 (int)

// 3.0
$rate->standard_rate;                       // FixedDecimal (2100 unscaled)
$rate->getRateForCategory('food');          // FixedDecimal (?FixedDecimal)
$rate->getReducedRate('food');              // FixedDecimal (?FixedDecimal)
$rate->getReducedRates();                   // ['food' => FixedDecimal, ...]
$rate->getStandardRate();                   // FixedDecimal
$rate->getDefaultRateForCountry('ES');      // FixedDecimal (static)
$rate->getAllRates();                       // ['standard' => FixedDecimal, 'reduced' => [FixedDecimal]]
```

### reduced_rates stays a raw integer JSON map

The `reduced_rates` attribute is still a base-100 **integer** map (storage
representation). Only the semantic getters above materialize `FixedDecimal`.

```php
$rate->reduced_rates;            // ['general' => 1000, 'super_reduced' => 400]  (int)
$rate->getReducedRate('general') // FixedDecimal (1000 unscaled)
```

### Mutator takes FixedDecimal

```php
// 2.x
$rate->setReducedRate('books', 500);

// 3.0
$rate->setReducedRate('books', FixedDecimal::ofUnscaled(500, 2));
```

### Removed helpers

`percentageToBase100()`, `base100ToPercentage()`,
`getRateForCategoryAsPercentage()`, `getStandardRateAsPercentage()`,
`setStandardRateFromPercentage()`, `getReducedRatesAsPercentages()`,
`getReducedRateAsPercentage()`, `setReducedRateFromPercentage()` are gone.

### Range scopes/finders unchanged in signature

`scopeByRate(?float $min, ?float $max)`, `findByStandardRateRange(float, float)`
and `findSimilarRates(float $target, float $tolerance)` still take **percentage
floats** (e.g. `byRate(18.0, 23.0)`) and convert internally to the stored
base-100 integer. `findByStandardRateRange` is now a clean inclusive range (the
old odd/even heuristic was removed).

## Behavior fixes shipped with the type change

- `VatCategory::isExempt()` now uses `vat_rate->isZero()`. The old
  `vat_rate === 0.0` comparison never matched the integer column, so a zero-rate
  non-`exempt` category was wrongly reported as non-exempt.
- `DestinationVatService::getDestinationVatRate()` /`getVatRateForCountry()` now
  return the correct percentage on every branch. Previously the
  `standard_rate` and `getRateForCategory` branches returned the base-100 integer
  (`2100`) instead of the percentage (`21.0`), only the category branch divided.
  If you relied on that buggy `2100`-shaped value, divide by 100.
