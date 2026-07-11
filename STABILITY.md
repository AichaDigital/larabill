# Stability Contract

**Effective from v6.0.0.** larabill is a closed, stable product. This document is the binding policy for how it evolves; it ships in the dist so every consumer can hold the package to it.

## The promise

1. **No breaking change without a qualified usage imperative.** A breaking change (SemVer major) only enters when a real, documented consumer case makes the current behaviour untenable — a fiscal/legal requirement, a data-integrity defect, or a demonstrated production blocker. "Cleaner API", naming preferences and speculative flexibility do **not** qualify. The qualifying case is recorded in the CHANGELOG entry and the tracking issue.

2. **Every release that ships migrations — major OR minor — follows the same versioned upgrade ritual.** A consumer reaches any newer version with, at most:

   ```bash
   composer update aichadigital/larabill
   php artisan larabill:install   # idempotent: publishes only NEW migrations, never overwrites config
   php artisan migrate
   ```

   This is the ONE upgrade mechanism of the package; no release may require a different one. Concretely, every schema- or data-touching release ships, in the same PR (policy AID-398):

   - a migration that handles **existing data** (backfill/transform, or provably data-safe DDL such as widening a column), never fresh-install-only;
   - an upgrade-path test that seeds the previous release's state, runs the migration and asserts invariants (`tests/Integration/UpgradePath/`);
   - a CHANGELOG entry that states **"Ships migrations: yes"** with the explicit upgrade steps for that release;
   - for majors additionally: `UPGRADE-X.0.md` in the dist root and a **BREAKING** CHANGELOG entry.

   Minor releases may ship schema changes only when they are **additive or provably data-safe** (new tables/columns/indexes, widened columns); anything that can lose data, reject existing rows or change persisted semantics is a major. `migrate:fresh` is never part of an upgrade path. Skipping majors is not supported: upgrade sequentially (5.x → 6.0 → 7.0); minors within a line may be skipped (the installer publishes every pending migration by name).

3. **Deprecation before removal, one full major apart.** Public surface (`@api`) is never removed in the release that deprecates it: it is marked `@deprecated` with its replacement in major `N`, and removed no earlier than major `N+1`. Deprecated surface keeps working until removed.

4. **The public surface is explicit and machine-guarded.** Every class is tagged `@api` (supported, covered by this contract) or `@internal` (may change in any release) — see `docs/api-surface.md` in the repository. The guarantees are enforced in CI, not by good intentions:

   - golden-master snapshots of the seven contract models (columns, casts, relations, scopes, method signatures) fail CI on any drift (`tests/Contract/snapshots/`);
   - shipped migrations are immutable — renaming, editing or deleting one fails CI (`release-migration-manifest.json`);
   - `bin/tag-release` refuses to tag if the contract preflight fails.

5. **What does not break.** Within a major line (`N.x`), consumers get only additive changes (new columns, methods, config keys — MINOR) and fixes (PATCH). Bug fixes that change observable behaviour are documented in the CHANGELOG under **Fixed** with the old and new behaviour stated.

## What this means in practice

- A defect discovered in production is fixed in a **patch or minor** unless a breaking fix is demonstrably the only correct option — in which case rule 1 applies and the justification is public.
- Feature requests that require breaking the contract wait until a qualifying imperative exists, then travel together in a single major (no drip-feed of breaking releases).
- As of v6.0.0 the deprecated backlog is **empty** and there is **no known future breaking change**.

## Scope

This contract covers the `@api` surface, the database schema of the package's tables, and the semantics of persisted data. It does not cover `@internal` classes, the test suite, or repository-only tooling (`bin/`, `docs/`, CI).
