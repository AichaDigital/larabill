<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grouped_payments', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('UUID v7 primary key');

            // Payer (user being billed). char(36) + index, no hard FK. (No extra index() — userIdColumn already adds one.)
            MigrationHelper::userIdColumn($table, 'billable_user_id');

            $table->bigInteger('amount')->comment('Base-100 (FixedDecimalCast:2): €12.34 = 1234. Equals sum of settled invoice totals. bigInteger: aggregate of N invoices can exceed a 32-bit int (€21.4M cap)');
            $table->string('currency', 3)->default('EUR')->comment('ISO 4217 — validated against each invoice companyFiscalConfig currency (D3)');
            $table->dateTime('paid_at')->comment('Date the external collection happened');
            $table->string('reference')->nullable()->comment('Bank/accounting reference — metadata, not identity');
            $table->string('idempotency_key')->unique()->comment('Provided or derived; unique guard against duplicate collections');
            $table->unsignedTinyInteger('status')->default(0)->comment('GroupedPaymentStatus: 0=posted, 1=reversed');
            $table->dateTime('reversed_at')->nullable();

            // Reversal actor — char(36) + index, no hard FK (may be a system actor).
            MigrationHelper::userIdColumn($table, 'reversed_by', nullable: true);

            $table->string('reverse_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grouped_payments');
    }
};
