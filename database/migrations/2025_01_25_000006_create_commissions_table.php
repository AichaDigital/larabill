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
            $table->enum('level', ['global', 'product_group', 'product'])
                ->comment('Nivel de comisión: global (todas las ventas), product_group (grupo), product (artículo específico)');

            // Reference (depends on level)
            $table->unsignedBigInteger('article_id')->nullable()->comment('FK a articles - Para level=product');
            $table->string('product_group')->nullable()->comment('Nombre del grupo de productos - Para level=product_group');

            // Commission Configuration
            $table->enum('type', ['percentage', 'fixed'])
                ->default('percentage')
                ->comment('Tipo de comisión: porcentaje o monto fijo');

            $table->decimal('rate', 8, 4)->comment('Tasa de comisión (20.5000 = 20.5% o €20.50 fijo)');
            $table->integer('rate_base100')->nullable()->comment('Base-100 para cálculos (2050 = 20.50)');

            $table->enum('applies_to', ['taxable_amount', 'total_amount'])
                ->default('taxable_amount')
                ->comment('Sobre qué se calcula: base imponible o total con impuestos');

            // Validity Period
            $table->date('valid_from')->comment('Fecha desde la cual es válida esta comisión');
            $table->date('valid_until')->nullable()->comment('Fecha hasta la cual es válida (null = indefinida)');
            $table->boolean('is_active')->default(true)->comment('Si la comisión está activa');

            // Additional Conditions
            $table->integer('min_amount_base100')->nullable()->comment('Base-100: Monto mínimo para aplicar comisión');
            $table->integer('max_amount_base100')->nullable()->comment('Base-100: Monto máximo para comisión');
            $table->unsignedInteger('min_quantity')->nullable()->comment('Cantidad mínima de artículos');

            // Description
            $table->string('name')->comment('Nombre descriptivo de la comisión');
            $table->text('description')->nullable()->comment('Descripción detallada');
            $table->json('metadata')->nullable()->comment('Condiciones adicionales, reglas especiales, etc.');

            $table->timestamps();

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
