# Operational Notes

Date started: 2026-05-09
Audience: maintainers and agents working on Larabill
Scope: operational decisions, flaky-test conditions, repair notes, and verification evidence that do not justify a full ADR.

Use this file when a change needs a short trail for future humans:

- A test failure was flaky only under specific suite conditions.
- A repair fixed test data, CI behavior, release process, or package operations.
- The decision has preconditions or tradeoffs that are easy to forget.
- The lesson should be visible without reading old CI logs or agent memory.

Each entry should include:

- **Status:** Proposed, Applied, Superseded, or Reverted.
- **Trigger:** What surfaced the issue.
- **Preconditions:** Conditions required to reproduce or understand the issue.
- **Root cause:** The smallest true cause found.
- **Decision:** What changed and why.
- **Verification:** Commands or CI runs that prove the current state.
- **Residual risk:** What remains true after the repair.

## 2026-05-09 - ArticleServiceStatus factory duplicate instance identifiers

**Status:** Applied

**Trigger:** GitHub Actions run `25591839371` failed in the coverage step for job `P8.3 - L13.* - prefer-stable - ubuntu-latest`.

Failure signature:

- Test file: `tests/Unit/Actions/ServiceLifecycleActionsTest.php`
- Failing area: pending cancellation setup around the multi-row `ArticleServiceStatus::factory()->count(3)->create(...)`
- Exception: `Illuminate\Database\UniqueConstraintViolationException`
- Duplicate key shape: `(customer_id, article_id, instance_identifier)`
- Observed duplicate: `customer_id=4`, `article_id=1`, `instance_identifier=schuppe.com`
- Random order seed: `1778301633`

**Preconditions:**

- The affected tests create several `ArticleServiceStatus` rows with the same `customer_id` and `article_id`.
- The `article_service_status` table has a unique index on `(customer_id, article_id, instance_identifier)`.
- The factory generated `instance_identifier` with `$this->faker->domainName()`.
- Faker does not guarantee uniqueness unless the `unique()` modifier is used.
- The isolated file passed locally with the CI seed, so the failure depended on suite-wide Faker state, coverage execution, or random order.

**Root cause:** The factory generated random non-unique values for a column that participates in a unique index. Tests that create multiple service statuses for the same customer/article pair could collide.

**Decision:** Make the factory default unique:

```php
'instance_identifier' => $this->faker->unique()->domainName(),
```

This fixes the data source used by every `ArticleServiceStatus::factory()` call instead of adding explicit identifiers to one failing test. The repair is intentionally stricter than the database constraint: the factory now avoids duplicate domains globally for the Faker generator, while the database only requires uniqueness inside the customer/article pair.

**Verification:**

```bash
vendor/bin/pest tests/Unit/Actions/ServiceLifecycleActionsTest.php --order=random --random-order-seed=1778301633
vendor/bin/pest tests/Unit/Services/ServiceLifecycleServiceTest.php --order=random --random-order-seed=1778301633
vendor/bin/pest tests/Unit/Models/ArticleServiceStatusTest.php --order=random --random-order-seed=1778301633
vendor/bin/pest --ci --order=random --random-order-seed=1778301633
env XDEBUG_MODE=coverage vendor/bin/pest --coverage --coverage-clover=/private/tmp/larabill-coverage.xml --order=random --random-order-seed=1778301633
```

Verified results:

- Focused action tests: 4 passed.
- Focused service lifecycle tests: 10 passed.
- Focused `ArticleServiceStatus` model tests: 39 passed.
- Full non-coverage suite: 959 passed, 2765 assertions.
- Full coverage suite with Xdebug: 959 passed, 2765 assertions, total coverage 57.4%.

**Residual risk:**

- The factory is now stricter than production data rules. If a future test intentionally needs two customers or articles with the same `instance_identifier`, set the value explicitly in that test.
- Faker's unique pool can be exhausted if a test creates an unusually large number of service statuses in one process. That is unlikely for the current suite, but explicit sequences are better for large bulk tests.

**Rule for future tests:** When a test creates multiple `ArticleServiceStatus` rows for the same `customer_id` and `article_id`, ensure `instance_identifier` is unique. Relying on plain Faker randomness is not enough.
