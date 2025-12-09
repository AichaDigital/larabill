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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();

            // Identificación
            $table->string('code', 50)->unique()->comment('Unique article code: HOST-PRO, DOMAIN-COM, etc.');
            $table->string('name', 255)->comment('Article name for display');
            $table->text('description')->nullable()->comment('Detailed article description');

            // Clasificación
            $table->char('item_type', 1)->comment('G=Good (product), S=Service');
            $table->string('category', 100)->nullable()->comment('Article category for grouping');

            // Pricing moved to article_prices table
            $table->integer('cost_price')->nullable()->comment('Cost price for margin calculations (Base100)');

            // Integración externa
            $table->string('subscription_type', 100)->nullable()->comment('External system identifier: stripe:price_xxx, cpanel:account, etc.');

            // Relaciones
            $table->unsignedSmallInteger('tax_group_id')->nullable()->comment('FK to tax_groups table');
            $table->unsignedTinyInteger('unit_measure_id')->nullable()->comment('FK to unit_measures table');

            // Estado
            $table->boolean('is_active')->default(true)->comment('If false, article is disabled');

            // Metadata flexible
            $table->json('metadata')->nullable()->comment('Additional data: physical properties, service config, cancellation policy, etc.');

            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index(['code', 'is_active']);
            $table->index(['item_type']);
            $table->index(['category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
