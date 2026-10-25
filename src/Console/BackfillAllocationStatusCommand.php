<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Console;

use AichaDigital\Larabill\Support\AllocationStatusBackfill;
use Illuminate\Console\Command;

/**
 * Re-run the AID-962 allocation-status backfill (larabill 8.3.0).
 *
 * The deploy window: between `php artisan migrate` and the switch to the 8.3
 * code, a reversal made by 8.2 code (card path) sets `active_invoice_id =
 * NULL` but leaves the pivot row `status = 0` (ACTIVE) under a REVERSED
 * payment — the invoice reads as still paid. Run this command once after the
 * code switch; it applies the migration's two idempotent UPDATEs again and
 * prints how many rows each one fixed. Verification afterwards:
 * `SELECT COUNT(*) FROM grouped_payment_invoice WHERE status = 0 AND active_invoice_id IS NULL`
 * must return 0.
 *
 * Exit code is always 0: fixing rows is the command's job, not a failure.
 *
 * @api Supported public surface (AID-962; see docs/api-surface.md).
 */
class BackfillAllocationStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'larabill:backfill-allocation-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-run the AID-962 backfill: pivot rows left ACTIVE with active_invoice_id = NULL (8.2 reversals in the deploy window) become REVERSED';

    public function handle(): int
    {
        $counts = AllocationStatusBackfill::run();

        $this->info(sprintf(
            'Allocation status backfill: %d row(s) under a REVERSED payment, %d residual row(s) under a POSTED payment (reverse_reason = backfill-8.3).',
            $counts['from_reversed_parent'],
            $counts['residual'],
        ));

        return self::SUCCESS;
    }
}
