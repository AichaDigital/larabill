<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fiscal_settings');
    }

    public function down(): void
    {
        // No rollback - ADR-001 architectural change
    }
};

