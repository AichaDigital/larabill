<?php

declare(strict_types=1);

use AichaDigital\Larabill\Tests\DevelopTest\Strategy4TestCase;
use Illuminate\Support\Facades\Schema;

uses(Strategy4TestCase::class);

describe('Strategy 4: NO ServiceProvider', function () {
    it('loads all tables without duplication', function () {
        $tables = Schema::getConnection()->select('SELECT name FROM sqlite_master WHERE type="table" ORDER BY name');
        $tableNames = array_map(fn($t) => $t->name, $tables);
        
        dump('=== STRATEGY 4: TABLES LOADED (NO ServiceProvider) ===');
        dump($tableNames);
        dump('=== TOTAL: ' . count($tableNames) . ' ===');
        
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
        
        expect(count($present))->toBe(4);
        expect(count($missing))->toBe(0);
    });
    
    it('can create an article', function () {
        $article = \AichaDigital\Larabill\Models\Article::factory()->create([
            'code' => 'STRAT4-001',
        ]);
        
        expect($article->code)->toBe('STRAT4-001');
        expect($article->exists)->toBeTrue();
    });
    
    it('can create a customer', function () {
        $customer = \AichaDigital\Larabill\Models\Customer::factory()->create([
            'display_name' => 'Strategy 4 Customer',
        ]);
        
        expect($customer->display_name)->toBe('Strategy 4 Customer');
        expect($customer->exists)->toBeTrue();
    });
});
