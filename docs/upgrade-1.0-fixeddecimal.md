# Upgrading larabill to 1.0.0 (FixedDecimal money type)

larabill 1.0.0 adopts lara100 v2's `FixedDecimal` value object for monetary model
attributes (AID-237, ADR-009). **The database is unchanged** — columns stay
`integer` and store the same base-100 cents, so there is no column or data
migration. The breaking change is purely in the PHP API: monetary attributes now
return a `FixedDecimal` object instead of a plain `int`.

## What changed

These attributes now return `FixedDecimal` (or `null`):

| Model | Attributes |
|-------|------------|
| `Article` | `cost_price` (nullable) |
| `ArticlePrice` | `price` |
| `ArticleOverride` | `custom_price` |
| `ArticleServiceStatus` | `effective_price` |
| `InvoiceItem` | `quantity`, `unit_price`, `taxable_amount`, `total_tax_amount`, `total_amount` |
| `Invoice` | `taxable_amount`, `total_tax_amount`, `total_amount` |
| `Commission` | `rate` |
| `TaxCategory` | `default_rate` |

## Reading values

```php
$cents  = $invoice->total_amount->unscaledValue();    // int: 1234  (the pre-1.0 value)
$string = $invoice->total_amount->toDecimalString();  // "12.34"  (exact)
$float  = $invoice->total_amount->toFloat();          // 12.34    (lossy — display only)
```

If you previously read `$invoice->total_amount` as an int of cents, append
`->unscaledValue()`.

## Writing values

Assigning a scalar now throws `InvalidFixedDecimal`. Build a `FixedDecimal`:

```php
use AichaDigital\Lara100\ValueObjects\FixedDecimal;

$invoice->total_amount = FixedDecimal::ofUnscaled(1234, 2);    // from cents
$invoice->total_amount = FixedDecimal::ofDecimalString('12.34');
$invoice->total_amount = FixedDecimal::ofFloat(12.34, 2);       // lossy input
```

## Null handling

The old `Base100Int` cast coerced `null → 0`. `FixedDecimalCast` preserves `null`.
Only `Article::cost_price` is nullable among the migrated columns. Guard with
`?->`: `$article->cost_price?->unscaledValue() ?? 0`.

## Rounding behaviour change

The per-line taxable amount (`quantity × unit_price`) now rounds to the cent with
**HalfUp** (EU/Spain rule) instead of truncating. On lines where
`quantity × unit_price` produces a fractional cent, the stored taxable amount may
differ by one cent from a pre-1.0 computation (e.g. 1.50 units × €12.33 → €18.50,
previously €18.49). Tax amounts are unchanged. Existing persisted rows are not
modified; only newly computed amounts use the corrected rounding.

## Query builder is unaffected

Raw column reads via the query builder still return integers:
`->value('price')`, `->sum('total_amount')`, `->where('total_amount', '>', 0)`,
`->pluck(...)`. Only Eloquent **attribute** access (`$model->price`) returns a
`FixedDecimal`.

## Tests in your application

If your tests assign integer cents to these attributes via factories or `create()`,
wrap them in `FixedDecimal::ofUnscaled($cents, 2)`. larabill's own test suite adds
a `cents(int): FixedDecimal` helper for terseness; you can do the same.
