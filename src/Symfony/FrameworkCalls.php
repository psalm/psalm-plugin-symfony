<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Symfony;

use Psalm\Codebase;
use Psalm\Context;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Records the calls that Symfony makes to project code dynamically, so that dead code detection sees them.
 *
 * The caller is a method of the framework, e.g. `Symfony\Component\HttpKernel\Kernel::handle`: code outside the
 * project is never unused, so whatever it calls is used.
 */
final class FrameworkCalls
{
    public const CONTAINER = 'Symfony\Component\DependencyInjection\Container::get';
    public const KERNEL = 'Symfony\Component\HttpKernel\Kernel::handle';
    public const EVENT_DISPATCHER = 'Symfony\Component\EventDispatcher\EventDispatcher::dispatch';
    public const MESSENGER = 'Symfony\Component\Messenger\Middleware\HandleMessageMiddleware::handle';
    public const VALIDATOR = 'Symfony\Component\Validator\Validator\RecursiveValidator::validate';
    public const DOCTRINE_LISTENERS = 'Doctrine\ORM\Event\ListenersInvoker::invoke';
    public const DOCTRINE_EVENT_MANAGER = 'Doctrine\Common\EventManager::dispatchEvent';
    public const TWIG = 'Twig\Environment::render';

    public static function callMethod(Codebase $codebase, string $class, string $method, string $caller): void
    {
        $codebase->methodExists($class.'::'.$method, $caller);
    }

    public static function useClass(Codebase $codebase, string $class, string $caller): void
    {
        $context = new Context();
        $context->calling_method_id = strtolower($caller);
        $codebase->addReferenceToClass(strtolower($class), null, $context);
    }

    public static function methodExists(Codebase $codebase, string $class, string $method): bool
    {
        return $codebase->classlike_storage_provider->has($class)
            && isset($codebase->classlike_storage_provider->get($class)->declaring_method_ids[strtolower($method)]);
    }

    /**
     * Records that $caller instantiates $class and the objects its properties hold, as the serializer does when it
     * denormalizes into $class.
     *
     * @param array<lowercase-string, true> $visited
     */
    public static function instantiateWithProperties(Codebase $codebase, string $class, string $caller, array &$visited = []): void
    {
        $class_lc = strtolower($class);
        if (isset($visited[$class_lc]) || !$codebase->classlike_storage_provider->has($class)) {
            return;
        }
        $visited[$class_lc] = true;

        $storage = $codebase->classlike_storage_provider->get($class);
        self::useClass($codebase, $storage->name, $caller);
        self::callMethod($codebase, $storage->name, '__construct', $caller);

        foreach ($storage->properties as $property) {
            $type = $property->type ?? $property->signature_type;
            if (null === $type) {
                continue;
            }
            foreach (self::getObjectClasses($type) as $nestedClass) {
                self::instantiateWithProperties($codebase, $nestedClass, $caller, $visited);
            }
        }
    }

    /**
     * @return list<string> the classes of the objects that $type holds, also as array values
     */
    public static function getObjectClasses(Union $type): array
    {
        $classes = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject) {
                $classes[] = $atomic->value;
                if ($atomic instanceof TGenericObject) {
                    foreach ($atomic->type_params as $typeParam) {
                        $classes = [...$classes, ...self::getObjectClasses($typeParam)];
                    }
                }
            } elseif ($atomic instanceof TKeyedArray) {
                foreach ($atomic->properties as $propertyType) {
                    $classes = [...$classes, ...self::getObjectClasses($propertyType)];
                }
                if (null !== $atomic->fallback_params) {
                    $classes = [...$classes, ...self::getObjectClasses($atomic->fallback_params[1])];
                }
            } elseif ($atomic instanceof TArray) {
                $classes = [...$classes, ...self::getObjectClasses($atomic->type_params[1])];
            }
        }

        return $classes;
    }
}
