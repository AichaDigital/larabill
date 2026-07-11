<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Support\Contract;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Serializes the public contract surface of a larabill model to canonical JSON
 * for the golden-master contract snapshot test (AID-412, boundary spec §4.2).
 *
 * Determinism rules — the output MUST be byte-identical across the CI matrix
 * (PHP 8.3/8.4 × Laravel 12/13), so:
 *
 * - Casts are the DECLARED ones ($casts property default + casts() method),
 *   never getCasts(), which injects framework defaults (primary-key cast) that
 *   vary between Laravel versions.
 * - Methods are filtered by declaring FILE under src/ — package traits count
 *   as surface (HasUserRelation::user()), vendor traits never do (spatie
 *   HasTranslations resolves differently per matrix leg).
 * - Interfaces are diffed against the parent class chain (only package-level
 *   additions like LegallyRetainable survive).
 * - Reflection types are stringified by hand (never (string) casts, whose
 *   format changed across PHP versions).
 * - Every list and map is sorted alphabetically; only method parameters keep
 *   declaration order (positional = contract).
 * - Related models resolved from consumer config (larabill.user_model /
 *   ModelMappingService) are normalized to "<configured>" so the snapshot
 *   captures the contract, not the test fixture.
 */
final class ModelContractSerializer
{
    private const CONFIGURED_TOKEN = '<configured>';

