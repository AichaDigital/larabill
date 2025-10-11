<?php

declare(strict_types=1);

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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            $table->string('description');
            $table->integer('quantity')->default(100)->comment('Base-100 integer (e.g., 1.5 => 150, 1.0 => 100)');
            $table->integer('unit_price')->comment('Base-100 integer (e.g., €12.34 => 1234)');
            $table->integer('subtotal')->comment('Base-100 integer (e.g., €12.34 => 1234)');
            $table->integer('tax_rate')->default(0)->comment('Base-100 integer (e.g., 21.50% => 2150)');
            $table->integer('tax_amount')->default(0)->comment('Base-100 integer (e.g., €12.34 => 1234)');
            $table->integer('total')->comment('Base-100 integer (e.g., €12.34 => 1234)');
            $table->timestamps();

            // Indexes
            $table->index(['invoice_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
