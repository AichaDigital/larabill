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
        Schema::table('vat_verifications', function (Blueprint $table) {
            $table->timestamp('checked_at')->nullable()->after('response_data');
            $table->timestamp('expires_at')->nullable()->after('checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vat_verifications', function (Blueprint $table) {
            $table->dropColumn(['checked_at', 'expires_at']);
        });
    }
};
