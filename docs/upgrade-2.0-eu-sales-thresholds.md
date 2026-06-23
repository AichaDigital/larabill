# Upgrade guide: 1.x → 2.0 (EuSalesThreshold → FixedDecimal, no decimals in DB)

larabill 2.0 finishes the "no decimals in the database" rule started in 1.0
(AID-237). Two `decimal` columns the package owns become `integer` base-100
minor units, and `EuSalesThreshold` swaps its `float` euro API for the same
`FixedDecimal` value object the rest of the package already uses.

If your application does **not** touch `EuSalesThreshold`, `Commission::rate` as
a raw column, or the `larabill.destination_vat.default_threshold` config value,
this release is transparent — no code changes are required.

## Why

- `eu_sales_thresholds.total_amount` / `threshold_amount` were `decimal(15,2)`
  and the model cast them to `float`. That violated the package's base-100
  invariant and, worse, mixed units: the threshold was seeded from a
  euro-denominated config default while sales were fed in cents — so a few
  hundred euros of sales could falsely trip the €10,000 OSS threshold.
- `commissions.rate` was `decimal(8,4)`. Its cast was already `FixedDecimalCast:2`
  (since 1.0), so the column stored unscaled integers in a decimal type, capping
  fixed commissions at `9999.9999` and overflowing above €99.99.

Both are now plain `integer` base-100 columns. No data migration is required
(dev-main makes no production-upgrade promise; the migrations are edited in
place).

## Breaking changes (EuSalesThreshold)

### Reading monetary amounts

```php
// 1.x — float euros
$threshold->total_amount;        // 5000.0
$threshold->threshold_amount;    // 10000.0

// 2.0 — FixedDecimal:2 over base-100 minor units
$threshold->total_amount;                    // FixedDecimal (500000 unscaled)
$threshold->total_amount->unscaledValue();   // 500000  (base-100 cents)
$threshold->total_amount->toDecimalString(); // "5000.00"
$threshold->total_amount->toFloat();         // 5000.0  (lossy — display only)
```

### Writing monetary amounts

The cast rejects scalars. Build a `FixedDecimal` explicitly:

```php
use AichaDigital\Lara100\ValueObjects\FixedDecimal;

EuSalesThreshold::create([
    'user_id'      => $userId,
    'fiscal_year'  => 2026,
    'total_amount' => FixedDecimal::ofUnscaled(500000, 2), // €5,000.00
]);
```

### Mutators now take FixedDecimal

```php
// 1.x
$threshold->addAmount(5000.0);
$threshold->addSalesAmount('DE', 2000.0);
$threshold->addSalesForCountry('FR', 3000.0);

// 2.0
$threshold->addAmount(FixedDecimal::ofUnscaled(500000, 2));
$threshold->addSalesAmount('DE', FixedDecimal::ofUnscaled(200000, 2));
$threshold->addSalesForCountry('FR', FixedDecimal::ofUnscaled(300000, 2));
```

### breakdown_by_country is integer cents

The JSON map now stores raw integer cents per country (it summed in euros
before). The raw attribute is `array<string, int>`; the semantic getters return
`FixedDecimal`:

```php
$threshold->breakdown_by_country;            // ['DE' => 200000, 'FR' => 300000]
$threshold->getSalesAmountByCountry('DE');   // FixedDecimal (200000 unscaled)
```

### Aggregate getters expose FixedDecimal

`getRemainingThresholdAmount()`, `calculateTotal()`,
`getThresholdStatistics()['total_sales_amount']`,
`getTopCountriesBySales()[]['amount']`, `getSalesGrowthByCompany()` amounts,
`EuSalesThresholdService::getThresholdStatus()['current_amount'|'threshold']`
and `EuSalesThresholdService::recalculateEuSales()` all return `FixedDecimal`.

### Removed helpers

These `EuSalesThreshold` methods are gone (the `FixedDecimal` API replaces them):
`amountToBase100()`, `base100ToAmount()`, `getTotalAmountAsAmount()`,
`getThresholdAmountAsAmount()`, `setTotalAmountFromAmount()`,
`setThresholdAmountFromAmount()`.

## Config

`larabill.destination_vat.default_threshold` is now base-100 minor units:

```php
// 1.x
'default_threshold' => 10000.0,   // euros

// 2.0
'default_threshold' => 1000000,   // €10,000.00 in base-100 minor units
```

Publish the updated config (or set the value to `1000000`) if you overrode it.

## Database

Fresh installs get the new column types automatically. The package migrations
(`commissions`, `eu_sales_thresholds`) and their published stubs already declare
`integer`. No `ALTER` is shipped: there is no production data to convert.

If you somehow have existing `decimal` data, the stored value for
`eu_sales_thresholds` was euros (e.g. `5000.00`) and must be rescaled to cents
(`500000`) before reinterpreting the column as base-100; `commissions.rate`
already stored unscaled integers and needs no rescale.
