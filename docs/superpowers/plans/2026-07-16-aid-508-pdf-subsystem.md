# AID-508 — PDF Subsystem Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The invoice PDF prints the real invoice — number, lines, amounts, issuer and tax QR — or it fails loudly. No fabricated data, ever.

**Architecture:** Wire the `DomPDFService` stubs to the real models **and** remove the mechanism that hid them: inner layers propagate exceptions, `PDFService` is the single frontier that logs and translates to `['success' => false]`. The tax QR becomes an effect of the fiscal record (never fabricated by the PDF). Money leaves the templates: the service hands over exact strings, blades only print. No structural redesign of connector/service/template.

**Spec:** `docs/superpowers/specs/2026-07-16-aid-508-pdf-subsystem-design.md` (approved, commit `92be0b4`). Read it before Task 1 — this plan implements it and does not restate its reasoning.

**Tech Stack:** PHP 8.3, Laravel 12/13, Pest, dompdf ^3.1, lara100 `FixedDecimal`, `smalot/pdfparser` (new require-dev).

## Global Constraints

- **Branch:** `fix/aid-508-pdf-subsystem` (already created from `origin/main`; the spec is committed there). Do not branch again.
- **PHP binary:** `php83` for every command (larabill is pinned: SQLite in-memory bug on 8.4). `php83 vendor/bin/pest`, and `php83 "$(which composer)"` for any composer command.
- **Zero migrations.** No schema change, no new column, no `larabill:install` change. If a task seems to need one, stop and report.
- **Money:** base-100 integers via `FixedDecimal` (lara100). Never float, never `number_format` on money, never `/ 100` in a blade.
- **Surface tags:** every new class carries exactly one class-level `@api` or `@internal` tag or `tests/Unit/Contract/SurfaceTaxonomyTest.php` fails. New exceptions are `@api` (see `MissingInvoiceOwnerException`).
- **Golden master:** no method signature changes. `tests/Contract/snapshots/Invoice.json` must stay untouched — if it drifts, you changed a signature you shouldn't have.
- **Quality gate before every commit:** `php83 vendor/bin/pint --dirty` then `php83 vendor/bin/pest`.
- **Test sensitivity (house rule, AID-390/AID-264):** every new test must be shown to FAIL against the pre-fix code. A green new test is new theatre.
- **Language:** code, comments and commit messages in English. Normative citations go in comments as source references (see spec §4.6), never as invented claims.

---

## File Structure

**Create:**
- `src/Exceptions/MissingFiscalVerificationQrException.php` — thrown when strict mode demands a fiscal QR and the record is absent or unusable.
- `src/Support/FiscalQrImage.php` — structural validation of the persisted QR value (spec §4.4.1). Pure, no dependencies on the container.
- `tests/Unit/Support/FiscalQrImageTest.php`
- `tests/Unit/Services/PDF/InvoiceContentComplianceTest.php` — the content dataset over the six templates.
- `tests/Unit/Services/PDF/FiscalQrPolicyTest.php` — the QR matrix (spec §6).
- `tests/Feature/PDF/InvoicePdfEndToEndTest.php` — the single pdfparser end-to-end.

**Modify:**
- `config/larabill.php:102-112` — add `require_fiscal_verification_qr` to the `pdf` block.
- `src/Models/Invoice.php:456-465` (`shouldIncludeQR`), `:541-565` (`getPDFPath`/`getPDFUrl`).
- `src/Enums/InvoiceSerieType.php` — read only (`isFiscal()` at `:66` already exists).
- `src/Services/PDF/PDFService.php` — frontier: strict policy, `fallback_to_local` removal, `Log::error` restore, dead guard removal.
- `src/Services/PDF/DomPDFService.php` — the bulk: eight catches, eleven guards, the three stubs, file naming.
- `src/Services/PDF/DefaultPDFConnector.php:219-270` — `prepareQRData` and `generateQRCode`.
- `resources/views/pdf/invoice/*.blade.php` — all six.
- `CHANGELOG.md`

**Delete (whole methods):** `DomPDFService::getConfigValue()`, `::isProductionEnvironment()`, `::generateMockHTML()`, `::generatePDFUrl()`, `::shouldIncludeQR()` (duplicate), `DefaultPDFConnector::generateQRCode()`.

---

### Task 1: Config key and typed exception

**Files:**
- Modify: `config/larabill.php:102-112`
- Create: `src/Exceptions/MissingFiscalVerificationQrException.php`
- Test: `tests/Unit/Services/PDF/FiscalQrPolicyTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: config key `larabill.pdf.require_fiscal_verification_qr` (bool, default `false`), always read with an explicit default — a consumer who already installed will not have the key. And `MissingFiscalVerificationQrException::forInvoice(Invoice $invoice): self`.

- [ ] **Step 1: Add the config key**

In `config/larabill.php`, inside the existing `'pdf' => [` block (`:102`), append:

```php
        /*
        | Whether this installation's invoices must carry the fiscal verification
        | QR. It declares the DOCUMENT CONTRACT, not a legal obligation: larabill
        | does not know the taxpayer type, exclusions, elected mode or effective
        | adoption date. The consumer turns it on when it actually operates that
        | flow — voluntarily during the trial period, or once obliged.
        |
        | VeriFACTU calendar (BOE, RD 1007/2023 consolidated:
        | https://www.boe.es/buscar/act.php?id=BOE-A-2023-24840 — and AEAT's
        | deadline-extension notice:
        | https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/nota-informativa-ampliacion-plazo-adaptacion-facturacion.html):
        | 2027-01-01 for corporate income tax payers, 2027-07-01 for the rest of
        | art. 3.1 obliged parties; before that the period is a trial one.
        |
        | true  => a fiscal invoice without a coherent record and a usable QR is
        |          a hard failure: no PDF is produced.
        | false => such an invoice is rendered without the tax block.
        */
        'require_fiscal_verification_qr' => env('LARABILL_REQUIRE_FISCAL_QR', false),
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Services/PDF/FiscalQrPolicyTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\MissingFiscalVerificationQrException;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\TestCase;

it('defaults require_fiscal_verification_qr to false', function () {
    expect(config('larabill.pdf.require_fiscal_verification_qr'))->toBeFalse();
});

it('builds a message naming the invoice', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'CASTRIS-2026-000042',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);

    $exception = MissingFiscalVerificationQrException::forInvoice($invoice);

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toContain('CASTRIS-2026-000042');
});
```

- [ ] **Step 3: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/FiscalQrPolicyTest.php`
Expected: FAIL — `Class "AichaDigital\Larabill\Exceptions\MissingFiscalVerificationQrException" not found`.

- [ ] **Step 4: Create the exception**

Create `src/Exceptions/MissingFiscalVerificationQrException.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\Invoice;
use RuntimeException;

/**
 * A fiscal invoice reached PDF generation without a usable fiscal verification
 * QR, while this installation declares it mandatory
 * (`larabill.pdf.require_fiscal_verification_qr`).
 *
 * Absence and loss are not the same thing: an installation outside VeriFACTU
 * legitimately has no QR and renders without the tax block. With the contract
 * switched on, a missing record — or a value that is not a usable image — is a
 * LOST datum, and the document must not be produced (AID-508). The alternative
 * is what this ticket exists to remove: emitting a plausible invoice that lies.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class MissingFiscalVerificationQrException extends RuntimeException
{
    public static function forInvoice(Invoice $invoice): self
    {
        return new self(
            "Invoice {$invoice->fiscal_number} is a fiscal document without a usable fiscal "
            .'verification QR, and larabill.pdf.require_fiscal_verification_qr is enabled — '
            .'refusing to produce a tax document without its QR.'
        );
    }
}
```

- [ ] **Step 5: Run the test and watch it pass**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/FiscalQrPolicyTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
php83 vendor/bin/pint --dirty
git add config/larabill.php src/Exceptions/MissingFiscalVerificationQrException.php tests/Unit/Services/PDF/FiscalQrPolicyTest.php
git commit -m "feat(pdf): declare the fiscal QR document contract (AID-508)

The consumer declares whether its invoices must carry the fiscal QR. The key
states the document contract, not a legal obligation: larabill knows nothing
about taxpayer type, exclusions or elected mode."
```

---

### Task 2: Structural validation of the persisted QR

**Files:**
- Create: `src/Support/FiscalQrImage.php`
- Test: `tests/Unit/Support/FiscalQrImageTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `FiscalQrImage::classify(?string $value): ?string` returning `'svg'`, `'png'` or `null`. Callers treat `null` as absence.

**Why structural and not a prefix check (spec §4.4.1):** `data:image/png;base64,garbage` passes a prefix check, dompdf renders no image, and the service reports success — this ticket's defect, reintroduced inside its own fix. And larabill cannot lean on lara-verifactu to guarantee the column: lara-verifactu is optional, and the column may be written by another integration or hold historical data.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/FiscalQrImageTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\FiscalQrImage;

it('classifies a well-formed svg', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';

    expect(FiscalQrImage::classify($svg))->toBe('svg');
});

it('classifies an svg preceded by an xml declaration', function () {
    $svg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';

    expect(FiscalQrImage::classify($svg))->toBe('svg');
});

it('classifies a valid base64 png data uri', function () {
    // 1x1 transparent PNG
    $png = 'data:image/png;base64,'.base64_encode(
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true)
    );

    expect(FiscalQrImage::classify($png))->toBe('png');
});

it('rejects a base64 png whose payload is not a png', function () {
    $fake = 'data:image/png;base64,'.base64_encode('this is not a png');

    expect(FiscalQrImage::classify($fake))->toBeNull();
});

it('rejects invalid base64', function () {
    expect(FiscalQrImage::classify('data:image/png;base64,not!valid!base64!'))->toBeNull();
});

it('rejects a truncated or malformed svg', function () {
    expect(FiscalQrImage::classify('<svg xmlns="http://www.w3.org/2000/svg"><rect'))->toBeNull();
});

it('rejects well-formed xml whose root is not svg', function () {
    expect(FiscalQrImage::classify('<html><body/></html>'))->toBeNull();
});

it('rejects the bare AEAT cotejo url', function () {
    expect(FiscalQrImage::classify('https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=B12345678'))->toBeNull();
});

it('rejects null and empty', function () {
    expect(FiscalQrImage::classify(null))->toBeNull()
        ->and(FiscalQrImage::classify(''))->toBeNull()
        ->and(FiscalQrImage::classify('   '))->toBeNull();
});

it('rejects the removed fake connector format', function () {
    expect(FiscalQrImage::classify('QR:a1b2c3d4e5f6a7b8:eyJpbnZvaWNlX2lkIjoi'))->toBeNull();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Support/FiscalQrImageTest.php`
Expected: FAIL — `Class "AichaDigital\Larabill\Support\FiscalQrImage" not found`.

- [ ] **Step 3: Implement**

Create `src/Support/FiscalQrImage.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use DOMDocument;

/**
 * Structural validation of the fiscal QR persisted on `invoices.fiscal_verification_qr`.
 *
 * larabill does NOT generate QR codes: it consumes what the fiscal registration
 * persisted. But it does check that the value is an image it can hand to dompdf,
 * because a recognised prefix is not a usable image — `data:image/png;base64,garbage`
 * would render nothing while the service reported success (AID-508).
 *
 * Scope: structure only. Fiscal content, scannability and the encoded cotejo URL
 * belong to the producer (lara-verifactu, per the AEAT QR spec v0.4.7). This class
 * cannot defer to that producer: it is optional, and the column may also be written
 * by another integration or hold historical data.
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
final class FiscalQrImage
{
    private const PNG_DATA_URI_PREFIX = 'data:image/png;base64,';

    /** PNG signature: \x89PNG\r\n\x1a\n */
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Classify the persisted value as a usable image.
     *
     * @return string|null 'svg', 'png', or null when the value is unusable
     *                     (absent, a bare URL, an unknown format, invalid
     *                     base64, or malformed XML). Callers treat null as
     *                     absence.
     */
    public static function classify(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, self::PNG_DATA_URI_PREFIX)) {
            return self::isValidPng(substr($value, strlen(self::PNG_DATA_URI_PREFIX))) ? 'png' : null;
        }

        return self::isValidSvg($value) ? 'svg' : null;
    }

    private static function isValidPng(string $base64): bool
    {
        $binary = base64_decode($base64, true);

        return $binary !== false && str_starts_with($binary, self::PNG_SIGNATURE);
    }

    private static function isValidSvg(string $value): bool
    {
        // Strip a leading XML declaration: lara-verifactu emits the SVG with one.
        $candidate = preg_replace('/^<\?xml[^>]*\?>\s*/i', '', $value) ?? $value;

        if (! str_starts_with(ltrim($candidate), '<svg')) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;

        // LIBXML_NONET: never resolve external entities over the network. The value
        // is untrusted input that ends up inlined raw in the template, and dompdf
        // runs with isRemoteEnabled.
        $wellFormed = $document->loadXML($value, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $wellFormed && $document->documentElement?->localName === 'svg';
    }
}
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php83 vendor/bin/pest tests/Unit/Support/FiscalQrImageTest.php`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
php83 vendor/bin/pint --dirty
git add src/Support/FiscalQrImage.php tests/Unit/Support/FiscalQrImageTest.php
git commit -m "feat(pdf): validate the persisted fiscal QR structurally (AID-508)

A recognised prefix is not a usable image: data:image/png;base64,garbage would
render nothing while the service reported success. Structure only — fiscal
content and scannability belong to the producer."
```

