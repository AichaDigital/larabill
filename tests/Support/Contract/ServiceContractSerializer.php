<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Support\Contract;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Serializes the public contract surface of a larabill SERVICE to canonical
 * JSON for the golden-master service-signature snapshot (ADR-016 §12, round 1
 * finding V5 — the service-signature surface is an explicit deliverable of
 * PR-2; the existing machinery only snapshotted the contract MODELS).
 *
 * Determinism rules mirror ModelContractSerializer (AID-1616 lessons apply):
 *
 * - Only public methods DECLARED in package files are surface; inherited
 *  framework methods never are.
 * - Reflection types are stringified by hand (never (string) casts) and
 *   `self`/`parent`/`static` resolve to the declaring class name, so the
 *   snapshot is identical across the CI matrix (PHP 8.3/8.4).
 * - Method parameters keep declaration order (positional = contract);
 *   everything else is sorted.
 * - Default parameter values are recorded when scalar (null/int/string/float/
 *   bool); class-constant defaults record the constant name.
 */
final class ServiceContractSerializer
{
    /**
     * @param  class-string  $serviceClass
     * @return array<string, mixed>
     */
    public function serialize(string $serviceClass): array
    {
        $reflection = new ReflectionClass($serviceClass);

        return [
            'class'         => $serviceClass,
            'interfaces'    => $this->packageInterfaces($reflection),
            'publicMethods' => $this->methodSurface($reflection),
        ];
    }

    /**
     * @param  class-string  $serviceClass
     */
    public function toJson(string $serviceClass): string
    {
        $json = json_encode(
            $this->serialize($serviceClass),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return $json."\n";
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return list<string>
     */
    private function packageInterfaces(ReflectionClass $reflection): array
    {
        $parent           = $reflection->getParentClass();
        $parentInterfaces = $parent !== false ? $parent->getInterfaceNames() : [];

        $interfaces = array_values(array_diff($reflection->getInterfaceNames(), $parentInterfaces));
        sort($interfaces);

        return $interfaces;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return array<string, array<string, mixed>>
     */
    private function methodSurface(ReflectionClass $reflection): array
    {
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue; // inherited — not this service's contract
            }

            $methods[$method->getName()] = [
                'returnType' => $this->typeToString($method->getReturnType(), $reflection),
                'static'     => $method->isStatic(),
                'parameters' => array_map(
                    fn (ReflectionParameter $parameter): array => $this->parameterToString($parameter, $reflection),
                    $method->getParameters(),
                ),
            ];
        }

        ksort($methods);

        return $methods;
    }

    /**
     * @param  ReflectionClass<object>  $declaring
     * @return array{name: string, type: ?string, default: mixed}
     */
    private function parameterToString(ReflectionParameter $parameter, ReflectionClass $declaring): array
    {
        return [
            'name'    => '$'.$parameter->getName(),
            'type'    => $this->typeToString($parameter->getType(), $declaring),
            'default' => $parameter->isDefaultValueAvailable()
                ? $this->defaultValueToString($parameter->getDefaultValue())
                : null,
        ];
    }

    private function defaultValueToString(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value) && $value === []) {
            return '[]';
        }

        return '['.get_class($value).']';
    }

    private function typeToString(?ReflectionType $type, ?ReflectionClass $declaring = null): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            if ($declaring !== null && in_array($name, ['self', 'static', 'parent'], true)) {
                $name = match ($name) {
                    'parent' => $declaring->getParentClass() !== false ? $declaring->getParentClass()->getName() : $declaring->getName(),
                    default  => $declaring->getName(),
                };
            }

            if ($type->allowsNull() && $name !== 'null' && $name !== 'mixed') {
                return '?'.$name;
            }

            return $name;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(
                fn (ReflectionType $inner): string => $this->typeToString($inner, $declaring),
                $type->getTypes(),
            ));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(
                fn (ReflectionType $inner): string => $this->typeToString($inner, $declaring),
                $type->getTypes(),
            ));
        }

        return (string) $type;
    }
}
