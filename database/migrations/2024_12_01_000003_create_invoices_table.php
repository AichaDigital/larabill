<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            // UUID primary key - UUID v7 string storage (char 36)
            // See ADR-002: UUID v7 consolidation for rationale
            $table->uuid('id')->primary()->comment('UUID v7 primary key');

            // Fiscal numbering (CEE compliance)
            $table->string('fiscal_number')->unique()->comment('Complete fiscal number for display: FAC-2025-000047');
            $table->string('prefix', 10)->default('FAC')->comment('User customizable prefix (max 10 chars): FAC, PRO, RECT, etc.');
            $table->unsignedTinyInteger('serie')->default(1)->comment('InvoiceSerieType enum: 0=proforma, 1=invoice, 2=rectificative');
            $table->unsignedBigInteger('series_number')->comment('Pure correlative number: 1, 2, 3... for fiscal validation & accounting APIs');
            $table->year('fiscal_year')->comment('Fiscal year (calculated from config fiscal_year_start)');

            // Dates (legal + technical)
            $table->date('invoice_date')->comment('Legal invoice date (appears on PDF). For proforma→invoice: conversion date');
            $table->timestamp('issued_at')->useCurrent()->comment('Technical immutable timestamp for chronological validation (issued_at_N > issued_at_N-1)');
            $table->date('service_date')->nullable()->comment('Service/delivery date if different from invoice_date (required by some countries)');
            $table->date('due_date')->nullable()->comment('Payment due date');
            $table->timestamp('paid_at')->nullable()->comment('Actual payment timestamp');

            // Status
            $table->unsignedTinyInteger('status')->default(0)->comment('InvoiceStatus enum: 0=draft, 1=sent, 2=paid, 3=overdue, 4=cancelled');

            // Relations (ADR-001 + ADR-003: CompanyFiscalConfig + UserTaxProfile architecture)
            MigrationHelper::userIdColumn($table);
            $table->uuid('billable_user_id')->nullable()->comment('FK to users - User being billed (replaces customer_id per ADR-003)');
            $table->unsignedBigInteger('company_fiscal_config_id')->nullable()->comment('FK to company_fiscal_configs - Issuer snapshot');
            $table->unsignedBigInteger('user_tax_profile_id')->nullable()->comment('FK to user_tax_profiles - User fiscal snapshot (ADR-003)');
            $table->foreignUuid('proforma_id')->nullable()->constrained('invoices')->nullOnDelete()->comment('UUID FK if this invoice was converted from a proforma');
            $table->foreignUuid('rectifies_invoice_id')->nullable()->constrained('invoices')->nullOnDelete()->comment('UUID FK if this is a rectificative invoice, references original');

            // Encrypted snapshots (immutable at invoice creation time)
            $table->text('issuer_snapshot')->nullable()->comment('Encrypted snapshot of issuer (company) fiscal data');
            $table->text('customer_snapshot')->nullable()->comment('Encrypted snapshot of customer fiscal data');
            $table->text('fiscal_snapshot')->nullable()->comment('Encrypted snapshot of fiscal context and tax rules applied');
            $table->boolean('is_roi_taxed')->default(false)->comment('If true, applies reverse charge mechanism (EU B2B)');

            // Amounts (Base-100 integer storage)
            $table->integer('taxable_amount')->default(0)->comment('Base-100: €12.34 = 1234. Taxable amount before tax');
            $table->integer('total_tax_amount')->default(0)->comment('Base-100: Sum of all tax amounts from items');
            $table->integer('total_amount')->default(0)->comment('Base-100: taxable_amount + total_tax_amount');

            // Immutability
            $table->boolean('is_immutable')->default(false)->comment('If true, invoice cannot be modified (fiscal protection)');
            $table->timestamp('immutable_at')->nullable()->comment('Timestamp when invoice was made immutable');

            // Additional fields
            $table->text('notes')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('template_name')->nullable();
            $table->timestamps();

            // Foreign keys are added in a later migration (2025_01_26_000001_add_invoices_foreign_keys)
            // to avoid circular dependency issues with customers, company_fiscal_configs, customer_fiscal_data

            // Optimized indexes
            $table->unique(['serie', 'series_number', 'fiscal_year']);
            $table->index(['user_id', 'serie', 'status']);
            $table->index(['invoice_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
