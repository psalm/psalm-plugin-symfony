<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Trait_;
use Psalm\Aliases;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Storage\PropertyStorage;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;

/**
 * Symfony injects properties marked with #[Required] after instantiation
 * (see AutowireRequiredPropertiesPass), so they are initialized from the container's point of view.
 *
 * The pass only skips untyped, union and intersection typed properties before Symfony 8.1. Since 8.1 it also
 * rejects, with a container compilation error, properties that are not public(set). The handler follows the
 * strictest (8.1+) rules: a property Symfony may fail to write must keep its PropertyNotSetInConstructor issue.
 */
final class RequiredPropertyHandler implements AfterClassLikeVisitInterface
{
    private const REQUIRED_ATTRIBUTE = 'Symfony\Contracts\Service\Attribute\Required';

    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
    {
        $stmt = $event->getStmt();
        if (!$stmt instanceof Class_ && !$stmt instanceof Trait_) {
            return;
        }

        $storage = $event->getStorage();
        $readonlyNames = self::nativeReadonlyPropertyNames($stmt);
        $promotedRequiredNames = self::promotedRequiredPropertyNames($stmt, $event->getStatementsSource()->getAliases());

        foreach ($storage->properties as $name => $property) {
            if (isset($readonlyNames[$name])) {
                continue;
            }

            if (self::isInjectedBySymfony($property, isset($promotedRequiredNames[$name]))) {
                $storage->initialized_properties[$name] = true;
                // Psalm treats an inherited promoted property as uninitialized when the child declares its own
                // constructor, which hides the injection by Symfony.
                $property->is_promoted = false;
            }
        }
    }

    /**
     * Names of the properties that are natively readonly (property or class modifier) without an explicit
     * public(set). PHP makes these implicitly protected(set), so Symfony cannot write them.
     *
     * The syntax tree is used because PropertyStorage::$readonly also reflects the `@readonly` docblock tag,
     * which does not prevent Symfony from writing the property, and does not record the implicit set visibility.
     *
     * @return array<string, true>
     */
    private static function nativeReadonlyPropertyNames(ClassLike $stmt): array
    {
        $isReadonlyClass = $stmt instanceof Class_ && $stmt->isReadonly();
        $names = [];

        foreach ($stmt->getProperties() as $property) {
            if (($isReadonlyClass || $property->isReadonly()) && !$property->isPublicSet()) {
                foreach ($property->props as $item) {
                    $names[$item->name->toString()] = true;
                }
            }
        }

        foreach ($stmt->getMethods() as $method) {
            if (0 !== strcasecmp('__construct', $method->name->toString())) {
                continue;
            }

            foreach ($method->params as $param) {
                if (!$param->isPromoted() || !$param->var instanceof Variable || !\is_string($param->var->name)) {
                    continue;
                }

                if (($isReadonlyClass || $param->isReadonly()) && !$param->isPublicSet()) {
                    $names[$param->var->name] = true;
                }
            }
        }

        return $names;
    }

    /**
     * Psalm does not copy the attributes of a promoted constructor parameter to the property it declares.
     *
     * @return array<string, true>
     */
    private static function promotedRequiredPropertyNames(ClassLike $stmt, Aliases $aliases): array
    {
        $names = [];

        foreach ($stmt->getMethods() as $method) {
            if (0 !== strcasecmp('__construct', $method->name->toString())) {
                continue;
            }

            foreach ($method->params as $param) {
                if (!$param->isPromoted() || !$param->var instanceof Variable || !\is_string($param->var->name)) {
                    continue;
                }

                foreach ($param->attrGroups as $attrGroup) {
                    foreach ($attrGroup->attrs as $attribute) {
                        if (self::REQUIRED_ATTRIBUTE === self::resolveAttributeName($attribute->name, $aliases)) {
                            $names[$param->var->name] = true;
                        }
                    }
                }
            }
        }

        return $names;
    }

    private static function resolveAttributeName(Name $name, Aliases $aliases): string
    {
        if ($name->isFullyQualified()) {
            return $name->toString();
        }

        $first = $name->getFirst();
        $alias = $aliases->uses[strtolower($first)] ?? null;
        if (null !== $alias) {
            return implode('\\', [$alias, ...array_slice($name->getParts(), 1)]);
        }

        return ('' !== (string) $aliases->namespace ? $aliases->namespace.'\\' : '').$name->toString();
    }

    /**
     * Applies the visibility, type and attribute rules of AutowireRequiredPropertiesPass.
     */
    private static function isInjectedBySymfony(PropertyStorage $property, bool $promotedRequired): bool
    {
        if ($property->is_static
            || ClassLikeAnalyzer::VISIBILITY_PUBLIC !== $property->visibility
            || ClassLikeAnalyzer::VISIBILITY_PUBLIC !== $property->set_visibility
        ) {
            return false;
        }

        if (!$promotedRequired && !self::hasRequiredAttribute($property)) {
            return false;
        }

        return self::hasNamedNativeType($property);
    }

    private static function hasRequiredAttribute(PropertyStorage $property): bool
    {
        foreach ($property->attributes as $attribute) {
            if (self::REQUIRED_ATTRIBUTE === $attribute->fq_class_name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Equivalent of the "ReflectionNamedType" check: a single native type, optionally nullable.
     */
    private static function hasNamedNativeType(PropertyStorage $property): bool
    {
        if (null === $property->signature_type) {
            return false;
        }

        $types = [];
        foreach ($property->signature_type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull) {
                continue;
            }
            if ($atomic instanceof TNamedObject && [] !== $atomic->extra_types) {
                return false;
            }
            $types[] = $atomic;
        }

        return 1 === count($types);
    }
}
