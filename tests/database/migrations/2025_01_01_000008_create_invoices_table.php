<?php

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
            $table->id();

            // Invoice identification
            $table->string('number')->unique();
            $table->string('type');
            $table->string('status');
            $table->unsignedBigInteger('user_id');

            // User tax information (encrypted)
            $table->text('user_tax_info_encrypted')->nullable();

            // Immutability
            $table->boolean('is_immutable')->default(false);
            $table->timestamp('immutable_at')->nullable();

            // Monetary amounts (using base 100 format: €12.34 = 1234)
            $table->integer('subtotal'); // Base 100: €12.34 = 1234
            $table->integer('tax_amount'); // Base 100: €12.34 = 1234
            $table->integer('total'); // Base 100: €12.34 = 1234

            // Additional data
            $table->json('fiscal_data')->nullable();
            $table->json('vat_verification')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('number');
            $table->index('status');
            $table->index('type');
            $table->index('is_immutable');
            $table->index('due_date');
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