---

### Task 3: `shouldIncludeQR()` expresses the two governing axes

**Files:**
- Modify: `src/Models/Invoice.php:456-465`
- Delete: `src/Services/PDF/DomPDFService.php:232-242` (the duplicate)
- Test: `tests/Unit/Services/PDF/FiscalQrPolicyTest.php` (append)

**Interfaces:**
- Consumes: `InvoiceSerieType::isFiscal()` (`src/Enums/InvoiceSerieType.php:66`, already exists — covers `INVOICE`, `SIMPLIFIED`, `RECTIFICATIVE`).
- Produces: `Invoice::shouldIncludeQR(): bool` — signature unchanged, semantics changed. `DomPDFService` calls `$invoice->shouldIncludeQR()` from now on.

**Two axes govern the QR — document class and fiscal registration. Invoice status governs nothing** (spec §4.2): delivery and payment do not make a document fiscal.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/PDF/FiscalQrPolicyTest.php`:

```php
function makeQrInvoice(InvoiceSerieType $serie, bool $registered, InvoiceStatus $status = InvoiceStatus::SENT): Invoice
{
    return Invoice::factory()->create([
        'fiscal_number'          => 'QR-AXES',
        'serie'                  => $serie->value,
        'status'                 => $status->value,
        'user_id'                => TestCase::USER_UUID_1,
        'fiscal_verification_id' => $registered ? 'REG-1' : null,
        'fiscal_verified_at'     => $registered ? now() : null,
    ]);
}

it('includes the QR for every fiscal serie once registered', function (InvoiceSerieType $serie) {
    expect(makeQrInvoice($serie, registered: true)->shouldIncludeQR())->toBeTrue();
})->with([
    'invoice'       => InvoiceSerieType::INVOICE,
    'simplified'    => InvoiceSerieType::SIMPLIFIED,   // AID-508: was excluded; a ticket is a fiscal document
    'rectificative' => InvoiceSerieType::RECTIFICATIVE,
]);

it('never includes the QR for a proforma, registered or not', function (bool $registered) {
    expect(makeQrInvoice(InvoiceSerieType::PROFORMA, $registered)->shouldIncludeQR())->toBeFalse();
})->with([true, false]);

it('does not include the QR for a fiscal invoice without a record', function () {
    expect(makeQrInvoice(InvoiceSerieType::INVOICE, registered: false)->shouldIncludeQR())->toBeFalse();
});

it('ignores delivery and payment status entirely', function (InvoiceStatus $status) {
    expect(makeQrInvoice(InvoiceSerieType::INVOICE, registered: true, status: $status)->shouldIncludeQR())->toBeTrue();
})->with([
    'sent'    => InvoiceStatus::SENT,
    'paid'    => InvoiceStatus::PAID,
    'overdue' => InvoiceStatus::OVERDUE,
    'draft'   => InvoiceStatus::DRAFT,
]);
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/FiscalQrPolicyTest.php`
Expected: FAIL on the `simplified` case (today's rule is `INVOICE || RECTIFICATIVE`) and on the four unregistered/status cases (today the rule ignores registration).

- [ ] **Step 3: Rewrite the model method**

In `src/Models/Invoice.php`, replace `shouldIncludeQR()` (`:456-465`) with:

```php
    /**
     * Whether this invoice has a tax QR to print.
     *
     * Two axes govern it, and invoice status is not one of them (AID-508):
     *
     * - Document class: only fiscal documents carry a tax QR. A proforma never
     *   does, in any state.
     * - Fiscal registration: the QR is an effect of the billing record — per the
     *   AEAT FAQ, definitive issuance happens when the billing record is generated
     *   and the QR incorporated, not when the PDF is delivered nor when AEAT
     *   accepts the record.
     *
     * Delivery and payment do not make a document fiscal: an invoice does not
     * acquire a QR by being paid.
     */
    public function shouldIncludeQR(): bool
    {
        return $this->serie->isFiscal() && $this->isFiscallyVerified();
    }
```

- [ ] **Step 4: Delete the duplicate in the service**

In `src/Services/PDF/DomPDFService.php`, delete the whole `shouldIncludeQR()` method (`:232-242`) — it carried the same copied logic and the same `SIMPLIFIED` hole. Then in `generatePDF()` (`:71`) change:

```php
            $includeQR = $this->shouldIncludeQR($invoice);
```

to:

```php
            $includeQR = $invoice->shouldIncludeQR();
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/FiscalQrPolicyTest.php`
Expected: PASS.

Then the full suite: `php83 vendor/bin/pest`
Expected: PASS. If `tests/Contract/snapshots/Invoice.json` drifts, you changed a signature — revert and keep `shouldIncludeQR(): bool`.

- [ ] **Step 6: Commit**

```bash
php83 vendor/bin/pint --dirty
git add src/Models/Invoice.php src/Services/PDF/DomPDFService.php tests/Unit/Services/PDF/FiscalQrPolicyTest.php
git commit -m "fix(pdf): the tax QR is governed by document class and registration (AID-508)

shouldIncludeQR() decided by serie alone, ignoring whether a billing record
existed at all, and excluded SIMPLIFIED — a simplified invoice is a fiscal
document and carries its QR. Invoice status governs nothing: delivery and
payment do not make a document fiscal. The service's copied duplicate is gone;
the model owns the rule."
```

---

### Task 4: The frontier applies the strict policy

**Files:**
- Modify: `src/Services/PDF/PDFService.php:94-111` (QR resolution), `:174-207` (`fiscalVerificationQrResult`)
- Test: `tests/Unit/Services/PDF/FiscalQrPolicyTest.php` (append)

**Interfaces:**
- Consumes: `FiscalQrImage::classify()` (Task 2), `MissingFiscalVerificationQrException::forInvoice()` (Task 1), `Invoice::shouldIncludeQR()` (Task 3).
- Produces: `PDFService::generatePDF()` throws `MissingFiscalVerificationQrException` under strict mode with an unusable record; the connector branch is gone.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/PDF/FiscalQrPolicyTest.php`:

```php
it('fails explicitly when strict mode meets a fiscal invoice with no record', function () {
    config()->set('larabill.pdf.require_fiscal_verification_qr', true);
    $invoice = makeQrInvoice(InvoiceSerieType::INVOICE, registered: false);

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('fiscal verification QR');
});

it('fails explicitly when strict mode meets a registered invoice whose QR is unusable', function (string $qr) {
    config()->set('larabill.pdf.require_fiscal_verification_qr', true);
    $invoice = makeQrInvoice(InvoiceSerieType::INVOICE, registered: true);
    $invoice->update(['fiscal_verification_qr' => $qr]);

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeFalse();
})->with([
    'bare url'       => 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=B1',
    'invalid base64' => 'data:image/png;base64,not!valid!',
    'malformed svg'  => '<svg xmlns="http://www.w3.org/2000/svg"><rect',
]);

it('fails explicitly when the QR is present but the record is incoherent', function () {
    // The blind spot of checking only the QR: a valid SVG with no registration ids.
    config()->set('larabill.pdf.require_fiscal_verification_qr', true);
    $invoice = makeQrInvoice(InvoiceSerieType::INVOICE, registered: false);
    $invoice->update(['fiscal_verification_qr' => '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>']);

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeFalse();
});

it('renders without the tax block when the contract is not required', function () {
    config()->set('larabill.pdf.require_fiscal_verification_qr', false);
    $invoice = makeQrInvoice(InvoiceSerieType::INVOICE, registered: false);

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeTrue();
});

it('exempts a proforma from strict mode', function () {
    config()->set('larabill.pdf.require_fiscal_verification_qr', true);
    $invoice = makeQrInvoice(InvoiceSerieType::PROFORMA, registered: false);

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeTrue();
});

it('never asks a connector to produce the QR', function () {
    // The spy that proves 4b does what it says: whatever the invoice's state, the
    // pipeline reads the fiscal record and never calls a connector to fabricate one.
    $connector = Mockery::spy(PDFConnectorInterface::class);
    $service   = new PDFService;
    $service->registerConnector('local', $connector);

    $service->generatePDF(makeQrInvoice(InvoiceSerieType::INVOICE, registered: true));
    $service->generatePDF(makeQrInvoice(InvoiceSerieType::INVOICE, registered: false));
    $service->generatePDF(makeQrInvoice(InvoiceSerieType::PROFORMA, registered: false));

    $connector->shouldNotHaveReceived('generateQR');
});
```

Add the import: `use AichaDigital\Larabill\Contracts\PDFConnectorInterface;`

Add the import at the top: `use AichaDigital\Larabill\Services\PDF\PDFService;`

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/FiscalQrPolicyTest.php`
Expected: FAIL — strict mode does not exist yet; everything reports `success: true`.

- [ ] **Step 3: Rewrite the QR resolution in the frontier**

In `src/Services/PDF/PDFService.php`, replace the block at `:94-111` with:

```php
            // The QR is an effect of the fiscal record; larabill never fabricates it
            // (AID-508). Two facts must hold, not one: a coherent registration AND a
            // usable image. A valid SVG with no registration ids is a lost datum.
            $isFiscal     = $invoice->serie->isFiscal();
            $isRegistered = $invoice->isFiscallyVerified();
            $qrResult     = $this->fiscalVerificationQrResult($invoice);
            $strict       = (bool) config('larabill.pdf.require_fiscal_verification_qr', false);

            if ($isFiscal && $strict && (! $isRegistered || $qrResult === null)) {
                throw MissingFiscalVerificationQrException::forInvoice($invoice);
            }

            $qrData = $isFiscal && $isRegistered && $qrResult !== null
                ? $qrResult
                : ['success' => true, 'qr_code' => null, 'qr_url' => null, 'qr_data' => []];
```

Then at `:114` pass `$qrData` instead of `$qrResult`:

```php
            $pdfResult = $this->dompdfService->generatePDF($invoice, $qrData);
