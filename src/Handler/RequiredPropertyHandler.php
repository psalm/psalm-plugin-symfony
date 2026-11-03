<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Trait_;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Storage\PropertyStorage;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;

/**
 * Symfony injects properties marked with #[Required] after instantiation
 * (see AutowireRequiredPropertiesPass), so they are initialized from the container's point of view.
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

        foreach ($storage->properties as $name => $property) {
            if (self::isInjectedBySymfony($property)) {
                $storage->initialized_properties[$name] = true;
            }
        }
    }

    /**
     * Mirrors the properties that AutowireRequiredPropertiesPass wires. Symfony throws a container compilation
     * error for non-public(set) properties and silently skips untyped, union and intersection typed ones, so
     * neither can be considered initialized.
     */
    private static function isInjectedBySymfony(PropertyStorage $property): bool
    {
        if ($property->is_static
            || $property->readonly
            || $property->is_promoted
            || ClassLikeAnalyzer::VISIBILITY_PUBLIC !== $property->visibility
            || ClassLikeAnalyzer::VISIBILITY_PUBLIC !== $property->set_visibility
        ) {
            return false;
        }

        if (!self::hasRequiredAttribute($property)) {
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
