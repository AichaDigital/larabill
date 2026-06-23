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
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();

            // Commission Level (priority: product > product_group > global)
            $table->unsignedTinyInteger('level')
                ->comment('Nivel de comisión: 0=global, 1=product_group, 2=product (CommissionLevel enum)');

            // Reference (depends on level)
            $table->unsignedBigInteger('article_id')->nullable()->comment('FK a articles - Para level=product');
            $table->string('product_group')->nullable()->comment('Nombre del grupo de productos - Para level=product_group');

            // Commission Configuration
            $table->unsignedTinyInteger('type')
                ->default(0)
                ->comment('Tipo de comisión: 0=percentage, 1=fixed (CommissionType enum)');

            $table->integer('rate')->comment('Base-100 minor units: 2050 = 20.50% o €20.50 fijo (FixedDecimalCast:2)');

            $table->unsignedTinyInteger('applies_to')
                ->default(0)
                ->comment('Sobre qué se calcula: 0=taxable_amount, 1=total_amount (CommissionAppliesTo enum)');

            // Validity Period
            $table->date('valid_from')->comment('Fecha desde la cual es válida esta comisión');
            $table->date('valid_until')->nullable()->comment('Fecha hasta la cual es válida (null = indefinida)');
            $table->boolean('is_active')->default(true)->comment('Si la comisión está activa');

            // Additional Conditions
            $table->integer('min_amount')->nullable()->comment('Base-100 minor units: monto mínimo para aplicar comisión (FixedDecimalCast:2)');
            $table->integer('max_amount')->nullable()->comment('Base-100 minor units: monto máximo para comisión (FixedDecimalCast:2)');
            $table->unsignedInteger('min_quantity')->nullable()->comment('Cantidad mínima de artículos');

            // Description
            $table->string('name')->comment('Nombre descriptivo de la comisión');
            $table->text('description')->nullable()->comment('Descripción detallada');
            $table->json('metadata')->nullable()->comment('Condiciones adicionales, reglas especiales, etc.');

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('level');
            $table->index('article_id');
            $table->index('product_group');
            $table->index('is_active');
            $table->index(['valid_from', 'valid_until']);
            $table->index(['level', 'is_active']);

            // Foreign keys
            $table->foreign('article_id')
                ->references('id')
                ->on('articles')
                ->cascadeOnDelete();

            // Constraints
            $table->unique(['level', 'article_id', 'valid_from'], 'unique_product_commission');
            $table->unique(['level', 'product_group', 'valid_from'], 'unique_group_commission');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