```

and at `:140` return `'qr_data' => $qrData,`.

Add the import: `use AichaDigital\Larabill\Exceptions\MissingFiscalVerificationQrException;`

- [ ] **Step 4: Make `fiscalVerificationQrResult()` validate structurally**

In the same file, replace the body of `fiscalVerificationQrResult()` (`:174-207`) with:

```php
    protected function fiscalVerificationQrResult(Invoice $invoice): ?array
    {
        $qr   = $invoice->fiscal_verification_qr;
        $kind = FiscalQrImage::classify(is_string($qr) ? $qr : null);

        if ($kind === null) {
            // Absent, a bare cotejo URL, an unknown format, invalid base64 or
            // malformed XML: larabill does not render QR codes, so it cannot use it.
            return null;
        }

        $metadata = $invoice->fiscal_verification_metadata ?? [];
        $qrUrl    = $metadata['qr_url']                    ?? null;

        $result = [
            'success'        => true,
            'source'         => 'fiscal_verification',
            'qr_code'        => $qr,
            'qr_url'         => is_string($qrUrl) ? $qrUrl : null,
            'qr_data'        => [],
            'connector_type' => 'fiscal_verification',
            'generated_at'   => now()->toISOString(),
            'metadata'       => $metadata,
        ];

        $result[$kind === 'svg' ? 'qr_svg' : 'qr_png'] = $qr;

        return $result;
    }
```

Add the import: `use AichaDigital\Larabill\Support\FiscalQrImage;`

- [ ] **Step 5: Run the tests and watch them pass**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/FiscalQrPolicyTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
php83 vendor/bin/pint --dirty
git add src/Services/PDF/PDFService.php tests/Unit/Services/PDF/FiscalQrPolicyTest.php
git commit -m "feat(pdf): the frontier enforces the fiscal QR contract (AID-508)

Strict mode checks both facts, not just the QR: a valid SVG with absent
registration ids is a lost datum and must not pass. Without the contract the
invoice renders with no tax block. The connector branch is unreachable from
here on: larabill consumes the record's QR, it never fabricates one."
```

---

### Task 5: The frontier stops falsifying results

**Files:**
- Modify: `src/Services/PDF/PDFService.php:60-72` (config), `:113-166` (frontier), `src/Services/PDF/DomPDFService.php:66-108`
- Test: `tests/Unit/Services/PDF/PDFServiceTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `DomPDFService::generatePDF()` now throws instead of returning `['success' => false]`. `PDFService` is the only translator.

**Three pieces, three destinations** (spec §3.3): `fallback_to_local` is deleted, `Log::error` is restored, `return ['success' => false]` stays. Plus the outermost `catch` of `DomPDFService` (`:100`) goes, and with it the now-unreachable guard at `PDFService:118-120`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/PDF/PDFServiceTest.php`:

```php
it('logs the original exception before translating it into the failure contract', function () {
    Log::spy();
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'FRONTIER-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
        'template_name' => null,
    ]);
    // Force a render failure: point the invoice at a view that does not exist.
    View::shouldReceive('make')->andThrow(new RuntimeException('boom from the depths'));

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('boom from the depths');

    Log::shouldHaveReceived('error')->once();
});

it('does not retry with the local connector when generation fails', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'FRONTIER-2',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);
    View::shouldReceive('make')->andThrow(new RuntimeException('render exploded'));

    $result = (new PDFService)->generatePDF($invoice);

    // The old fallback_to_local retried and could report success on a fabricated path.
    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('render exploded');
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/PDFServiceTest.php`
Expected: FAIL — the log is commented out, and the error message is the rebuilt generic `"PDF rendering failed: ..."` string rather than the original.

- [ ] **Step 3: Remove the outermost catch of `DomPDFService`**

In `src/Services/PDF/DomPDFService.php`, delete lines `:100-107` — the whole `} catch (\Exception $e) { return ['success' => false, ...]; }` — so `generatePDF()` propagates. Keep the `try {` removal consistent: the method body no longer needs the `try`.

The method becomes:

```php
    public function generatePDF(Invoice $invoice, ?array $qrData = null): array
    {
        // No catch here (AID-508): this method used to translate any exception into
        // ['success' => false], keeping only getMessage() and dropping the class,
        // the stack trace and the previous — while PDFService rebuilt a generic
        // RuntimeException from that string. Exception → array → exception → array,
        // losing the original. The frontier (PDFService) is the only translator.
        $template  = $this->getTemplateForInvoice($invoice);
        $includeQR = $invoice->shouldIncludeQR();

        $templateData = $this->prepareTemplateData($invoice, $qrData, $includeQR);
        $html         = $this->renderTemplate($template, $templateData);

        $this->dompdf->loadHtml($html);
        $this->dompdf->render();

        $pdfContent = $this->dompdf->output();
        $pdfPath    = $this->savePDF($invoice, $pdfContent);

        return [
            'success'       => true,
            'pdf_path'      => $pdfPath,
            'pdf_url'       => null,
            'pdf_size'      => strlen($pdfContent),
            'template_used' => $template,
            'qr_included'   => $includeQR,
            'generated_at'  => now()->toISOString(),
        ];
    }
```

(`'pdf_url' => null` implements spec §5.6 — Task 10 removes `generatePDFUrl()` itself.)

- [ ] **Step 4: Fix the frontier**

In `src/Services/PDF/PDFService.php`:

Delete the now-unreachable guard at `:118-120` (`if (! ($pdfResult['success'] ?? false)) throw ...`) — `generatePDF()` cannot return `success: false` any more.

Replace the `catch` block (`:145-166`) with:

```php
        } catch (\Exception $e) {
            // The frontier: log the exception BEFORE translating it, so the
            // translation never loses the cause (AID-508). No fallback: retrying
            // with another connector after a failure only fabricated a plausible
            // result and buried the original error.
            Log::error('larabill: invoice PDF generation failed', [
                'invoice_id'      => $invoice->id,
                'invoice_number'  => $invoice->fiscal_number,
                'connector_type'  => $connectorType,
                'exception_class' => $e::class,
                'exception'       => $e->getMessage(),
            ]);

            return [
                'success'        => false,
                'error'          => $e->getMessage(),
                'connector_used' => $connectorType,
                'generated_at'   => now()->toISOString(),
            ];
        }
```

Delete the `'fallback_to_local' => true,` entry from the config array in the constructor (`:60-72`), and the commented-out `Log::info` block at `:127-134`.

Add the import: `use Illuminate\Support\Facades\Log;`

- [ ] **Step 5: Verify no orphan references to the removed key**

Run: `grep -rn "fallback_to_local" src/ config/ tests/`
Expected: no output.

- [ ] **Step 6: Run the tests and watch them pass**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
php83 vendor/bin/pint --dirty
git add src/Services/PDF/PDFService.php src/Services/PDF/DomPDFService.php tests/Unit/Services/PDF/PDFServiceTest.php
git commit -m "fix(pdf): one frontier, no fallback, and the exception survives (AID-508)

DomPDFService::generatePDF() destroyed the exception (kept getMessage(), dropped
class, trace and previous) and PDFService rebuilt a generic one from the string.
Now inner layers propagate and the frontier logs before translating.
fallback_to_local retried with the fake-QR connector and reported success —
it falsified the result and buried the cause. Gone, with its config key."
```

---

### Task 6: Remove the blindfold

**Files:**
- Modify: `src/Services/PDF/DomPDFService.php` (`:149-179`, `:189-227`, `:462-551`, `:559-600`, `:642-678`)
- Test: `tests/Unit/Services/PDF/DomPdfFiscalFlagsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `DomPDFService` fails loudly. `getConfigValue()`, `isProductionEnvironment()` and `generateMockHTML()` no longer exist.

**A Laravel package does not wrap guaranteed framework APIs or declared dependencies** (spec §3.2). If dompdf, Eloquent or the container are missing, the install is broken and must say so.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/PDF/DomPdfFiscalFlagsTest.php`:

```php
it('propagates a database failure instead of falling back to a default template', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'BLINDFOLD-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
        'template_name' => 'some-template',
    ]);
    Schema::drop('invoice_templates');

    $engine = new DomPDFService([]);
    $method = new ReflectionMethod($engine, 'getTemplateForInvoice');

    expect(fn () => $method->invoke($engine, $invoice))->toThrow(QueryException::class);
});

it('no longer carries the mock html generator', function () {
    expect(method_exists(DomPDFService::class, 'generateMockHTML'))->toBeFalse()
        ->and(method_exists(DomPDFService::class, 'getConfigValue'))->toBeFalse()
        ->and(method_exists(DomPDFService::class, 'isProductionEnvironment'))->toBeFalse();
});
```

Add imports: `use Illuminate\Database\QueryException;` and `use Illuminate\Support\Facades\Schema;`

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/DomPdfFiscalFlagsTest.php`
Expected: FAIL — today the exception is swallowed and the mock methods exist.

- [ ] **Step 3: Strip `initializeDomPDF()`**

Replace `initializeDomPDF()` (`:149-179`) with:

```php
    protected function initializeDomPDF(): void
    {
        $options = new Options;
        $options->set('defaultFont', $this->config['default_font']);
        $options->set('isRemoteEnabled', $this->config['is_remote_enabled']);
        $options->set('isHtml5ParserEnabled', $this->config['is_html5_parser_enabled']);
        $options->set('fontCache', $this->config['font_cache']);

        $this->dompdf = new Dompdf($options);
        $this->dompdf->setPaper($this->config['paper_size'], $this->config['paper_orientation']);
    }
```

The `class_exists('Dompdf\Dompdf')` check and the anonymous mock returning `'mock-pdf-content'` are gone: dompdf is a declared dependency (`^3.1`).

- [ ] **Step 4: Strip the database guards**

In `getTemplateForInvoice()` (`:189-227`), `getInvoiceNotes()` (`:462-485`), `getPaymentTerms()` (`:493-516`) and `getTemplateSettings()` (`:524-551`), remove every `class_exists('\AichaDigital\Larabill\Models\...') && app()->bound('db')` wrapper and every `try { ... } catch (\Exception $e) { // Fall back ... }`.

The absence path stays exactly as it is: `getByName()` returning `null` and `getDefaultNotes()` returning `null` are legitimate answers and are already handled by the `if ($template)` / `?? null` below. Only the exception path changes.

Example — `getInvoiceNotes()` becomes:

```php
    protected function getInvoiceNotes(Invoice $invoice): ?string
    {
        // Priority: individual -> client -> global. A null from getDefaultNotes()
        // is a legitimate absence (no notes configured) and stays null; a database
        // exception is NOT an absence and now propagates to the frontier (AID-508).
        if ($invoice->notes) {
            return $invoice->notes;
        }

        return CompanyTemplateSettings::getDefaultNotes(
            $this->getCompanyId($invoice),
            $this->convertToTemplateInvoiceType($invoice->getInvoiceType()),
            (string) $invoice->user_id
        );
    }
```

- [ ] **Step 5: Delete the three theatre methods**

Delete `getConfigValue()` (`:589-600`), `isProductionEnvironment()` (`:666-678`) and `generateMockHTML()` (`:642-659`) entirely.

Replace the single call to `getConfigValue()` inside `getCompanyId()` with `config(...)` directly (Task 9 rewrites this method anyway). Replace `renderTemplate()`'s mock branch (`:612-614`) so the method starts straight at the `try`:

```php
    protected function renderTemplate(string $template, array $data): string
    {
        try {
            return View::make($template, $data)->render();
        } catch (\Throwable $e) {
            // A real render failure must NOT be masked as a plausible-but-fake invoice.
            // Log with context and surface the error so the frontier reports failure.
            $invoice = $data['invoice'] ?? null;

            Log::error('larabill: invoice PDF template render failed; surfacing instead of falling back to a mock invoice', [
                'invoice_id'      => $invoice instanceof Invoice ? $invoice->id : null,
                'invoice_number'  => $invoice instanceof Invoice ? $invoice->fiscal_number : null,
                'template'        => $template,
                'exception_class' => $e::class,
                'exception'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }
```

- [ ] **Step 6: Verify no orphan guards remain**

Run: `grep -rnE "class_exists\('Dompdf|class_exists\('\\\\\\\\AichaDigital|app\(\)->bound\('db'\)|function_exists\('storage_path'\)|function_exists\('url'\)|function_exists\('config'\)|generateMockHTML|getConfigValue|isProductionEnvironment" src/Services/PDF/`
Expected: no output.

- [ ] **Step 7: Run the suite**

