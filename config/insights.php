<?php

declare(strict_types=1);

return [
    'preset' => 'laravel',

    'exclude' => [
        'tests',
        'vendor',
        'build',
        'node_modules',
        'public',
        'storage',
        'resources',
    ],

    'add' => [],

    'remove' => [],

    'config' => [],

    'requirements' => [
        'min-quality'      => 80,
        'min-complexity'   => 80,
        'min-architecture' => 80,
        'min-style'        => 80,
    ],
];
