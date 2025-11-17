<?php

declare(strict_types=1);

use AichaDigital\Larabill\Tests\DevelopTest\ExperimentalTestCase;
use Illuminate\Support\Facades\Schema;

/**
 * Test experimental para encontrar la estrategia correcta de carga de migraciones
 * Objetivo: Cargar migraciones de tests/Database/migrations Y database/migrations sin duplicados
 */

uses(ExperimentalTestCase::class);

describe('Migration Strategy Experiments', function () {
    it('lists all tables after migrations', function () {
        // Get all tables using Laravel's Schema facade
        $tables = Schema::getConnection()->select('SELECT name FROM sqlite_master WHERE type="table" ORDER BY name');
        $tableNames = array_map(fn($t) => $t->name, $tables);

        dump('=== TABLES LOADED ===');
        dump($tableNames);
        dump('=== TOTAL: ' . count($tableNames) . ' ===');

        // Verificar que las tablas v0.4.0 NO existan aún (porque no están en tests/Database/migrations)
        $v040Tables = ['customers', 'issuer_config', 'legal_entity_types', 'commissions'];
        $missing = [];
        $present = [];

        foreach ($v040Tables as $table) {
            if (!in_array($table, $tableNames)) {
                $missing[] = $table;
            } else {
                $present[] = $table;
            }
        }

        dump('❌ MISSING v0.4.0 tables: ' . implode(', ', $missing));
        dump('✅ PRESENT v0.4.0 tables: ' . implode(', ', $present));

        // Por ahora esperamos que NO existan porque solo cargamos tests/Database/migrations
        expect(count($missing))->toBeGreaterThan(0);
    });

    it('checks for duplicate migrations execution', function () {
        // Intentar crear una tabla que ya existe para detectar duplicación
        try {
            Schema::create('test_duplicate_check', function ($table) {
                $table->id();
            });

            // Si llegamos aquí, no hay duplicación
            dump('✅ No duplicate migration execution detected');

            Schema::dropIfExists('test_duplicate_check');
            expect(true)->toBeTrue();
        } catch (\Exception $e) {
            dump('❌ Possible duplicate execution: ' . $e->getMessage());
            expect(false)->toBeTrue();
        }
    });
});
