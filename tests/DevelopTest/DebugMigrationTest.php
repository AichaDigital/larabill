<?php

declare(strict_types=1);

use AichaDigital\Larabill\Tests\DevelopTest\Strategy4TestCase;
use Illuminate\Support\Facades\DB;

uses(Strategy4TestCase::class);

it('debugs migration execution count', function () {
    // Check migrations table to see how many times customers migration ran
    $migrations = DB::table('migrations')->where('migration', 'like', '%customers%')->get();
    
    dump('=== MIGRATIONS TABLE ===');
    dump($migrations->toArray());
    
    // Check if customers table exists
    $tables = DB::select('SELECT name FROM sqlite_master WHERE type="table" AND name="customers"');
    dump('=== CUSTOMERS TABLE EXISTS: ' . (count($tables) > 0 ? 'YES' : 'NO') . ' ===');
    
    // Try to describe customers table
    if (count($tables) > 0) {
        $indexes = DB::select('SELECT * FROM sqlite_master WHERE type="index" AND tbl_name="customers"');
        dump('=== CUSTOMERS INDEXES ===');
        dump(array_map(fn($i) => $i->name, $indexes));
    }
    
    expect(true)->toBeTrue();
});
