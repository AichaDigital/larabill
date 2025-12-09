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
        Schema::create('article_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('billing_frequency')
                ->comment('BillingFrequency enum: 0=ONE_TIME, 1=WEEKLY, 2=BIWEEKLY, 3=MONTHLY, 4=BIMONTHLY, 5=QUARTERLY, 6=SEMIANNUAL, 7=YEARLY, 8=BIENNIAL, 9=TRIENNIAL');

            $table->integer('price')
                ->comment('Price in Base100 format (€12.34 = 1234)');

            $table->unsignedTinyInteger('billing_days_in_advance')
                ->nullable()
                ->comment('Days in advance to generate invoice for this frequency');

            $table->date('valid_from')
                ->nullable()
                ->comment('Price valid from this date');

            $table->date('valid_to')
                ->nullable()
                ->comment('Price valid until this date');

            $table->boolean('is_active')
                ->default(true)
                ->comment('If false, price is disabled');

            $table->timestamps();

            // Unique constraint: one active price per article+frequency at any time
            $table->unique(
                ['article_id', 'billing_frequency', 'valid_from'],
                'article_prices_unique'
            );

            // Index for common queries
            $table->index(['article_id', 'is_active'], 'article_prices_active');
            $table->index(['billing_frequency'], 'article_prices_frequency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_prices');
    }
};