    private const PACKAGE_MODEL_NAMESPACE = 'AichaDigital\\Larabill\\Models\\';

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<string, mixed>
     */
    public function serialize(string $modelClass): array
    {
        $reflection = new ReflectionClass($modelClass);
        $instance   = new $modelClass;

        [$relations, $scopes, $methods] = $this->methodSurface($reflection, $instance);

        return [
            'class'      => $modelClass,
            'table'      => $instance->getTable(),
            'primaryKey' => [
                'incrementing' => $instance->getIncrementing(),
                'name'         => $instance->getKeyName(),
                'type'         => $instance->getKeyType(),
            ],
            'interfaces'        => $this->packageInterfaces($reflection),
            'traits'            => $this->sortedList(array_values(class_uses($modelClass) ?: [])),
            'constants'         => $this->packageConstants($reflection),
            'publicProperties'  => $this->packagePublicProperties($reflection),
            'defaultAttributes' => $this->defaultAttributes($reflection),
            'fillable'          => $this->sortedList($instance->getFillable()),
            'hidden'            => $this->sortedList($instance->getHidden()),
            'visible'           => $this->sortedList($instance->getVisible()),
            'appends'           => $this->sortedList($instance->getAppends()),
            'with'              => $this->sortedList($this->defaultPropertyValue($reflection, 'with')),
            'casts'             => $this->declaredCasts($reflection, $instance),
            'relations'         => $relations,
            'scopes'            => $scopes,
            'methods'           => $methods,
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function toJson(string $modelClass): string
    {
        $json = json_encode(
            $this->serialize($modelClass),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return $json."\n";
    }

    /**
     * Interfaces added by the package on top of the Eloquent base class.
     *
     * @param  ReflectionClass<Model>  $reflection
     * @return list<string>
     */
    private function packageInterfaces(ReflectionClass $reflection): array
    {
        $parent           = $reflection->getParentClass();
        $parentInterfaces = $parent !== false ? $parent->getInterfaceNames() : [];

        return $this->sortedList(array_values(array_diff($reflection->getInterfaceNames(), $parentInterfaces)));
    }

    /**
     * Public constants declared in package files (e.g. InvoiceSeriesControl::GLOBAL_SCOPE).
     *
     * @param  ReflectionClass<Model>  $reflection
     * @return array<string, mixed>
     */
    private function packageConstants(ReflectionClass $reflection): array
    {
        $constants = [];

        foreach ($reflection->getReflectionConstants() as $constant) {
            if (! $constant->isPublic()) {
                continue;
            }

            if (! $this->isPackageFile($constant->getDeclaringClass()->getFileName())) {
                continue;
            }

            $constants[$constant->getName()] = $constant->getValue();
        }

        ksort($constants);

        return $constants;
    }

    /**
     * Public non-static properties declared in package files (e.g. Article::$translatable).
     *
     * @param  ReflectionClass<Model>  $reflection
     * @return array<string, mixed>
     */
    private function packagePublicProperties(ReflectionClass $reflection): array
    {
        $properties = [];
        $defaults   = $reflection->getDefaultProperties();

        foreach ($reflection->getProperties() as $property) {
            if (! $property->isPublic() || $property->isStatic()) {
                continue;
            }

            if (! $this->isPackageFile($property->getDeclaringClass()->getFileName())) {
                continue;
            }

            $default                          = $defaults[$property->getName()] ?? null;
            $properties[$property->getName()] = is_array($default) ? $this->sortedList($default) : $default;
        }

        ksort($properties);

        return $properties;
    }

    /**
     * Declared default attributes (e.g. InvoiceSeriesControl user_id sentinel).
     *
     * @param  ReflectionClass<Model>  $reflection
     * @return array<string, mixed>
     */
    private function defaultAttributes(ReflectionClass $reflection): array
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $reflection->getDefaultProperties()['attributes'] ?? [];

        ksort($attributes);

        return $attributes;
    }

    /**
     * Casts declared BY the package: $casts property default merged with the
     * casts() method result — never getCasts() (framework-version dependent).
     *
     * @param  ReflectionClass<Model>  $reflection
     * @return array<string, string>
     */
    private function declaredCasts(ReflectionClass $reflection, Model $instance): array
    {
        /** @var array<string, string> $casts */
        $casts = $reflection->getDefaultProperties()['casts'] ?? [];

        if ($reflection->hasMethod('casts')) {
            $method = $reflection->getMethod('casts');

            if ($this->isPackageFile($method->getFileName())) {
                /** @var array<string, string> $methodCasts */
                $methodCasts = $method->invoke($instance);
                $casts       = array_merge($casts, $methodCasts);
            }
        }

        ksort($casts);

        return $casts;
    }

    /**
     * Splits the package-declared public methods into relations, scopes and
     * behaviour methods, each keyed by name and sorted.
     *
     * @param  ReflectionClass<Model>  $reflection
     * @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>}
     */
    private function methodSurface(ReflectionClass $reflection, Model $instance): array
    {
        $relations = [];
        $scopes    = [];
        $methods   = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $this->isPackageFile($method->getFileName())) {
                continue;
            }

            $name = $method->getName();

            if (str_starts_with($name, '__')
                || str_starts_with($name, 'boot')
                || str_starts_with($name, 'initialize')
                || $name === 'newFactory') {
                continue;
            }

            if (str_starts_with($name, 'scope')) {
                $scopes[$name] = $this->describeMethod($method);

                continue;
            }

            if ($this->isRelationMethod($method)) {
                $relations[$name] = $this->describeRelation($method, $instance);

                continue;
            }

            $methods[$name] = $this->describeMethod($method);
        }

        ksort($relations);
        ksort($scopes);
        ksort($methods);

        return [$relations, $scopes, $methods];
    }

    private function isRelationMethod(ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();

        if (! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return false;
        }

        return is_a($returnType->getName(), Relation::class, true);
    }