Run: `php83 vendor/bin/pest`
Expected: PASS. Tests that relied on the mock path (constructing `DomPDFService` without a view layer) will fail — those tests were asserting the fake. Fix them to use the real render, do not restore the mock.

- [ ] **Step 8: Commit**

```bash
php83 vendor/bin/pint --dirty
git add src/Services/PDF/DomPDFService.php tests/Unit/Services/PDF/DomPdfFiscalFlagsTest.php
git commit -m "fix(pdf): remove the blindfold — the service can fail now (AID-508)

Eleven guards asked whether Laravel, dompdf and the package's own models
existed, and turned every exception into filler. A service built never to fail
cannot report that it is empty; that is why a fake invoice survived months.
Absence still returns null — only failures propagate."
```

---

### Task 7: Real invoice lines

**Files:**
- Modify: `src/Services/PDF/DomPDFService.php:381-394`
- Test: `tests/Unit/Services/PDF/InvoiceContentComplianceTest.php` (create)

**Interfaces:**
- Consumes: `Invoice::items()` relation; `InvoiceItem` casts (`quantity`, `unit_price`, `taxable_amount`, `total_tax_amount`, `total_amount` are `FixedDecimalCast:2`; `taxes_applied` is `array`).
- Produces: `getInvoiceItems()` returns, per line: `description` (string), `quantity`, `unit_price`, `taxable_amount`, `tax_amount`, `total` — **all exact strings** via `toDecimalString()` — plus `taxes` (the line's `taxes_applied` entries with `rate`/`name`/`amount` already formatted). Task 9 consumes this shape from the blades.

**`taxes_applied` shape** (from `VatCalculationStrategy`): `['source_rate_id' => int, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100]`, where `rate` is base-100 of the percentage (`2100` = 21 %).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/PDF/InvoiceContentComplianceTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use AichaDigital\Larabill\Tests\TestCase;

/**
 * AID-508 — the PDF must print the real invoice. getInvoiceItems() was a
 * hardcoded stub returning «Servicio 1» with amounts read from non-existent
 * columns, and no test ever looked at the document's content: they asserted
 * success === true and file_exists().
 */
function makeContentInvoice(): Invoice
{
    $invoice = Invoice::factory()->create([
        'fiscal_number'    => 'CASTRIS-2026-000001',
        'serie'            => InvoiceSerieType::INVOICE->value,
        'status'           => InvoiceStatus::DRAFT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'invoice_date'     => '2026-07-16',
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(2100),
        'total_amount'     => cents(12100),
    ]);

    InvoiceItem::factory()->create([
        'invoice_id'       => $invoice->id,
        'description'      => 'Hosting anual Probe',
        'quantity'         => cents(150),      // 1.50 units
        'unit_price'       => cents(10000),    // €100.00
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(2100),
        'total_amount'     => cents(12100),
        'taxes_applied'    => [
            ['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100],
        ],
    ]);

    return $invoice->fresh();
}

function invoiceItemsFor(Invoice $invoice): array
{
    $engine = new DomPDFService([]);
    $method = new ReflectionMethod($engine, 'getInvoiceItems');

    return $method->invoke($engine, $invoice);
}

it('reads the real invoice lines, never a hardcoded stub', function () {
    $items = invoiceItemsFor(makeContentInvoice());

    expect($items)->toHaveCount(1)
        ->and($items[0]['description'])->toBe('Hosting anual Probe')
        ->and($items[0]['description'])->not->toBe('Servicio 1');
});

it('renders each amount as an exact string, honouring its own scale', function () {
    $items = invoiceItemsFor(makeContentInvoice());

    // The trap: the blades divided unit_price by 100 but not quantity. Getting this
    // wrong prints 150.00 units or €0.12 — today it only survives because the stub
    // passes a literal 1.
    expect($items[0]['quantity'])->toBe('1.50')
        ->and($items[0]['unit_price'])->toBe('100.00')
        ->and($items[0]['taxable_amount'])->toBe('100.00')
        ->and($items[0]['tax_amount'])->toBe('21.00')
        ->and($items[0]['total'])->toBe('121.00');
});

it('carries the line tax breakdown from the immutable snapshot', function () {
    $items = invoiceItemsFor(makeContentInvoice());

    expect($items[0]['taxes'])->toHaveCount(1)
        ->and($items[0]['taxes'][0]['name'])->toBe('IVA 21%')
        ->and($items[0]['taxes'][0]['rate'])->toBe('21.00')
        ->and($items[0]['taxes'][0]['amount'])->toBe('21.00');
});

it('returns no lines for an invoice with none, never an invented one', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'EMPTY-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);

    expect(invoiceItemsFor($invoice))->toBe([]);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/InvoiceContentComplianceTest.php`
Expected: FAIL — every test. The stub returns `'Servicio 1'` with `null` amounts.

- [ ] **Step 3: Implement**

In `src/Services/PDF/DomPDFService.php`, replace `getInvoiceItems()` (`:381-394`) with:

```php
    /**
     * Get the invoice's real lines for the template.
     *
     * Amounts are handed over as EXACT STRINGS (AID-508): money never travels to a
     * blade as a number to be divided there. The scale belongs to each value —
     * `quantity` is 1.50 units, `unit_price` is €100.00 — and FixedDecimal knows it,
     * so the template does no arithmetic at all.
     *
     * @param  Invoice  $invoice  The invoice
     * @return array<int, array<string, mixed>> Invoice lines
     */
    protected function getInvoiceItems(Invoice $invoice): array
    {
        return $invoice->items->map(fn (InvoiceItem $item): array => [
            'description'    => $item->description,
            'quantity'       => $item->quantity->toDecimalString(),
            'unit_price'     => $item->unit_price->toDecimalString(),
            'taxable_amount' => $item->taxable_amount->toDecimalString(),
            'tax_amount'     => $item->total_tax_amount->toDecimalString(),
            'total'          => $item->total_amount->toDecimalString(),
            'taxes'          => $this->formatLineTaxes($item),
        ])->all();
    }

    /**
     * Format a line's immutable tax snapshot (`invoice_items.taxes_applied`).
     *
     * Shape persisted by VatCalculationStrategy:
     * ['source_rate_id' => int, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100]
     * where `rate` is base-100 of the percentage (2100 = 21%) and `amount` base-100
     * euros. The rate is a string too: an int cannot carry a 5.2% equivalence surcharge.
     *
     * @return array<int, array<string, string>>
     */
    protected function formatLineTaxes(InvoiceItem $item): array
    {
        return array_map(fn (array $tax): array => [
            'name'   => (string) ($tax['name'] ?? ''),
            'rate'   => FixedDecimal::ofUnscaled((int) ($tax['rate'] ?? 0), 2)->toDecimalString(),
            'amount' => FixedDecimal::ofUnscaled((int) ($tax['amount'] ?? 0), 2)->toDecimalString(),
        ], $item->taxes_applied ?? []);
    }
```

Add imports: `use AichaDigital\Larabill\Models\InvoiceItem;` and `use AichaDigital\Lara100\ValueObjects\FixedDecimal;`

> Verify the `FixedDecimal` namespace against an existing import before writing it: `grep -rn "use.*FixedDecimal;" src/Models/Invoice.php`

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/InvoiceContentComplianceTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
php83 vendor/bin/pint --dirty
git add src/Services/PDF/DomPDFService.php tests/Unit/Services/PDF/InvoiceContentComplianceTest.php
git commit -m "fix(pdf): read the real invoice lines (AID-508)

getInvoiceItems() was a hardcoded stub — «Servicio 1», quantity 1, amounts from
columns that do not exist — carrying its own confession: 'This should load from
invoice_items relationship'. Amounts are handed over as exact strings so the
template does no arithmetic on money."
```

---

### Task 8: Real totals and the tax breakdown

**Files:**
- Modify: `src/Services/PDF/DomPDFService.php:402-410`
- Test: `tests/Unit/Services/PDF/InvoiceContentComplianceTest.php` (append)

**Interfaces:**
- Consumes: `Invoice` casts (`taxable_amount`, `total_tax_amount`, `total_amount` are `FixedDecimalCast:2`), `getInvoiceItems()` from Task 7.
- Produces: `getInvoiceTotals()` returns `subtotal`, `tax_amount`, `total` (exact strings), `currency`, and `tax_breakdown` — one entry per tax rate: `['rate' => '21.00', 'name' => 'IVA 21%', 'base' => '100.00', 'amount' => '21.00']`. Task 9 renders one row per entry.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/PDF/InvoiceContentComplianceTest.php`:

```php
function invoiceTotalsFor(Invoice $invoice): array
{
    $engine = new DomPDFService([]);
    $method = new ReflectionMethod($engine, 'getInvoiceTotals');

    return $method->invoke($engine, $invoice);
}

it('reads the real totals from the real columns', function () {
    $totals = invoiceTotalsFor(makeContentInvoice());

    // The old code read $invoice->subtotal / ->tax_amount / ->total: columns that do
    // not exist. Eloquent returned null and the blade printed "0.00" without a peep.
    expect($totals['subtotal'])->toBe('100.00')
        ->and($totals['tax_amount'])->toBe('21.00')
        ->and($totals['total'])->toBe('121.00');
});

it('breaks the tax down per rate on a mixed-rate invoice', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'    => 'MIXED-1',
        'serie'            => InvoiceSerieType::INVOICE->value,
        'status'           => InvoiceStatus::DRAFT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'taxable_amount'   => cents(15000),
        'total_tax_amount' => cents(2600),
        'total_amount'     => cents(17600),
    ]);
    InvoiceItem::factory()->create([
        'invoice_id'     => $invoice->id,
        'description'    => 'Standard rate line',
        'quantity'       => cents(100),
        'unit_price'     => cents(10000),
        'taxable_amount' => cents(10000),
        'total_tax_amount' => cents(2100),
        'total_amount'   => cents(12100),
        'taxes_applied'  => [['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100]],
    ]);
    InvoiceItem::factory()->create([
        'invoice_id'     => $invoice->id,
        'description'    => 'Reduced rate line',
        'quantity'       => cents(100),
        'unit_price'     => cents(5000),
        'taxable_amount' => cents(5000),
        'total_tax_amount' => cents(500),
        'total_amount'   => cents(5500),
        'taxes_applied'  => [['source_rate_id' => 2, 'name' => 'IVA 10%', 'rate' => 1000, 'amount' => 500]],
    ]);

    $breakdown = invoiceTotalsFor($invoice->fresh())['tax_breakdown'];

    // RD 1619/2012 art. 6.1.g/h: base and rate, broken down when the invoice
    // comprises several. The old blade printed $items[0]['tax_rate'] for the lot.
    expect($breakdown)->toHaveCount(2)
        ->and($breakdown[0])->toMatchArray(['rate' => '21.00', 'name' => 'IVA 21%', 'base' => '100.00', 'amount' => '21.00'])
        ->and($breakdown[1])->toMatchArray(['rate' => '10.00', 'name' => 'IVA 10%', 'base' => '50.00', 'amount' => '5.00']);
});

it('returns an empty breakdown for an invoice with no taxes', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'    => 'NOTAX-1',
        'serie'            => InvoiceSerieType::INVOICE->value,
        'status'           => InvoiceStatus::DRAFT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(0),
        'total_amount'     => cents(10000),
    ]);
    InvoiceItem::factory()->create([
        'invoice_id'     => $invoice->id,
        'description'    => 'Exempt line',
        'quantity'       => cents(100),
        'unit_price'     => cents(10000),
        'taxable_amount' => cents(10000),
        'total_tax_amount' => cents(0),
        'total_amount'   => cents(10000),
        'taxes_applied'  => [],
    ]);

    // Never the hardcoded `?? 21` the old blade fell back to.
    expect(invoiceTotalsFor($invoice->fresh())['tax_breakdown'])->toBe([]);
});

