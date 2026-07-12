<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

/**
 * AID-450 — remove the phantom «Plantilla Simple» proforma template.
 *
 * InvoiceTemplatesSeeder used to seed an ACTIVE proforma template whose
 * template_path (larabill::pdf.invoice.proforma-simple) never resolved to a
 * blade anywhere in the package history, so selecting it crashed the render
 * with «View not found». The seeder entry is gone; this migration cleans the
 * row already seeded in existing databases.
 *
 * The deletion is guarded: a consumer who published an override blade at
 * that path made the row functional, so it is kept.
 */
return new class extends Migration
{
    private const PHANTOM_VIEW = 'larabill::pdf.invoice.proforma-simple';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (View::exists(self::PHANTOM_VIEW)) {
            return;
        }

        DB::table('invoice_templates')
            ->where('template_path', self::PHANTOM_VIEW)
            ->delete();
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally a no-op: this is a one-way data cleanup. Restoring a row
     * that points at a view that does not exist would only reintroduce the
     * latent render crash.
     */
    public function down(): void
    {
        //
    }
};
