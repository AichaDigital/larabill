<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PHPUnit\Runner\Version;

return new class extends Migration
{
    /**
     * ADR-016 §8.4 (PR-1 of AID-1627) — read-only preflight for the invoice
     * numbering lifecycle migration program.
     *
     * The legacy tree has exactly one issuance-like state shape: EVERY
     * numbered fiscal row was born with `issued_at = now()` at creation
     * (ADR-016 §1.1). There is no data signal separating "issued" from
     * "numbered draft", so the backfill classifies every numbered legacy row
     * as ISSUED (§8.3) and relies on THIS report for operator visibility.
     *
     * Loud-abort conditions (fail the migration chain before any DDL):
     *
     * - Any unnumbered fiscal row (§8.3 case 3): a mutable unnumbered row has
     *   no legacy producer and an immutable one would contradict §1.1's
     *   evidence — the backfill cannot classify it.
     * - Any row whose `serie` is outside the InvoiceSerieType domain 0..3
     *   (round 6, V11): no §8.3 rule heals it, and the named CHECK would make
     *   the tree permanently uncompliant.
     *
     * Output is written through plain echo so the migration runner surfaces
     * it during `php artisan migrate`.
     */
    public function up(): void
    {
        $fiscalRows = DB::table('invoices')->where('serie', '!=', 0);

        // §8.4: numbered fiscal rows, partitioned by their legacy collection
        // status — the populations the operator must SEE before the backfill
        // stamps the transitional ISSUED over them.
        $numberedCancelled = (clone $fiscalRows)
            ->whereNotNull('fiscal_number')->where('status', 4)->count();
        $numberedDraft = (clone $fiscalRows)
            ->whereNotNull('fiscal_number')->where('status', 0)->count();
        $numberedPending = (clone $fiscalRows)
            ->whereNotNull('fiscal_number')->where('status', 5)->count();

        $this->report(sprintf(
            'larabill[ADR-016 preflight]: %d numbered fiscal row(s) with legacy status CANCELLED — will be classified ISSUED (the number WAS consumed); collection status kept',
            $numberedCancelled,
        ));
        $this->report(sprintf(
            'larabill[ADR-016 preflight]: %d numbered fiscal row(s) with legacy status DRAFT — will be classified ISSUED (numbered drafts; the AID-1628 population)',
            $numberedDraft,
        ));
        $this->report(sprintf(
            'larabill[ADR-016 preflight]: %d numbered fiscal row(s) with legacy status PENDING — will be classified ISSUED; collection status remapped to DRAFT (round 2, B5)',
            $numberedPending,
        ));

        // §8.3 case 3 / §8.4: unnumbered fiscal rows MUST NOT exist.
        $unnumbered = (clone $fiscalRows)
            ->where(function ($query): void {
                $query->whereNull('fiscal_number')
                    ->orWhereNull('series_number')
                    ->orWhereNull('fiscal_year')
                    ->orWhereNull('invoice_date')
                    ->orWhereNull('issued_at');
            })
            ->count();

        if ($unnumbered > 0) {
            throw new RuntimeException(sprintf(
                'larabill[ADR-016 preflight]: %d unnumbered fiscal row(s) found — the backfill cannot classify them (§8.3 case 3). '
               .'A mutable unnumbered row has no legacy producer; investigate these rows before upgrading.',
                $unnumbered,
            ));
        }

        // V11 (round 6): the backfill cannot heal a row whose serie is outside
        // the InvoiceSerieType domain — fail before any DDL, never after.
        $outOfDomainSerie = DB::table('invoices')
            ->whereNotIn('serie', [0, 1, 2, 3])
            ->count();

        if ($outOfDomainSerie > 0) {
            throw new RuntimeException(sprintf(
                'larabill[ADR-016 preflight]: %d invoice row(s) have a serie outside the InvoiceSerieType domain (0..3) — '
                .'the ADR-016 CHECK cannot be added over them and the backfill would not heal them (round 6, V11).',
                $outOfDomainSerie,
            ));
        }

        $this->report('larabill[ADR-016 preflight]: OK — no unnumbered fiscal rows, serie domain clean.');
    }

    /**
     * Read-only: nothing to roll back.
     */
    public function down(): void
    {
        //
    }

    private function report(string $message): void
    {
        // The report is for the OPERATOR running `php artisan migrate`. Under
        // PHPUnit (both the SQLite suite and the InstallMysql harness — which
        // forces environment=production, so the APP_ENV check alone would not
        // cover it) a bare echo becomes "unexpected output" and marks the
        // owning test risky. PHPUnit 10+ no longer defines
        // PHPUNIT_COMPOSER_INSTALL, so the detection is the loaded
        // PHPUnit\Runner\Version class (absent from a --no-dev consumer
        // upgrade, where the report must print).
        if (! class_exists(Version::class)) {
            echo $message.PHP_EOL;
        }
    }
};
