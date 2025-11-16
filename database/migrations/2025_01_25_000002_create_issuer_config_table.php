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
        Schema::create('issuer_config', function (Blueprint $table) {
            $table->id()->comment('Singleton: Siempre ID=1');
            $table->unsignedBigInteger('current_tax_profile_id')->nullable()->comment('FK a issuer_tax_profiles - Perfil fiscal activo del emisor');

            // ROI & OSS Configuration (from old CompanyConfig)
            $table->boolean('is_roi_registered')->default(false)->comment('Si el emisor está registrado en ROI/VIES');
            $table->boolean('is_oss_registered')->default(false)->comment('Si el emisor usa One-Stop Shop EU');

            // EU Sales Thresholds
            $table->integer('eu_sales_threshold')->default(1000000)->comment('Base-100: Umbral de ventas EU (€10,000 = 1000000)');
            $table->integer('current_eu_sales')->default(0)->comment('Base-100: Ventas EU acumuladas año fiscal actual');
            $table->year('fiscal_year')->comment('Año fiscal actual para control de umbrales');
            $table->date('fiscal_year_start')->comment('Fecha de inicio del año fiscal');
            $table->date('fiscal_year_end')->comment('Fecha de fin del año fiscal');
            $table->boolean('threshold_exceeded')->default(false)->comment('Si se ha superado el umbral EU');
            $table->timestamp('threshold_exceeded_at')->nullable()->comment('Cuándo se superó el umbral');
            $table->boolean('threshold_notification_sent')->default(false)->comment('Si se notificó al admin');

            // Fiscal Settings
            $table->json('fiscal_settings')->nullable()->comment('Configuración fiscal adicional (tasas especiales, exenciones, etc.)');
            $table->json('metadata')->nullable()->comment('Metadatos adicionales de configuración');

            $table->timestamps();

            // Ensure singleton: Only one record allowed
            $table->unique('id');
        });

        // Insert the singleton record
        DB::table('issuer_config')->insert([
            'id'                => 1,
            'fiscal_year'       => now()->year,
            'fiscal_year_start' => now()->startOfYear()->toDateString(),
            'fiscal_year_end'   => now()->endOfYear()->toDateString(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issuer_config');
    }
};
