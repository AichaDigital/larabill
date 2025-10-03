<?php

declare(strict_types=1);

use AichaDigital\Larabill\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The TestCase is the "bootstrap" for your package's tests. You can add
| custom functionality to it that will be available in all your tests.
|
*/

uses(TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain
| conditions. Pest provides a wide array of fluent expectations to make
| that easy. You can add your own expectations here.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
