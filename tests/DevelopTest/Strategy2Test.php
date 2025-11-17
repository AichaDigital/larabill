<?php

declare(strict_types=1);

use AichaDigital\Larabill\Tests\DevelopTest\Strategy2TestCase;
use Illuminate\Support\Facades\Schema;

uses(Strategy2TestCase::class);

describe('Strategy 2: Load BOTH directories', function () {
    it('loads all tables including v0.4.0', function () {
        $tables = Schema::getConnection()->select('SELECT name FROM sqlite_master WHERE type="table" ORDER BY name');
        $tableNames = array_map(fn($t) => $t->name, $tables);
        
        dump('=== STRATEGY 2: TABLES LOADED ===');
        dump($tableNames);
        dump('=== TOTAL: ' . count($tableNames) . ' ===');
        
        // Verificar que las tablas v0.4.0 SÍ existan
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
        
        // Esperamos que TODAS existan
        expect(count($present))->toBe(4);
        expect(count($missing))->toBe(0);
    });
    
    it('can create an article (test existing functionality)', function () {
        $article = \AichaDigital\Larabill\Models\Article::factory()->create([
            'code' => 'STRAT2-001',
            'name' => 'Strategy 2 Test Article',
        ]);
        
        expect($article->code)->toBe('STRAT2-001');
        expect($article->exists)->toBeTrue();
    });
});
