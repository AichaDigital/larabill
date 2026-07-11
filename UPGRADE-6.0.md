# Upgrading larabill 5.x → 6.0

## TL;DR

larabill **6.0 is a pure code-hygiene major** (AID-423): it removes API surface that has been `@deprecated` for several releases and has a direct 1:1 replacement. **It ships zero migrations — no schema change, no data change.** If your code no longer calls the deprecated surface listed below, upgrading is nothing more than `composer update`.

This is also the **closing major**: with it, the deprecated backlog is empty and the roadmap carries **no known breaking change**. From 6.0 on, the package is governed by the stability contract in **STABILITY.md** — breaking changes only enter with a qualified, documented usage imperative, and every future major must be auto-upgradeable from the previous one.

**Are you affected?**

- **No** — if you already emit through `InvoiceService` and address tax profiles by `owner_user_id` / `owner()` (the non-deprecated surface since v4.1.0 and ADR-004 respectively).
- **Yes** — if you still call `BillingService` or any `user_id`-named alias on `UserTaxProfile` / `HasUserRelationships`. The replacements below are drop-in.

## Migration steps

### 1. Update the dependency

```bash
composer update aichadigital/larabill
```

No `larabill:install` re-run and no `php artisan migrate` are needed: 6.0 publishes no new migrations.

### 2. Replace `BillingService` with `InvoiceService`

`BillingService` (deprecated since v4.1.0, AID-390) is removed. `InvoiceService` is the supported emission path — it adds the fiscal snapshots and ADR-003 `billable_user_id` handling that `BillingService` never had, and derives its numbering from the same `InvoiceNumberingService`.

| Removed | Replacement |
|---------|-------------|
| `BillingService::createInvoice($data, $options)` | `InvoiceService::createInvoice($data, $options)` |
| `BillingService::createProforma($data, $options)` | `InvoiceService::createProforma($data, $options)` |
| `BillingService::convertToInvoice($proforma, $options)` | `InvoiceService::convertProformaToInvoice($proforma, $options)` |

Note that `InvoiceService::createInvoice()` requires an active `CompanyFiscalConfig` (it snapshots the issuer's fiscal data and refuses to emit without it) and resolves the customer's `UserTaxProfile` — behaviour `BillingService` silently skipped. See the README quick-start for the full payload shape.

### 3. Replace the `user_id` aliases on `UserTaxProfile`

All removed methods delegate 1:1 to their replacement — a mechanical rename:

| Removed | Replacement |
|---------|-------------|
| `$profile->user_id` (accessor/mutator) | `$profile->owner_user_id` |
| `$profile->user()` relation | `$profile->owner()` |
| `UserTaxProfile::getActiveForUser($id)` | `UserTaxProfile::getActiveForOwner($id)` |
| `UserTaxProfile::getValidForUserAt($id, $date)` | `UserTaxProfile::getValidForOwnerAt($id, $date)` |
| `UserTaxProfile::createForUser($id, $attrs)` | `UserTaxProfile::createForOwner($id, $attrs)` |
| `UserTaxProfile::forUser($id)` scope | `UserTaxProfile::forOwner($id)` scope |

The `user_tax_profiles` table is untouched — the column has been `owner_user_id` since ADR-004; only the code aliases are gone.

### 4. Replace the aliases on `HasUserRelationships` (your User model trait)

| Removed | Replacement |
|---------|-------------|
| `$user->taxProfiles()` | `$user->ownedTaxProfiles()` |
| `$user->activeTaxProfile()` | `$user->currentTaxProfile()` relation |
| `User::withActiveTaxProfile()` scope | `User::withCurrentTaxProfile()` scope |

### 5. Test factories (dev only)

| Removed | Replacement |
|---------|-------------|
| `UserTaxProfileFactory::forUser($id)` | `UserTaxProfileFactory::forOwner($id)` |

### 6. Find every call site in one sweep

```bash
grep -rnE 'BillingService|createForUser|getActiveForUser|getValidForUserAt|->forUser\(|->user_id|->user\(\)|taxProfiles\(|activeTaxProfile|withActiveTaxProfile' app/ tests/
```

Review each hit against the tables above. `->user_id` and `->user()` are only affected on `UserTaxProfile` instances — on `Invoice` and other models they remain real, supported columns/relations.

## Not in this release

Nothing is deferred. This major empties the deprecated backlog; there is no known future breaking change. Future evolution is governed by **STABILITY.md**.
