<?php

declare(strict_types=1);

use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenDefineFunctions;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenGlobals;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenNormalClasses;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenTraits;
use NunoMaduro\PhpInsights\Domain\Sniffs\ForbiddenSetterSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\CodeAnalysis\EmptyStatementSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Commenting\TodoSniff;
use PHP_CodeSniffer\Standards\PSR12\Sniffs\Classes\ClassInstantiationSniff;
use PhpCsFixer\Fixer\Operator\NewWithBracesFixer;
use PhpCsFixer\Fixer\ReturnNotation\ReturnAssignmentFixer;
use SlevomatCodingStandard\Sniffs\Classes\ForbiddenPublicPropertySniff;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousExceptionNamingSniff;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousInterfaceNamingSniff;
use SlevomatCodingStandard\Sniffs\Commenting\InlineDocCommentDeclarationSniff;
use SlevomatCodingStandard\Sniffs\Commenting\UselessInheritDocCommentSniff;
use SlevomatCodingStandard\Sniffs\ControlStructures\AssignmentInConditionSniff;
use SlevomatCodingStandard\Sniffs\ControlStructures\DisallowEmptySniff;
use SlevomatCodingStandard\Sniffs\ControlStructures\DisallowShortTernaryOperatorSniff;
use SlevomatCodingStandard\Sniffs\Functions\UnusedParameterSniff;
use SlevomatCodingStandard\Sniffs\PHP\UselessParenthesesSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\DisallowArrayTypeHintSyntaxSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\DisallowMixedTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\ParameterTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\PropertyTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\ReturnTypeHintSniff;
use SlevomatCodingStandard\Sniffs\Variables\UselessVariableSniff;

return [
    'preset' => 'laravel',

    'ide' => 'vscode',

    'exclude' => [
        'vendor',
        'build',
        'dist',
        'storage',
        'tests/Pest.php',
        '.phpunit.result.cache',
    ],

    'add' => [],

    'remove' => [
        // Laravel uses public properties ($fillable, $casts, $guarded, etc.)
        ForbiddenPublicPropertySniff::class,

        // Setters are valid for configuration and DI
        ForbiddenSetterSniff::class,

        // empty() is useful and common in PHP
        DisallowEmptySniff::class,

        // mixed type is sometimes necessary for flexibility
        DisallowMixedTypeHintSniff::class,

        // Enum methods are not global helpers
        ForbiddenDefineFunctions::class,

        // Interface methods require unused parameters
        UnusedParameterSniff::class,

        // TODO comments are useful during development
        TodoSniff::class,

        // Elvis operator (?:) is valid PHP
        DisallowShortTernaryOperatorSniff::class,

        // Parentheses can improve readability
        UselessParenthesesSniff::class,

        // Return assignment can be more readable
        ReturnAssignmentFixer::class,

        // Article[] syntax is valid and readable
        DisallowArrayTypeHintSyntaxSniff::class,

        // Not all classes need to be final/abstract
        ForbiddenNormalClasses::class,

        // Traits are useful in Laravel (HasFactory, etc.)
        ForbiddenTraits::class,

        // Intermediate variables can improve readability
        UselessVariableSniff::class,

        // @inheritDoc is useful for documentation
        UselessInheritDocCommentSniff::class,

        // Assignment in condition is sometimes useful
        AssignmentInConditionSniff::class,

        // Empty catch/if can be intentional
        EmptyStatementSniff::class,

        // Type hint requirements are too strict for package development
        ParameterTypeHintSniff::class,
        PropertyTypeHintSniff::class,
        ReturnTypeHintSniff::class,

        // Enum cases with GLOBAL name are not actual globals
        ForbiddenGlobals::class,

        // Interface/Exception suffixes are Laravel convention
        SuperfluousInterfaceNamingSniff::class,
        SuperfluousExceptionNamingSniff::class,

        // Inline @var comments can be useful for IDE support
        InlineDocCommentDeclarationSniff::class,

        // Project prefers new Class without parentheses (pint config)
        ClassInstantiationSniff::class,
        NewWithBracesFixer::class,
    ],

    'config' => [
        \PHP_CodeSniffer\Standards\Generic\Sniffs\Files\LineLengthSniff::class => [
            'lineLimit'         => 120,
            'absoluteLineLimit' => 160,
            'ignoreComments'    => true,
        ],
        \SlevomatCodingStandard\Sniffs\Functions\FunctionLengthSniff::class => [
            'maxLinesLength' => 100,
        ],
        \NunoMaduro\PhpInsights\Domain\Insights\CyclomaticComplexityIsHigh::class => [
            'maxComplexity' => 15,
        ],
        \NunoMaduro\PhpInsights\Domain\Insights\MethodCyclomaticComplexityIsHigh::class => [
            'maxComplexity' => 12,
        ],
    ],

    'requirements' => [
        'min-quality'      => 75,
        'min-complexity'   => 75,
        'min-architecture' => 75,
        'min-style'        => 80,
    ],
];
