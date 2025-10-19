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
        Schema::create('article_overrides', function (Blueprint $table) {
            $table->id();

            // Relaciones
            $table->unsignedBigInteger('customer_id')->comment('Customer/User ID (agnostic)');
            $table->foreignId('article_id')
                ->constrained('articles')
                ->cascadeOnDelete()
                ->comment('FK to articles table');

            // Override de precio
            $table->integer('custom_price')->comment('Custom price in Base100 format');
            $table->text('reason')->nullable()->comment('Reason for price override: "Premium customer", "Annual contract", etc.');

            // Vigencia
            $table->date('valid_from')->nullable()->comment('Override valid from this date');
            $table->date('valid_to')->nullable()->comment('Override valid until this date (null = indefinite)');

            // Estado
            $table->boolean('is_active')->default(true)->comment('If false, override is disabled');

            $table->timestamps();

            // Índices
            $table->unique(['customer_id', 'article_id', 'valid_from'], 'customer_article_override_unique');
            $table->index(['customer_id', 'is_active']);
            $table->index(['article_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_overrides');
    }
};

