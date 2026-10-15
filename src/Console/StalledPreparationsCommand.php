<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Console;

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use Illuminate\Console\Command;

/**
 * Stalled preparations report (ADR-016 §11.3, v7 Codex 58 adopted).
 *
 * The stalled CONVERTING+PREPARED state is NEW failure territory the
 * ADR-016 lifecycle creates: `convertAndIssue()` commits the conversion
 * (proforma CONVERTING, document PREPARED) and its issuance transaction can
 * then fail — leaving a legal, resumable aggregate that some caller must
 * resume (`issue()`) or release (`cancelPreparedInvoice()`). This command
 * lists those aggregates so they cannot rot silently; the deeper deadline
 * reporting is R4's (AID-1631).
 *
 * Read-only: it writes nothing. Exit code is always 0 — the presence of
 * stalled preparations is an operator-attention signal, not a failure of the
 * command.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class StalledPreparationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'larabill:stalled-preparations
                            {--json : Output the report as JSON instead of a table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List conversion-born invoices stuck at PREPARED (ADR-016 §11.3) — resumable by issue() or releasable by cancelPreparedInvoice()';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $stalled = Invoice::query()
            ->stalledPreparations()
            ->with('proforma:id,status,converted_at')
            ->orderBy('created_at')
            ->get();

        if ($this->option('json')) {
            $this->line(json_encode([
                'count'   => $stalled->count(),
                'items'   => $stalled->map(fn (Invoice $invoice): array => [
                    'invoice_id'         => (string) $invoice->getKey(),
                    'proforma_id'        => (string) $invoice->proforma_id,
                    'proforma_status'    => $invoice->proforma?->status->name,
                    'prefix'             => $invoice->prefix,
                    'serie'              => $invoice->serie?->name,
                    'prepared_since'     => $invoice->created_at?->toIso8601String(),
                    'age_in_days'        => $invoice->created_at?->diffInDays(now()),
                ])->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($stalled->isEmpty()) {
            $this->info('No stalled preparations: every conversion-born document has been issued or released.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d stalled preparation(s) — proforma CONVERTING + document PREPARED (ADR-016 §11.3):', $stalled->count()));

        $this->table(
            ['invoice_id', 'proforma_id', 'proforma_status', 'prefix', 'serie', 'prepared_since', 'age_days'],
            $stalled->map(fn (Invoice $invoice): array => [
                (string) $invoice->getKey(),
                (string) $invoice->proforma_id,
                $invoice->proforma?->status->name ?? InvoiceStatus::CONVERTING->name,
                $invoice->prefix,
                $invoice->serie?->name,
                $invoice->created_at?->toIso8601String(),
                (string) $invoice->created_at?->diffInDays(now()),
            ])->all(),
        );

        $this->line('Resume each with InvoiceService::issue($invoice) or release it with InvoiceService::cancelPreparedInvoice($invoice, $actor).');

        return self::SUCCESS;
    }
}
