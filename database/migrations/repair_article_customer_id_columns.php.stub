<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->repairCustomerIdColumn('article_overrides');
        $this->repairCustomerIdColumn('article_service_status');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->restoreIntegerCustomerIdColumn('article_service_status');
        $this->restoreIntegerCustomerIdColumn('article_overrides');
    }

    private function repairCustomerIdColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'customer_id')) {
            return;
        }

        $idType = MigrationHelper::getUserIdType();
        if ($idType === 'int' || ! $this->isIntegerColumn($tableName)) {
            return;
        }

        $this->assertExistingValuesMatchTargetType($tableName, $idType);
        $this->changeCustomerIdColumn($tableName, $idType);
    }

    private function restoreIntegerCustomerIdColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'customer_id')) {
            return;
        }

        if ($this->isIntegerColumn($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->bigInteger('customer_id', unsigned: true)->change();
        });
    }

    private function changeCustomerIdColumn(string $tableName, string $idType): void
    {
        if (DB::connection()->getDriverName() === 'pgsql' && $idType === 'uuid') {
            $grammar = DB::connection()->getQueryGrammar();
            $table   = $grammar->wrapTable($tableName);
            $column  = $grammar->wrap('customer_id');

            DB::statement("alter table {$table} alter column {$column} type uuid using {$column}::text::uuid");

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($idType): void {
            match ($idType) {
                'uuid'  => $table->uuid('customer_id')->change(),
                'ulid'  => $table->ulid('customer_id')->change(),
                default => null,
            };
        });
    }

    private function isIntegerColumn(string $tableName): bool
    {
        return in_array(Schema::getColumnType($tableName, 'customer_id'), ['integer', 'bigint'], true);
    }

    private function assertExistingValuesMatchTargetType(string $tableName, string $idType): void
    {
        foreach (DB::table($tableName)->whereNotNull('customer_id')->select('customer_id')->cursor() as $row) {
            $value   = (string) $row->customer_id;
            $isValid = match ($idType) {
                'uuid'  => Uuid::isValid($value),
                'ulid'  => preg_match('/^[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{26}$/i', $value) === 1,
                default => true,
            };

            if (! $isValid) {
                throw new RuntimeException(
                    "Cannot convert {$tableName}.customer_id to {$idType}: existing value [{$value}] is not a valid {$idType}."
                );
            }
        }
    }
};
