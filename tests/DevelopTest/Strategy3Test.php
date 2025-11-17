<?php

declare(strict_types=1);

use AichaDigital\Larabill\Tests\DevelopTest\Strategy3TestCase;
use Illuminate\Support\Facades\Schema;

uses(Strategy3TestCase::class);

describe('Strategy 3: Load from SINGLE directory', function () {
    it('loads all tables including v0.4.0', function () {
        $tables = Schema::getConnection()->select('SELECT name FROM sqlite_master WHERE type="table" ORDER BY name');
        $tableNames = array_map(fn($t) => $t->name, $tables);
        
        dump('=== STRATEGY 3: TABLES LOADED ===');
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
            'code' => 'STRAT3-001',
            'name' => 'Strategy 3 Test Article',
        ]);
        
        expect($article->code)->toBe('STRAT3-001');
        expect($article->exists)->toBeTrue();
    });
    
    it('can create a customer (test v0.4.0 functionality)', function () {
        $customer = \AichaDigital\Larabill\Models\Customer::factory()->create([
            'display_name' => 'Strategy 3 Test Customer',
        ]);
        
        expect($customer->display_name)->toBe('Strategy 3 Test Customer');
        expect($customer->exists)->toBeTrue();
    });
});
