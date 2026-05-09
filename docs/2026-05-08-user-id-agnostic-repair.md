# User ID Agnostic Repair Plan

Date: 2026-05-08
Status: Proposed
Scope: Larabill package only

## Context

Larabill is intended to be agnostic to the application's user primary key type. The package exposes `larabill.user_id_type` and `AichaDigital\Larabill\Support\MigrationHelper` to support integer, UUID string, and ULID user identifiers.

A real consumer project surfaced a violation of that contract: `article_service_status.customer_id` is declared as `unsignedBigInteger`, while the consumer's users use UUID identifiers. This blocks dashboard integration against `ArticleServiceStatus`.

The issue is not a new architecture decision. The existing rule is correct: user/customer-facing identifiers must be created through the package's agnostic migration helper. The repair is to enforce that rule consistently.

## Broken Invariant

Any package column that references a consumer user/customer identity must be compatible with `larabill.user_id_type`.

Current violations found during read-only review:

- `database/migrations/2025_01_20_000003_create_article_service_status_table.php`
- `database/migrations/create_article_service_status_table.php.stub`
- `database/migrations/2025_01_20_000002_create_article_overrides_table.php`
- `database/migrations/create_article_overrides_table.php.stub`

In those files, `customer_id` is documented as agnostic but implemented as `unsignedBigInteger`.

## Goals

1. Make `article_service_status.customer_id` compatible with integer, UUID string, and ULID user IDs.
2. Make `article_overrides.customer_id` compatible with integer, UUID string, and ULID user IDs.
3. Remove primitive `int` type assumptions from public/internal APIs that accept customer/user identifiers.
4. Add regression tests that fail if UUID string customer IDs stop working.
5. Keep the fix inside larabill until package tests pass, then let consumer projects update the package normally.

## Non-Goals

- Do not rename `customer_id` in this repair.
- Do not replace the service lifecycle model.
- Do not extract `larabill-services`.
- Do not refactor billing, pricing, or invoice generation beyond the ID-type repair.
- Do not change consumer projects as part of this larabill session.

## Operational Decision: Published vs Unpublished Migrations

Before editing migrations, verify whether the affected tables have been published or migrated in any real consumer environment.

If the affected migrations have not been published/executed in real consumers:

- Edit the package migrations and stubs in place.
- Replace hardcoded `unsignedBigInteger('customer_id')` with an agnostic helper call.

If the affected migrations have already been published/executed in real consumers:

- Add a forward migration that changes the column type safely for supported database engines.
- Keep published-install compatibility explicit.
- Document any manual migration risk for existing data and foreign/index constraints.

This plan does not pre-decide which path applies. That verification belongs to the implementation session.

## Likely Code Changes

Migration-level changes:

- `article_service_status.customer_id` should be created via an agnostic helper.
- `article_overrides.customer_id` should be created via an agnostic helper.
- The PHP migrations and their `.stub` counterparts must stay aligned.

Type-level changes:

- `ArticleServiceStatus::scopeForCustomer()` should accept `int|string` customer IDs.
- `ArticleOverride::scopeForCustomer()` should accept `int|string` customer IDs.
- `Article::getEffectivePriceFor()`, `Article::getActiveOverrideFor()`, and `Article::hasActiveOverrideFor()` should accept `int|string` customer IDs where applicable.
- `PricingService` methods that accept customer IDs should accept `int|string` customer IDs.
- Factory helpers such as `forCustomer()` should accept `int|string` customer IDs.

Test-level changes:

- Add or adjust tests so UUID string customer IDs are exercised against the real affected models.
- Existing tests using integer IDs are not enough because SQLite can hide type mismatches that fail in consumer databases.

## Required Tests

At minimum, add regression coverage for:

1. Creating `ArticleServiceStatus` with a UUID string `customer_id`.
2. Querying `ArticleServiceStatus::forCustomer($uuid)`.
3. Creating `ArticleOverride` with a UUID string `customer_id`.
4. Querying `ArticleOverride::forCustomer($uuid)`.
5. Looking up pricing overrides through `Article` / `PricingService` using a UUID string customer ID.

If feasible, run the same behavioral tests with `larabill.user_id_type` set to `int`, `uuid`, and `ulid`. If that is too large for the immediate repair, prioritize UUID because it blocks the current consumer integration.

## Acceptance Criteria

- No hardcoded `unsignedBigInteger('customer_id')` remains for user/customer-facing IDs in the affected migrations or stubs.
- No method that receives customer/user IDs rejects UUID strings through an `int` type hint.
- Package tests pass for the changed areas.
- A UUID-based consumer can create and query `ArticleServiceStatus` rows using its real user IDs.
- Consumer dashboard work remains blocked until this package repair is released or linked locally.

## Suggested Session Prompt

Fix larabill user/customer ID agnosticism.

Larabill already claims support for integer, UUID string, and ULID user IDs via `MigrationHelper`, but `article_service_status.customer_id` and `article_overrides.customer_id` currently hardcode `unsignedBigInteger`. This violates the package invariant and blocks UUID consumers.

Work only in larabill. Keep the change surgical. Do not rename `customer_id`, do not extract packages, and do not touch consumer projects.

First verify whether the affected migrations are published/executed anywhere real. If not published, edit migrations/stubs in place. If published, add a compatible forward migration. Then update the affected model/service/factory signatures from `int` to `int|string` where customer IDs can be UUID strings. Add tests proving UUID customer IDs work for service status, overrides, and pricing lookup.