it('formats a rate with decimals, which an int cannot carry', function () {
    $invoice = makeContentInvoice();
    $invoice->items()->first()->update([
        'taxes_applied' => [['source_rate_id' => 9, 'name' => 'Recargo 5,2%', 'rate' => 520, 'amount' => 520]],
    ]);

    $breakdown = invoiceTotalsFor($invoice->fresh())['tax_breakdown'];

    expect($breakdown[0]['rate'])->toBe('5.20');
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/InvoiceContentComplianceTest.php`
Expected: FAIL — totals are `null` and `tax_breakdown` does not exist.

- [ ] **Step 3: Implement**

Replace `getInvoiceTotals()` (`:402-410`) with:

```php
    /**
     * Get the invoice totals for the template.
     *
     * Reads the REAL columns (AID-508): the old code read $invoice->subtotal,
     * ->tax_amount and ->total — none of which exist. Eloquent returned null and
     * the blade's number_format(null / 100, 2) printed "0.00" with no error and no
     * warning, on an invoice of €121.00.
     *
     * @param  Invoice  $invoice  The invoice
     * @return array<string, mixed> Totals, as exact strings
     */
    protected function getInvoiceTotals(Invoice $invoice): array
    {
        return [
            'subtotal'      => $invoice->taxable_amount->toDecimalString(),
            'tax_amount'    => $invoice->total_tax_amount->toDecimalString(),
            'total'         => $invoice->total_amount->toDecimalString(),
            'currency'      => 'EUR',
            'tax_breakdown' => $this->taxBreakdown($invoice),
        ];
    }

    /**
     * Aggregate the per-rate tax breakdown from the lines' immutable snapshots.
     *
     * RD 1619/2012 art. 6.1.g/h requires the taxable base and the rate, broken down
     * when the invoice comprises several. The old templates printed
     * `$items[0]['tax_rate'] ?? 21` — the first line's rate for the whole invoice,
     * with an invented fallback.
     *
     * Grouped by `source_rate_id` (the rate's identity, not its percentage: the
     * snapshot keeps whatever was in force at issuance). A line bearing two taxes on
     * the same base contributes its base to both, which is correct.
     *
     * @return array<int, array<string, string>>
     */
    protected function taxBreakdown(Invoice $invoice): array
    {
        $groups = [];

        foreach ($invoice->items as $item) {
            foreach ($item->taxes_applied ?? [] as $tax) {
                $key = (string) ($tax['source_rate_id'] ?? $tax['rate'] ?? 'unknown');

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'name'   => (string) ($tax['name'] ?? ''),
                        'rate'   => (int) ($tax['rate'] ?? 0),
                        'base'   => 0,
                        'amount' => 0,
                    ];
                }

                $groups[$key]['base']   += $item->taxable_amount->unscaledValue();
                $groups[$key]['amount'] += (int) ($tax['amount'] ?? 0);
            }
        }

        return array_values(array_map(fn (array $group): array => [
            'name'   => $group['name'],
            'rate'   => FixedDecimal::ofUnscaled($group['rate'], 2)->toDecimalString(),
            'base'   => FixedDecimal::ofUnscaled($group['base'], 2)->toDecimalString(),
            'amount' => FixedDecimal::ofUnscaled($group['amount'], 2)->toDecimalString(),
        ], $groups));
    }
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/InvoiceContentComplianceTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
php83 vendor/bin/pint --dirty
git add src/Services/PDF/DomPDFService.php tests/Unit/Services/PDF/InvoiceContentComplianceTest.php
git commit -m "fix(pdf): real totals and a real per-rate tax breakdown (AID-508)

getInvoiceTotals() read subtotal/tax_amount/total: columns that do not exist.
Eloquent returned null, number_format(null / 100, 2) printed 0.00, and a €121.00
invoice came out at zero without an error. The totals block also took the first
line's rate for the whole invoice with a hardcoded 21 fallback, misreporting
every mixed-rate invoice — RD 1619/2012 art. 6.1.g/h requires the breakdown."
```

---

### Task 9: The issuer comes from the frozen snapshot

**Files:**
- Modify: `src/Services/PDF/DomPDFService.php:334-348` (`getCompanyData`), `:559-564` (`getCompanyId`), `:282` (call site)
- Test: `tests/Unit/Services/PDF/InvoiceContentComplianceTest.php` (append)

**Interfaces:**
- Consumes: `Invoice::getIssuerSnapshotData(): ?array` (`:713`), whose keys are `config_id`, `business_name`, `tax_id`, `legal_entity_type`, `address`, `city`, `state`, `zip_code`, `country_code`, `is_oss`, `is_roi`, `currency`, `valid_from`, `valid_until`, `snapshot_at`.
- Produces: `getCompanyData(Invoice $invoice): array` — **signature changes** (it takes the invoice now). Keys: `name`, `address`, `city`, `postal_code`, `country`, `tax_id`. **No `phone`, `email` or `website`**: those fields do not exist in `CompanyFiscalConfig` and were invented by the stub. Task 11 removes them from the blades.

**Coherent with AID-328:** the frozen side is the persisted snapshot, never the live row. Legacy invoices with no snapshot fall back to the config row referenced by `company_fiscal_config_id`, with the limitation documented.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/PDF/InvoiceContentComplianceTest.php`:

```php
function companyDataFor(Invoice $invoice): array
{
    $engine = new DomPDFService([]);
    $method = new ReflectionMethod($engine, 'getCompanyData');

    return $method->invoke($engine, $invoice);
}

it('reads the issuer from the frozen snapshot, not from config or fantasy', function () {
    $config  = CompanyFiscalConfig::factory()->create([
        'business_name' => 'Castris Conformance SL',
        'tax_id'        => 'ESB12345678',
        'address'       => 'Calle Real 1',
        'city'          => 'Granada',
        'zip_code'      => '18001',
        'country_code'  => 'ES',
    ]);
    $invoice = makeContentInvoice();
    $invoice->company_fiscal_config_id = $config->id;
    $invoice->snapshotFiscalConfigs();
    $invoice->save();

    $company = companyDataFor($invoice->fresh());

    expect($company['name'])->toBe('Castris Conformance SL')
        ->and($company['tax_id'])->toBe('ESB12345678')
        ->and($company['address'])->toBe('Calle Real 1')
        ->and($company['postal_code'])->toBe('18001')
        ->and($company['city'])->toBe('Granada')
        // The stub's fantasy, which reached the customer's PDF:
        ->and($company['name'])->not->toBe('Mi Empresa')
        ->and($company['tax_id'])->not->toBe('NIF: 12345678A');
});

it('carries no invented contact details', function () {
    $company = companyDataFor(makeContentInvoice());

    // CompanyFiscalConfig has no phone/email/website. The stub invented
    // +34 123 456 789 / info@empresa.com / https://empresa.com and printed them.
    expect($company)->not->toHaveKeys(['phone', 'email', 'website']);
});
```

Add the import: `use AichaDigital\Larabill\Models\CompanyFiscalConfig;`

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/InvoiceContentComplianceTest.php`
Expected: FAIL — `getCompanyData()` takes no argument and returns the hardcoded fantasy.

- [ ] **Step 3: Implement**

Replace `getCompanyData()` (`:334-348`) with:

```php
    /**
     * Get the issuer's data for the template, from the invoice's frozen snapshot.
     *
     * The issuer's identity is the one AT ISSUANCE, not today's configuration
     * (ADR-001; AID-328 established that the frozen side is the persisted snapshot,
     * never the live row — which can be edited in place).
     *
     * Legacy invoices with no snapshot fall back to the referenced config row. That
     * row may have been edited since, so the identity is best-effort: a documented
     * limitation, not a guarantee.
     *
     * No phone/email/website: those fields do not exist in CompanyFiscalConfig, and
     * the old stub simply invented them (AID-508).
     *
     * @param  Invoice  $invoice  The invoice
     * @return array<string, mixed> Issuer data
     */
    protected function getCompanyData(Invoice $invoice): array
    {
        $snapshot = $invoice->getIssuerSnapshotData() ?? $this->legacyIssuerData($invoice);

        if ($snapshot === null) {
            return [];
        }

        return [
            'name'        => $snapshot['business_name'] ?? null,
            'address'     => $snapshot['address']       ?? null,
            'city'        => $snapshot['city']          ?? null,
            'postal_code' => $snapshot['zip_code']      ?? null,
            'country'     => $snapshot['country_code']  ?? null,
            'tax_id'      => $snapshot['tax_id']        ?? null,
        ];
    }

    /**
     * Best-effort issuer data for invoices frozen before the snapshot existed.
     *
     * @return array<string, mixed>|null
     */
    protected function legacyIssuerData(Invoice $invoice): ?array
    {
        $config = $invoice->companyFiscalConfig;

        return $config === null ? null : $config->only([
            'business_name', 'tax_id', 'address', 'city', 'zip_code', 'country_code',
        ]);
    }
```

Update the call site in `prepareTemplateData()` (`:282`):

```php
            'company'           => $this->getCompanyData($invoice),
```

Replace `getCompanyId()` (`:559-564`) with:

```php
    /**
     * The issuer whose template settings apply: the invoice's own fiscal config.
     *
     * The old stub returned config('larabill.default_company_id', 'default') — a key
     * that does not exist — so notes and payment terms silently resolved against a
     * company that was never there (AID-508).
     */
    protected function getCompanyId(Invoice $invoice): string
    {
        return (string) $invoice->company_fiscal_config_id;
    }
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/InvoiceContentComplianceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php83 vendor/bin/pint --dirty
git add src/Services/PDF/DomPDFService.php tests/Unit/Services/PDF/InvoiceContentComplianceTest.php
git commit -m "fix(pdf): the issuer comes from the invoice's frozen snapshot (AID-508)

getCompanyData() returned hardcoded fantasy — 'Mi Empresa', 'Dirección de la
empresa', 'NIF: 12345678A', 'info@empresa.com' — and those values reached the
customer's PDF. It now reads issuer_snapshot: the identity at issuance, not
today's configuration (AID-328). getCompanyId() resolved template settings
against config('larabill.default_company_id'), a key that does not exist."
```

---

### Task 10: One root, one filename, no public URL

**Files:**
- Modify: `src/Services/PDF/DomPDFService.php:419-436`, delete `:444-454` (`generatePDFUrl`); `src/Models/Invoice.php:541-565`
- Test: `tests/Unit/Services/PDF/PDFServiceTest.php` (append)

**Interfaces:**
- Consumes: `Invoice::getInvoiceType()`.
- Produces: `savePDF()` and `Invoice::getPDFPath()` compose the same name under the same root. `Invoice::getPDFUrl(): ?string` returns `null` always. `generatePDFUrl()` no longer exists.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/PDF/PDFServiceTest.php`:

```php
it('finds the file it just wrote, so the PDF is not regenerated on every download', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'PATH-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);

    $result = (new PDFService)->generatePDF($invoice);

    // savePDF() used $invoice->type (a column that does not exist) → invoice_<id>_.pdf
    // while getPDFPath() used getInvoiceType() → invoice_<id>_invoice.pdf. They never
    // matched, so the consumer's controller regenerated the PDF on every download.
    expect($result['success'])->toBeTrue()
        ->and($invoice->getPDFPath())->not->toBeNull()
        ->and($invoice->getPDFPath())->toBe($result['pdf_path']);
});

it('publishes no url for a private invoice', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'PATH-2',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['pdf_url'])->toBeNull()
        ->and($invoice->getPDFUrl())->toBeNull()
        ->and(json_encode($result))->not->toContain('storage/invoices');
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/PDFServiceTest.php`
Expected: FAIL — `getPDFPath()` returns `null` while the file exists, and `pdf_url` carries `http://localhost/storage/invoices/...`.

- [ ] **Step 3: Fix `savePDF()` and delete `generatePDFUrl()`**

Replace `savePDF()` (`:419-436`) with:

```php
    /**
     * Save the PDF under the single, private root.
     *
     * One root and one name (AID-508). The name used to be composed from
     * $invoice->type — a column that does not exist — so it never matched what
     * Invoice::getPDFPath() looked for, and the consumer regenerated the PDF on
     * every single download. The temp/storage fork died with isProductionEnvironment().
     *
     * @param  Invoice  $invoice  The invoice
     * @param  string  $pdfContent  PDF content
     * @return string PDF file path
     */
    protected function savePDF(Invoice $invoice, string $pdfContent): string
    {
        $pdfPath = storage_path('app/invoices/'.$invoice->pdfFilename());

        File::ensureDirectoryExists(dirname($pdfPath), 0755);
        File::put($pdfPath, $pdfContent);

        return $pdfPath;
    }
```

Delete `generatePDFUrl()` (`:444-454`) entirely, and its call site at `:88` (Task 5 already set `'pdf_url' => null`).

- [ ] **Step 4: Give the model the single naming rule**

In `src/Models/Invoice.php`, replace `getPDFPath()` and `getPDFUrl()` (`:541-565`) with:

```php
    /**
     * The invoice PDF's filename. Single source of the name, shared with the
     * writer (DomPDFService::savePDF) so both sides always agree (AID-508).
     */
    public function pdfFilename(): string
    {
        return 'invoice_'.$this->id.'_'.$this->getInvoiceType().'.pdf';
    }

    /**
     * Get PDF path for this invoice
     *
     * @return string|null PDF file path
     */
    public function getPDFPath(): ?string
    {
        $pdfPath = storage_path('app/invoices/'.$this->pdfFilename());

        return file_exists($pdfPath) ? $pdfPath : null;
    }

    /**
     * Get PDF URL for this invoice
     *
     * Always null: larabill does not publish invoices. Delivery is the consumer's
     * responsibility, through an authorised controller. The old
     * url('storage/invoices/...') was an UNAUTHORISED public link that bypassed the
     * consumer's policy — and a URL leaks through logs, history and Referer headers,
     * so protection cannot rest on a UUID being hard to guess (AID-508).
     *
     * @return string|null Always null
     */
    public function getPDFUrl(): ?string
    {
        return null;
    }
```

> `pdfFilename()` is a new public method on an `@api` model: it changes `tests/Contract/snapshots/Invoice.json`. That is a legitimate surface addition — regenerate the snapshot in Task 15 with `bin/sync-contract-snapshots`, never by hand.

- [ ] **Step 5: Run the tests and watch them pass**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/PDFServiceTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
php83 vendor/bin/pint --dirty
git add src/Services/PDF/DomPDFService.php src/Models/Invoice.php tests/Unit/Services/PDF/PDFServiceTest.php
git commit -m "fix(pdf): one root, one filename, no public URL (AID-508)

The writer composed the name from \$invoice->type (nonexistent column) and the
reader from getInvoiceType(): they never matched, so getPDFPath() returned null
on a file that existed and the consumer regenerated the PDF on every download.
getPDFUrl() now returns null: url('storage/invoices/...') was an unauthorised
public link bypassing the consumer's policy — only the name mismatch kept that
hole shut."
```

---

### Task 11: The templates print the truth

**Files:**
- Modify: all six of `resources/views/pdf/invoice/{fiscal,fiscal-minimal,fiscal-modern,proforma,reverse-charge,exempt}.blade.php`
- Test: `tests/Unit/Services/PDF/InvoiceContentComplianceTest.php` (append)

**Interfaces:**
- Consumes: `$items` and `$totals` from Tasks 7-8 (exact strings, `taxes` per line, `tax_breakdown`), `$company` from Task 9 (no contact keys).
- Produces: nothing downstream.

**Three edits per template.** Line numbers below are today's; re-locate by content, not by number, since earlier edits shift them.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/PDF/InvoiceContentComplianceTest.php`:

```php
function renderContentTemplate(string $template, Invoice $invoice): string
{
    $engine  = new DomPDFService([]);
    $prepare = new ReflectionMethod($engine, 'prepareTemplateData');
    $render  = new ReflectionMethod($engine, 'renderTemplate');

    return $render->invoke($engine, $template, $prepare->invoke($engine, $invoice, null, false));
}

it('prints the fiscal number, never an empty accessor', function (string $template) {
    $html = renderContentTemplate($template, makeContentInvoice());

    // $invoice->number does not exist and never did: the six templates printed blank.
    expect($html)->toContain('CASTRIS-2026-000001');
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
    'fiscal-modern'  => 'larabill::pdf.invoice.fiscal-modern',
    'proforma'       => 'larabill::pdf.invoice.proforma',
    'reverse-charge' => 'larabill::pdf.invoice.reverse-charge',
    'exempt'         => 'larabill::pdf.invoice.exempt',
]);

it('prints the real line and the real amounts', function (string $template) {
    $html = renderContentTemplate($template, makeContentInvoice());

    expect($html)->toContain('Hosting anual Probe')
        ->and($html)->toContain('121.00')      // the real total
        ->and($html)->not->toContain('Servicio 1')
        ->and($html)->not->toContain('0.00 €'); // what a €121.00 invoice used to print
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
    'fiscal-modern'  => 'larabill::pdf.invoice.fiscal-modern',
    'proforma'       => 'larabill::pdf.invoice.proforma',
    'reverse-charge' => 'larabill::pdf.invoice.reverse-charge',
    'exempt'         => 'larabill::pdf.invoice.exempt',
]);

it('prints the quantity at its own scale', function (string $template) {
    $html = renderContentTemplate($template, makeContentInvoice());

    expect($html)->toContain('1.50')       // 1.50 units
        ->and($html)->not->toContain('150.00');
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
    'fiscal-modern'  => 'larabill::pdf.invoice.fiscal-modern',
    'proforma'       => 'larabill::pdf.invoice.proforma',
    'reverse-charge' => 'larabill::pdf.invoice.reverse-charge',
    'exempt'         => 'larabill::pdf.invoice.exempt',
]);

it('carries no invented contact details', function (string $template) {
    $html = renderContentTemplate($template, makeContentInvoice());

    expect($html)->not->toContain('info@empresa.com')
        ->and($html)->not->toContain('+34 123 456 789')
        ->and($html)->not->toContain('https://empresa.com');
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
    'fiscal-modern'  => 'larabill::pdf.invoice.fiscal-modern',
    'proforma'       => 'larabill::pdf.invoice.proforma',
    'reverse-charge' => 'larabill::pdf.invoice.reverse-charge',
    'exempt'         => 'larabill::pdf.invoice.exempt',
]);
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/InvoiceContentComplianceTest.php`
Expected: FAIL on all four across all six templates.

- [ ] **Step 3: Edit A — the fiscal number, in all six**

Replace both occurrences of `$invoice->number` per template (the `<title>` and the «Número» line):

```blade
{{-- before --}}
<title>Factura #{{ $invoice->number }}</title>
<div><strong>Número:</strong> {{ $invoice->number }}</div>

{{-- after --}}
<title>Factura #{{ $invoice->fiscal_number }}</title>
<div><strong>Número:</strong> {{ $invoice->fiscal_number }}</div>
```

Locations: `fiscal.blade.php:6,164` · `fiscal-minimal.blade.php:6,147` · `fiscal-modern.blade.php:6,185` · `proforma.blade.php:6,160` · `reverse-charge.blade.php:6,158` · `exempt.blade.php:6,158`. Keep each template's own wording (`Proforma #`, `Factura Reverse Charge #`, …).

- [ ] **Step 4: Edit B — the item rows, in all six**

```blade
{{-- before --}}
<td>{{ $item['description'] }}</td>
<td class="text-right">{{ number_format($item['quantity'], 2) }}</td>
<td class="text-right">{{ number_format($item['unit_price'] / 100, 2) }} €</td>
<td class="text-right">{{ $item['tax_rate'] }}%</td>
<td class="text-right">{{ number_format($item['tax_amount'] / 100, 2) }} €</td>
<td class="text-right">{{ number_format($item['total'] / 100, 2) }} €</td>

{{-- after --}}
<td>{{ $item['description'] }}</td>
<td class="text-right">{{ $item['quantity'] }}</td>
<td class="text-right">{{ $item['unit_price'] }} €</td>
<td class="text-right">{{ $item['taxes'] ? collect($item['taxes'])->pluck('rate')->implode('% + ').'%' : '—' }}</td>
<td class="text-right">{{ $item['tax_amount'] }} €</td>
<td class="text-right">{{ $item['total'] }} €</td>
```

The service hands over exact strings; the template does no arithmetic on money. A line with two taxes on the same base prints `21.00% + 5.20%`; a line with none prints `—`, never a bare `%` and never an invented rate.

- [ ] **Step 5: Edit C — the totals block with the real breakdown, in all six**

```blade
{{-- before --}}
<tr>
    <td>Subtotal:</td>
    <td class="text-right">{{ number_format($totals['subtotal'] / 100, 2) }} €</td>
</tr>
<tr>
    <td>IVA ({{ $items[0]['tax_rate'] ?? 21 }}%):</td>
    <td class="text-right">{{ number_format($totals['tax_amount'] / 100, 2) }} €</td>
</tr>
<tr class="total-row">
    <td>TOTAL:</td>
    <td class="text-right">{{ number_format($totals['total'] / 100, 2) }} €</td>
</tr>

{{-- after --}}
<tr>
    <td>Subtotal:</td>
    <td class="text-right">{{ $totals['subtotal'] }} €</td>
</tr>
@foreach($totals['tax_breakdown'] as $tax)
    <tr>
        <td>{{ $tax['name'] }} ({{ $tax['rate'] }}% s/ {{ $tax['base'] }} €):</td>
        <td class="text-right">{{ $tax['amount'] }} €</td>
    </tr>
@endforeach
<tr class="total-row">
    <td>TOTAL:</td>
    <td class="text-right">{{ $totals['total'] }} €</td>
</tr>
```

One row per rate (RD 1619/2012 art. 6.1.g/h). No breakdown, no rows — never the invented `?? 21`.

- [ ] **Step 6: Edit D — remove the invented contact details, in all six**

Delete every line referencing `$company['phone']`, `$company['email']` or `$company['website']`. In `fiscal.blade.php` that is `:158`, `:159` and the whole footer line `:290`:

```blade
{{-- delete --}}
<div>{{ $company['phone'] }}</div>
<div>{{ $company['email'] }}</div>
<p>Tel: {{ $company['phone'] }} - Email: {{ $company['email'] }} - Web: {{ $company['website'] }}</p>
```

Keep the footer's first line (`{{ $company['name'] }} - {{ $company['address'] }} - {{ $company['tax_id'] }}`): name, NIF and address are the issuer's fiscal identity (RD 1619/2012 art. 6.1). Phone, email and website do not exist in `CompanyFiscalConfig` — the stub invented them. They are not passed as null or empty strings: they are gone.

- [ ] **Step 7: Run the tests and watch them pass**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/InvoiceContentComplianceTest.php`
Expected: PASS (24 assertions across six templates).

- [ ] **Step 8: Commit**

```bash
php83 vendor/bin/pint --dirty
git add resources/views/pdf/invoice/ tests/Unit/Services/PDF/InvoiceContentComplianceTest.php
git commit -m "fix(pdf): the six templates print the real invoice (AID-508)

\$invoice->number never existed: all six printed a blank number, in the title and
in the number line. Money now arrives as exact strings, so the templates do no
arithmetic — they mixed two scales on consecutive lines and only survived
because the stub passed a literal 1. The totals block prints one row per tax
rate instead of the first line's rate for the whole invoice. And the invented
contact details are gone: CompanyFiscalConfig has no phone, email or website."
```

---

### Task 12: The QR block

**Files:**
- Modify: `resources/views/pdf/invoice/{fiscal,fiscal-minimal,fiscal-modern,reverse-charge,exempt}.blade.php`, `proforma.blade.php`
- Test: `tests/Unit/Services/PDF/FiscalQrPolicyTest.php` (append)

**Interfaces:**
- Consumes: `$qr_data` with `qr_svg` or `qr_png` (Task 4), `$include_qr`.
- Produces: nothing downstream.

**The five fiscal templates render the QR; the proforma loses its block entirely** (spec §4.3). Today only `fiscal.blade.php` can paint the real QR — the other four would dump the escaped SVG as text.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/PDF/FiscalQrPolicyTest.php`:

```php
function renderQrTemplate(string $template, Invoice $invoice, ?array $qrData): string
{
    $engine  = new DomPDFService([]);
    $prepare = new ReflectionMethod($engine, 'prepareTemplateData');
    $render  = new ReflectionMethod($engine, 'renderTemplate');

    return $render->invoke($engine, $template, $prepare->invoke($engine, $invoice, $qrData, $qrData !== null));
}

it('renders the QR as an image with its label and legal size', function (string $template) {
    $qr = ['qr_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect/></svg>', 'qr_code' => 'x'];

    $html = renderQrTemplate($template, makeQrInvoice(InvoiceSerieType::INVOICE, registered: true), $qr);

    // AEAT QR spec v0.4.7 art. 20-21: 30x30..40x40 mm, preceded by «QR tributario:».
    expect($html)->toContain('QR tributario')
        ->and($html)->toContain('35mm')
        ->and($html)->toContain('<svg')
        ->and($html)->not->toContain('&lt;svg')   // escaped SVG dumped as text
        ->and($html)->not->toContain('QR_CODE');
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
    'fiscal-modern'  => 'larabill::pdf.invoice.fiscal-modern',
    'reverse-charge' => 'larabill::pdf.invoice.reverse-charge',
    'exempt'         => 'larabill::pdf.invoice.exempt',
]);

it('never renders a QR on a proforma, even when one is injected', function () {
    $qr = ['qr_svg' => '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>', 'qr_code' => 'x'];

    $html = renderQrTemplate('larabill::pdf.invoice.proforma', makeQrInvoice(InvoiceSerieType::PROFORMA, registered: false), $qr);

    expect($html)->not->toContain('QR tributario')
        ->and($html)->not->toContain('<svg')
        ->and($html)->not->toContain('QR_CODE');
});

it('prints no tax block at all when there is no QR', function (string $template) {
    $html = renderQrTemplate($template, makeQrInvoice(InvoiceSerieType::INVOICE, registered: false), null);

    expect($html)->not->toContain('QR tributario')
        ->and($html)->not->toContain('QR_CODE');
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
]);
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/FiscalQrPolicyTest.php`
Expected: FAIL — the four laggards print `QR: {{ $qr_data['qr_code'] ?? 'QR_CODE' }}`, and the proforma has a QR block.

- [ ] **Step 3: Give the four laggards the real block**

In `fiscal-minimal.blade.php:221`, `fiscal-modern.blade.php:259-261`, `reverse-charge.blade.php:240-243` and `exempt.blade.php:240-243`, replace the QR block with:

```blade
@if($include_qr && (!empty($qr_data['qr_svg']) || !empty($qr_data['qr_png'])))
    <div><strong>QR tributario:</strong></div>
    <div class="qr-code">
        @if(!empty($qr_data['qr_svg']))
            <div style="width: 35mm; height: 35mm;">{!! $qr_data['qr_svg'] !!}</div>
        @else
            <img src="{{ $qr_data['qr_png'] }}" alt="QR tributario" style="width: 35mm; height: 35mm;">
        @endif
    </div>
@endif
```

The `?? 'QR_CODE'` fallback is gone: a QR that is not an image is not printed as text.

- [ ] **Step 4: Complete `fiscal.blade.php`**

Its PNG branch already carries 35 mm; the SVG branch (`:241`) does not — and SVG is the listener's preferred format (`getQrSvg() ?? getQrPng() ?? ...`), so the likeliest path is the one breaching the size. Replace:

```blade
{{-- before --}}
@if(!empty($qr_data['qr_svg']))
    {!! $qr_data['qr_svg'] !!}

{{-- after --}}
@if(!empty($qr_data['qr_svg']))
    <div style="width: 35mm; height: 35mm;">{!! $qr_data['qr_svg'] !!}</div>
```

And delete its `@else` branch that printed `{{ $qr_data['qr_code'] ?? 'QR_CODE' }}` (`:245-247`).

- [ ] **Step 5: Remove the proforma's QR block**

In `proforma.blade.php`, delete the whole QR section. A proforma never carries a tax QR, in any state: it is not a fiscal document. The template does not learn to paint it — it stops having the option.

- [ ] **Step 6: Run the tests and watch them pass**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/FiscalQrPolicyTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
php83 vendor/bin/pint --dirty
git add resources/views/pdf/invoice/ tests/Unit/Services/PDF/FiscalQrPolicyTest.php
git commit -m "fix(pdf): five templates render the tax QR, the proforma has none (AID-508)

Only fiscal.blade.php could paint the real QR (AID-139 never propagated): the
other four would have dumped the escaped SVG as text, and printed the literal
'QR_CODE' when absent. fiscal.blade.php's SVG branch carried no dimensions —
and SVG is the preferred format, so the likeliest path breached the 30-40mm
requirement (AEAT QR spec v0.4.7 art. 20-21). The proforma loses its block."
```

---

### Task 13: The connector stops lying, and says so

**Files:**
- Modify: `src/Services/PDF/DefaultPDFConnector.php:219-270`
- Test: `tests/Unit/Services/PDF/DefaultPDFConnectorTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `DefaultPDFConnector::generateQR()` throws `LogicException`. `generateQRCode()` no longer exists.

**`PDFConnectorInterface` stays intact** — it is `@api` (`docs/api-surface.md:24`, `:40`) and removing it would need a major. Only the `@internal` implementation changes.

- [ ] **Step 1: Write the failing test**

In `tests/Unit/Services/PDF/DefaultPDFConnectorTest.php`, replace the metadata theatre (`it('returns metadata')`, `it('has no endpoint')`, `it('has no authentication')`, `it('supports all countries')`, `it('is available by default')`) with:

```php
it('refuses to fabricate a fiscal QR', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'CONNECTOR-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);

    // It used to return 'QR:'.substr($hash, 0, 16).':'.base64_encode(substr($json, 0, 100)):
    // plain text, not scannable, with the JSON truncated mid-key.
    expect(fn () => $this->connector->generateQR($invoice))
        ->toThrow(LogicException::class, 'does not generate fiscal QR codes');
});

it('no longer carries the fake QR generator', function () {
    expect(method_exists(DefaultPDFConnector::class, 'generateQRCode'))->toBeFalse();
});
```

Keep `it('can be instantiated')`, `it('returns correct connector type')`, `it('returns required fields')` and the `validateInvoice` tests: those assert real behaviour.

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/DefaultPDFConnectorTest.php`
Expected: FAIL — `generateQR()` happily returns the fake.

- [ ] **Step 3: Implement**

Replace `generateQRCode()` (`:261-270`) — delete it — and make `generateQR()` throw. Find `generateQR()` in the class and replace its body with:

```php
    /**
     * larabill does not generate fiscal QR codes.
     *
     * The tax QR is an effect of the fiscal billing record, and it is the registrar
     * (lara-verifactu) who builds it from the AEAT cotejo URL — nif, numserie, fecha,
     * importe — per the AEAT QR spec v0.4.7. This connector used to fabricate a
     * placeholder that was not a QR at all: plain text, unscannable, with the JSON
     * truncated to 100 bytes. Presenting a code without the mandatory cotejo URL is
     * the breach; fabricating it locally is not (the registrar does exactly that).
     *
     * The interface stays (@api): only this implementation refuses (AID-508).
     */
    public function generateQR(Invoice $invoice): array
    {
        throw new LogicException(
            'larabill does not generate fiscal QR codes: the tax QR comes from the fiscal '
            .'billing record (fiscal_verification_qr), never from the PDF pipeline.'
        );
    }
```

Also fix `prepareQRData():233`, which reads the nonexistent `tax_amount` column — if the method survives as a helper, it must read `total_tax_amount`. If it has no callers left after this change, delete it.

Run: `grep -rn "prepareQRData" src/ tests/` to decide.

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/DefaultPDFConnectorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php83 vendor/bin/pint --dirty
git add src/Services/PDF/DefaultPDFConnector.php tests/Unit/Services/PDF/DefaultPDFConnectorTest.php
git commit -m "fix(pdf): the connector refuses to fabricate a fiscal QR (AID-508)

generateQRCode() returned 'QR:'.\$hash.':'.base64(\$json truncated to 100 bytes):
plain text, no image, unscannable, decoding to a JSON cut mid-key. It confessed
three times in comments ('For now', 'In a real implementation', 'placeholder').
Its test asserted the connector's metadata and never once looked at what it
produced. The @api interface stays; only this @internal implementation refuses."
```

---

### Task 14: `template_name` that does not resolve says so

**Files:**
- Modify: `src/Services/PDF/DomPDFService.php:189-227`
- Test: `tests/Unit/Services/PDF/DomPdfFiscalFlagsTest.php` (append)

**Interfaces:**
- Consumes: nothing.
- Produces: a `Log::warning` when a configured `template_name` resolves to nothing.

- [ ] **Step 1: Write the failing test**

```php
it('warns when a configured template cannot be resolved, and uses the default', function () {
    Log::spy();
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'TEMPLATE-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
        'template_name' => 'a-template-that-does-not-resolve',
    ]);

    $engine = new DomPDFService([]);
    $method = new ReflectionMethod($engine, 'getTemplateForInvoice');

    expect($method->invoke($engine, $invoice))->toBe('larabill::pdf.invoice.fiscal');

    Log::shouldHaveReceived('warning')->once();
});

it('does not warn when no template was configured', function () {
    Log::spy();
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'TEMPLATE-2',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
        'template_name' => null,
    ]);

    $engine = new DomPDFService([]);
    (new ReflectionMethod($engine, 'getTemplateForInvoice'))->invoke($engine, $invoice);

    Log::shouldNotHaveReceived('warning');
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/DomPdfFiscalFlagsTest.php`
Expected: FAIL — no warning is emitted; the default is chosen in silence.

- [ ] **Step 3: Implement**

In `getTemplateForInvoice()`, after the `InvoiceTemplate::getByName()` lookup returns nothing:

```php
        if ($invoice->template_name) {
            $template = InvoiceTemplate::getByName($invoice->template_name, $invoice->getInvoiceType());

            if ($template) {
                return $template->template_path;
            }

            // The consumer asked for a template and did not get it. That deserves a
            // trace, not a hard failure: it is a presentation preference, not a
            // fiscal blocker — the document is still valid with the default.
            // The message does not claim WHY it failed: until AID-502 settles the
            // canonical vocabulary, we cannot tell a wrong name from the
            // type/`invoice` vs `fiscal` mismatch.
            Log::warning('Configured invoice PDF template could not be resolved; using the default template.', [
                'requested_template' => $invoice->template_name,
                'lookup_type'        => $invoice->getInvoiceType(),
                'invoice_id'         => $invoice->id,
            ]);
        }
```

Note there is no `try/catch` around the lookup: if the query explodes, that exception belongs to the frontier (Task 6).

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php83 vendor/bin/pest tests/Unit/Services/PDF/DomPdfFiscalFlagsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php83 vendor/bin/pint --dirty
git add src/Services/PDF/DomPDFService.php tests/Unit/Services/PDF/DomPdfFiscalFlagsTest.php
git commit -m "fix(pdf): say so when a configured template cannot be resolved (AID-508)

