# API surface taxonomy (`@api` / `@internal`)

> AID-413 (T-F of the consumer↔package boundary work, AID-407 spec §3). Every classlike under `src/` carries exactly one class-level tag, enforced in CI by `tests/Unit/Contract/SurfaceTaxonomyTest.php`. This document records the criteria so the classification stays conscious — it is the package-level input for the umbrella STD (T-A).

## The two tags

- **`@api`** — supported public surface. Consumers may depend on it; changes follow semver (added surface → at least minor; removed surface or changed signatures/semantics → major, with an UPGRADE guide in the dist). The contract snapshots (`tests/Contract/snapshots/`, AID-412) additionally freeze the full surface of the seven contract models.
- **`@internal`** — implementation detail. May change in any release without notice. Consumers depending on it do so at their own risk.

`@deprecated` composes with either tag: a deprecated public class keeps its `@api` until the announced removal major (precedent: `BillingService` — deprecated in v4.1.0/AID-390, kept `@api` through v5.x, removed in v6.0.0/AID-423). Per STABILITY.md, a deprecation lives through at least one full major before removal.

## Guiding principle

**When in doubt → `@internal`.** Promoting `@internal` to `@api` later is a harmless minor; demoting `@api` is a breaking change. Real consumer usage (surveyed across the `clientes` app, 150 references, 2026-07-11) decided the borderline cases.

## Classification by directory

| Directory | Tag | Rationale (boundary-spec band) |
|---|---|---|
| `Models/` (21) | `@api` | Yellow band: columns/casts/relations/scopes are read directly by consumers. The amber fiscal operations additionally carry method-level `@api` (7 methods, guarded by the contract snapshots) |
| `Enums/` (17) | `@api` | Green band: consumed directly everywhere |
| `Concerns/` (3) | `@api` | Green band; `HasUuid` and `HasUserRelationships` are applied to the CONSUMER's own User model |
| `ValueObjects/` (1) | `@api` | `InvoiceNumber` is the return type of the public numbering API |
| `Contracts/` (3) | `@api` | Extension points for consumers and sibling packages (PDF connectors, fiscal verification, tax strategies) |
| `Exceptions/` (6) | `@api` | Thrown across the boundary; consumers catch them |
| `Events/` (7) | `@api` | Consumers listen to them |
| `Actions/` (4) | `@api` | `VerifyVatNumber` (lararoi bridge) + the three `Process*` scheduler entry points |
| `DataTransferObjects/` (6) | `@api` | Appear (directly or nested) in `@api` service signatures |
| `Console/` (1) | `@api` | `larabill:install` is the documented install path |
| `Facades/` + `Larabill` | `@api` | Trivial (`version()`/`description()`) but public |
| `Services/` | mixed | See below |
| `Support/` | mixed | `MigrationHelper` `@api` (STD-001 setup API, used in real consumer migrations); `RegionalContext` `@internal` (no consumption; promotable) |
| `Listeners/`, `Notifications/`, provider | `@internal` | Package wiring |
| `Database/Factories/` (19) | `@internal` | Package test infra; promotable if a consumer asks |
| `Database/Seeders/` | mixed | `TaxRatesSeeder` `@api` (README instructs running it); the rest `@internal` |

### Services split

- **`@api` (13):** `InvoiceService`, `InvoiceNumberingService`, `InvoiceSeriesResolver` (AID-307 — the single source of the fiscal series), `InvoiceVerifactuService`, `TaxCalculationService`, `FiscalChangeDetector` (real consumer usage), `RecurringBillingService`, `ServiceLifecycleService`, `GroupedPaymentService`, `PricingService`, `EuSalesThresholdService`, `DestinationVatService`, `CommissionCalculationService` (consumer-orchestrated domain). (`BillingService`, formerly `@api` + `@deprecated`, was removed in v6.0.0/AID-423.)
- **`@internal` (9):** `FiscalIntegrityChecker` (creating-hook mechanism; its public exception is the contract), `ModelMappingService` (the config keys are public, not the resolver), `CacheService`, `PDFService`/`DomPDFService`/`DefaultPDFConnector` (public surface is `Invoice::generatePDF()` + `PDFConnectorInterface`), `VerifactuAdapter`, `FakeFiscalVerification` (test double; promotable), `VatCalculationStrategy` (default implementation — the contract is the API).

## Notes / candidates recorded here (not acted on in AID-413)

- `TaxRateSeeder` vs `TaxRatesSeeder` coexist (small legacy example set vs the comprehensive, README-documented one). `TaxRateSeeder` is `@internal` and a deprecation candidate.
- `config/larabill.php` references `Models\Customer` / `Models\CustomerFiscalData`, which do not exist in `src/Models/` — tracked as F-bug2 (AID-415, consumer repo).