    /**
     * Instantiates the relation (no queries are executed) to capture its shape.
     *
     * @return array<string, mixed>
     */
    private function describeRelation(ReflectionMethod $method, Model $instance): array
    {
        /** @var Relation<Model, Model, mixed> $relation */
        $relation = $method->invoke($instance);

        $details = [
            'type'    => class_basename($relation),
            'related' => $this->normalizeRelatedClass($relation->getRelated()::class),
        ];

        if ($relation instanceof BelongsTo) {
            $details['foreignKey'] = $relation->getForeignKeyName();
            $details['ownerKey']   = $relation->getOwnerKeyName();
        } elseif ($relation instanceof HasOneOrMany) {
            $details['foreignKey'] = $relation->getForeignKeyName();
            $details['localKey']   = $relation->getLocalKeyName();
        } elseif ($relation instanceof BelongsToMany) {
            $details['pivotTable']      = $relation->getTable();
            $details['foreignPivotKey'] = $relation->getForeignPivotKeyName();
            $details['relatedPivotKey'] = $relation->getRelatedPivotKeyName();
        }

        $details['withTrashed'] = in_array(SoftDeletingScope::class, $relation->getQuery()->removedScopes(), true);

        ksort($details);

        return $details;
    }

    /**
     * Related classes resolved from consumer config are not package contract:
     * normalize them so the snapshot never captures a test fixture class.
     */
    private function normalizeRelatedClass(string $relatedClass): string
    {
        return str_starts_with($relatedClass, self::PACKAGE_MODEL_NAMESPACE)
            ? $relatedClass
            : self::CONFIGURED_TOKEN;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeMethod(ReflectionMethod $method): array
    {
        $docComment = $method->getDocComment() ?: '';

        return [
            'api'        => (bool) preg_match('/@api\b/', $docComment),
            'deprecated' => (bool) preg_match('/@deprecated\b/', $docComment),
            'parameters' => array_map(
                fn (ReflectionParameter $parameter): array => $this->describeParameter($parameter),
                $method->getParameters(),
            ),
            'returnType' => $this->typeToString($method->getReturnType()),
            'static'     => $method->isStatic(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeParameter(ReflectionParameter $parameter): array
    {
        $described = [
            'name'     => $parameter->getName(),
            'type'     => $this->typeToString($parameter->getType()),
            'variadic' => $parameter->isVariadic(),
        ];

        if ($parameter->isDefaultValueAvailable()) {
            $described['default'] = $parameter->isDefaultValueConstant()
                ? $parameter->getDefaultValueConstantName()
                : var_export($parameter->getDefaultValue(), true);
        }

        return $described;
    }

    /**
     * Hand-rolled type stringification: `(string) ReflectionType` output has
     * changed across PHP versions, so it can never feed a golden master.
     */
    private function typeToString(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            if ($type->allowsNull() && $name !== 'null' && $name !== 'mixed') {
                return '?'.$name;
            }

            return $name;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(
                static fn (ReflectionType $inner): string => $inner instanceof ReflectionNamedType
                    ? $inner->getName()
                    : '('.self::intersectionToString($inner).')',
                $type->getTypes(),
            ));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return self::intersectionToString($type);
        }

        return null;
    }

    private static function intersectionToString(ReflectionType $type): string
    {
        if (! $type instanceof ReflectionIntersectionType) {
            return '';
        }

        return implode('&', array_map(
            static fn (ReflectionType $inner): string => $inner instanceof ReflectionNamedType ? $inner->getName() : '',
            $type->getTypes(),
        ));
    }

    private function isPackageFile(string|false $file): bool
    {
        static $srcPath = null;

        if ($srcPath === null) {
            $srcPath = realpath(dirname(__DIR__, 3).'/src');
        }

        if ($file === false || $srcPath === false) {
            return false;
        }

        $realFile = realpath($file);

        return $realFile !== false && str_starts_with($realFile, $srcPath.DIRECTORY_SEPARATOR);
    }

    /**
     * @param  ReflectionClass<Model>  $reflection
     * @return list<mixed>
     */
    private function defaultPropertyValue(ReflectionClass $reflection, string $property): array
    {
        /** @var list<mixed> $value */
        $value = $reflection->getDefaultProperties()[$property] ?? [];

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return list<mixed>
     */
    private function sortedList(array $values): array
    {
        sort($values);

        return array_values($values);
    }
}
