# Deprecation Notice: BillingService Numbering Methods

- **Date:** 2026-02-15
- **Affects:** BillingService (3 methods)
- **Removal target:** v0.5.0
- **Replacement:** InvoiceNumberingService::generateNumber()

---

## Deprecated Methods

The following methods in `AichaDigital\Larabill\Services\BillingService` are deprecated:

- `generateInvoiceNumber()` — Uses cache-based sequencing, not atomic
- `getSequenceNumber()` — Relies on cache, no database locks
- `getTempSeriesNumber()` — Temporary workaround using max() queries

## Why

These methods do not guarantee atomic, gap-free fiscal numbering under concurrent access. `InvoiceNumberingService` (introduced in v0.3.3) uses `lockForUpdate()` with database transactions for fiscal compliance (AEAT/VeriFactu).

## Migration

Replace any direct usage of these methods with:

```php
use AichaDigital\Larabill\Services\InvoiceNumberingService;

$service = app(InvoiceNumberingService::class);
$invoiceNumber = $service->generateNumber(
    prefix: 'FAC',
    serie: InvoiceSerieType::INVOICE->value,
    userId: auth()->id(),
);

// $invoiceNumber is an InvoiceNumber value object
// Access components: $invoiceNumber->formatted, ->prefix, ->fiscalYear, ->seriesNumber
// String-castable: (string) $invoiceNumber or string concatenation works
```

## Timeline

- **2026-02-15:** Methods marked `@deprecated`
- **v0.5.0:** Methods removed
