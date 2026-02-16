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
        Schema::create('user_tax_infos', function (Blueprint $table) {
            $table->id();
            
            // Agnostic user_id - auto-detects User model ID type
            MigrationHelper::userIdColumn($table);
            $table->boolean('is_current')->default(false);
            $table->string('tax_id');
            $table->string('company_name');
            $table->text('address');
            $table->string('city');
            $table->string('postal_code');
            $table->string('country', 2);
            $table->string('state')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();

            // Indexes (user_id index added automatically by MigrationHelper)
            $table->index(['is_current']);
            $table->index(['user_id', 'is_current']);
            $table->unique(['user_id', 'is_current']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_tax_infos');
    }
};