getByName(\$name, 'invoice') never matches — no seeded template has type
'invoice' — so a consumer's template_name was silently ignored and the default
used: the service pretended to obey. A warning, not an exception: it is a
presentation preference, not a fiscal blocker. The message does not claim why
it failed; until AID-502 we cannot tell a wrong name from the taxonomy mismatch."
```

---

### Task 15: End-to-end on the real PDF

**Files:**
- Modify: `composer.json` (require-dev)
- Create: `tests/Feature/PDF/InvoicePdfEndToEndTest.php`
- Modify: `tests/Contract/snapshots/Invoice.json` (regenerated, never hand-edited)

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

**Assert presence, not order or layout** — asserting the extracted text's ordering would be brittle.

- [ ] **Step 1: Add the dev dependency**

Run: `php83 "$(which composer)" require --dev smalot/pdfparser:^2.12`

> `php83`, not plain composer: larabill's local flow is pinned to 8.3 and composer resolves against the PHP that runs it.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/PDF/InvoicePdfEndToEndTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Services\PDF\PDFService;
use AichaDigital\Larabill\Tests\TestCase;
use Smalot\PdfParser\Parser;

/**
 * AID-508 — the test that would have caught all of it. The consumer's test asserted
 * $result['success'] === true and file_exists($result['pdf_path']): green over a
 * document with a blank number, an invented line, €0.00 totals and a fantasy issuer.
 *
 * This one reads the PDF that dompdf actually produces. It asserts PRESENCE of the
 * data, never order or layout of the extracted text — that would be brittle.
 */
it('produces a PDF whose text carries the real invoice', function () {
    $config = CompanyFiscalConfig::factory()->create([
        'business_name' => 'Castris Conformance SL',
        'tax_id'        => 'ESB12345678',
        'address'       => 'Calle Real 1',
        'city'          => 'Granada',
        'zip_code'      => '18001',
        'country_code'  => 'ES',
    ]);

    $invoice = Invoice::factory()->create([
        'fiscal_number'            => 'CASTRIS-2026-000001',
        'serie'                    => InvoiceSerieType::INVOICE->value,
        'status'                   => InvoiceStatus::SENT->value,
        'user_id'                  => TestCase::USER_UUID_1,
        'invoice_date'             => '2026-07-16',
        'company_fiscal_config_id' => $config->id,
        'taxable_amount'           => cents(10000),
        'total_tax_amount'         => cents(2100),
        'total_amount'             => cents(12100),
    ]);
    $invoice->snapshotFiscalConfigs();
    $invoice->save();

    InvoiceItem::factory()->create([
        'invoice_id'       => $invoice->id,
        'description'      => 'Hosting anual Probe',
        'quantity'         => cents(100),
        'unit_price'       => cents(10000),
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(2100),
        'total_amount'     => cents(12100),
        'taxes_applied'    => [['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100]],
    ]);

    $result = (new PDFService)->generatePDF($invoice->fresh());

    expect($result['success'])->toBeTrue();

    $text = (new Parser)->parseFile($result['pdf_path'])->getText();

    expect($text)->toContain('CASTRIS-2026-000001')     // the number, blank before AID-508
        ->and($text)->toContain('Hosting anual Probe')   // the real line, «Servicio 1» before
        ->and($text)->toContain('121.00')                // the real total, 0.00 before
        ->and($text)->toContain('100.00')                // the real base
        ->and($text)->toContain('21.00')                 // the real tax
        ->and($text)->toContain('Castris Conformance SL')
        ->and($text)->toContain('ESB12345678')
        ->and($text)->not->toContain('Servicio 1')
        ->and($text)->not->toContain('Mi Empresa')
        ->and($text)->not->toContain('12345678A')
        ->and($text)->not->toContain('info@empresa.com');
});
```

- [ ] **Step 3: Run it**

Run: `php83 vendor/bin/pest tests/Feature/PDF/InvoicePdfEndToEndTest.php`
Expected: PASS.

- [ ] **Step 4: Prove its sensitivity (house rule)**

Check out the pre-fix `DomPDFService` and confirm the test FAILS:

```bash
git stash
git checkout origin/main -- src/Services/PDF/DomPDFService.php
php83 vendor/bin/pest tests/Feature/PDF/InvoicePdfEndToEndTest.php
```

Expected: FAIL (blank number, `Servicio 1`, `0.00`). Then restore:

```bash
git checkout HEAD -- src/Services/PDF/DomPDFService.php
git stash pop
```

If it passes against the old code, the test is theatre — fix it before continuing.

- [ ] **Step 5: Regenerate the contract snapshot**

`Invoice::pdfFilename()` (Task 10) is a new public method, so the golden master must be re-stamped — with the script, never by hand:

```bash
php83 bin/sync-contract-snapshots
git diff tests/Contract/snapshots/Invoice.json
```

Expected diff: **only** the addition of `pdfFilename`. Any other drift means an unintended signature change — investigate before committing.

- [ ] **Step 6: Run everything**

```bash
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G
php83 vendor/bin/pest
```

Expected: all green, PHPStan level 8 clean.

- [ ] **Step 7: Commit**

```bash
git add composer.json tests/Feature/PDF/InvoicePdfEndToEndTest.php tests/Contract/snapshots/Invoice.json
git commit -m "test(pdf): read the PDF we actually produce (AID-508)

The test that would have caught all of it. The existing one asserted success ===
true and file_exists(): green over a document with a blank number, an invented
line, zero totals and a fantasy issuer. This parses dompdf's real output and
asserts the data is present — never order or layout, which would be brittle."
```

---

### Task 16: CHANGELOG and version

**Files:**
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: everything.
- Produces: the release entry.

**The CHANGELOG is the ONLY signal of this change.** The contract snapshots capture shape (`api`, `deprecated`, `parameters`, `returnType`, `static`), not truth: they would not have caught this defect and will not catch the semantic change. Write it as if it were the only thing a consumer reads — because it is.

- [ ] **Step 1: Confirm the version against the diff**

Run: `git diff origin/main --stat` and `git diff origin/main -- 'src/**/*.php' | grep -E '^\+.*(public|protected) (static )?function'`

Expected additions: `MissingFiscalVerificationQrException::forInvoice()`, `FiscalQrImage::classify()`, `Invoice::pdfFilename()`, plus one new config key. Public surface added, nothing removed, no signature changed, zero migrations ⇒ **MINOR: `v6.4.0`**. If the diff shows a removal from `@api` surface, stop: that would be a major and this PR is not one.

- [ ] **Step 2: Write the entry**

Add under `## [Unreleased]` in `CHANGELOG.md`:

```markdown
## [6.4.0] - 2026-07-16

Ships migrations: no.

### Fixed

- **The invoice PDF is an invoice again (AID-508).** The PDF pipeline was never finished and shipped anyway: the number printed blank (`$invoice->number` does not exist, in all six templates), lines were a hardcoded stub (`Servicio 1`, quantity 1), the totals read columns that do not exist (`subtotal`/`tax_amount`/`total` → `null` → `0.00` on a €121.00 invoice), and the issuer was fantasy (`Mi Empresa`, `NIF: 12345678A`, `info@empresa.com`). Everything now comes from the real models and the frozen issuer snapshot.
- **The tax QR is no longer fabricated.** `DefaultPDFConnector` produced `QR:<hash>:<base64 of the JSON truncated to 100 bytes>` — plain text, not an image, unscannable. It is gone; the QR comes from the fiscal record (`fiscal_verification_qr`) or it is not printed. **Behaviour change:** a fiscal invoice without a fiscal record now renders **without a tax block** (before: with a fake one). Set `larabill.pdf.require_fiscal_verification_qr` to `true` to make that a hard failure instead.
- **The tax breakdown is real.** The totals block printed the first line's rate for the entire invoice with a hardcoded `21%` fallback, misreporting every mixed-rate invoice. It now prints one row per rate with its base and amount (RD 1619/2012 art. 6.1.g/h). **Behaviour change:** invoices with several rates show several rows; invoices with no taxes show none, instead of a fabricated 21 %.
- **Simplified invoices carry their QR.** `shouldIncludeQR()` excluded `SIMPLIFIED`; a simplified invoice is a fiscal document and carries its tax QR. It is now governed by document class and fiscal registration only — invoice status governs nothing.
- **The PDF stops being regenerated on every download.** The writer composed the filename from `$invoice->type` (a nonexistent column) and the reader from `getInvoiceType()`: they never matched, so `getPDFPath()` returned `null` on a file that existed.
- **The service can fail now.** Eleven guards and eight `catch` blocks turned every error into filler; a failed generation could even be retried with the fake-QR connector and reported as a success. Inner layers propagate, `PDFService` logs and translates to `['success' => false]`. **Behaviour change:** conditions that used to yield a plausible PDF now return an explicit failure.
- **Amounts no longer travel to the templates as numbers to divide there.** The service hands over exact strings; the blades do no arithmetic on money. **Behaviour change:** amounts print without a thousands separator (`1234.56`, previously `1,234.56`). Neither is the Spanish format; localisation belongs to AID-502.

### Removed

- **`getPDFUrl()` returns `null`; invoices are private.** `url('storage/invoices/<file>.pdf')` was an unauthorised public link that bypassed the consumer's policy. Delivery is the consumer's responsibility, via an authorised controller.
- **Invented contact details.** Phone, email and website did not exist in `CompanyFiscalConfig` and were invented by the stub; they no longer print.
- `larabill.pdf.fallback_to_local` (the config key and its behaviour).

### Added

- `larabill.pdf.require_fiscal_verification_qr` (default `false`) — declares whether this installation's invoices must carry the fiscal QR. It states the **document contract**, not a legal obligation: larabill does not know your taxpayer type, exclusions, elected mode or adoption date. Turn it on when you actually operate that flow. VeriFACTU calendar: 2027-01-01 (corporate income tax payers) and 2027-07-01 (rest of art. 3.1 obliged parties); the earlier period is a trial one.
- `MissingFiscalVerificationQrException` — thrown when the contract above is enabled and a fiscal invoice has no usable QR.
- `Invoice::pdfFilename()` — the single source of the PDF filename, shared by writer and reader.

### Note for consumers

`PDFConnectorInterface` remains intact and `@api`, but larabill's pipeline no longer invokes any connector: the tax QR is an effect of the fiscal record, not something the PDF fabricates. If you implemented a connector, it will no longer be called. The contradiction this exposes — a public extension point registered through an `@internal` service — is tracked separately.
```

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs(changelog): v6.4.0 — AID-508 (PDF subsystem)"
```

---

## After the plan

**Do not tag or release.** Open the PR against `main` and stop. `bin/tag-release` runs the contract preflight and is a separate, deliberate step — and the version (`v6.4.0`) is confirmed with the diff in front, per the spec.

Open follow-up tickets (spec §8.2 and §5.2), which this PR deliberately does not resolve:

1. **The PDF subsystem's contract contradiction:** `PDFConnectorInterface` is `@api` but registers through `@internal` `PDFService::registerConnector()`; `Invoice::generatePDF()` cannot choose a connector; `docs/api-surface.md:40` claims `Invoice::generatePDF()` is public surface while the golden master records it as `api: false`; and `getPDFUrl()` can now only return `null` — surface that is dead weight, removable only in a major.
2. **`InvoiceService:225`** persists `'tax_rules_applied' => []` with a `// TODO: Add tax calculation details`: always empty.
3. **`PDFService:128-134`** had its success logging commented out with «disabled for testing» — restored for errors here, but review the whole subsystem's log noise, including the double-registration of a render failure (`renderTemplate()` logs and rethrows; the frontier logs again).
4. **Mutation testing (Infection)**, scoped to the money and fiscal subsystems. The `grep` for confessions found every corpse in the PDF subsystem — the rest of the package is clean — so this is a safety net, not an expedition.
5. **Governance gap — PDF presentation ownership (registered on AID-502, 2026-07-16).** The whole PDF subsystem entered in one commit (`922eed4`, 2025-10-05) nine months before the constitution; `products/larabill.md` never assigned presentation ownership, yet its identity clause protects `generatePDF()` by inheritance, and `docs/ARCHITECTURE.md` («Sin frontend») contradicts `README.md:29` («Built-in invoice PDF generation»). The decision — canonical renderer owned vs headless with a compatible extraction — is CENTRAL (contract amendment; an extraction is a major, subject to the constitutional gate). **This plan must not drift into that extraction:** it fixes the supported renderer so it does not lie, and nothing else. Item 1 above is a symptom of this same gap and resolves with it, on AID-502.
