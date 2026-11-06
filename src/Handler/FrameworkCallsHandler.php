<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Storage\AttributeStorage;
use Psalm\Storage\ClassLikeStorage;
use Psalm\SymfonyPsalmPlugin\Symfony\FrameworkCalls;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Union;

/**
 * Marks the code that Symfony, Doctrine and Twig call because of an attribute, a serializer call or a Twig callable as
 * used. The calls that the container knows of are recorded by {@see ContainerHandler}.
 */
final class FrameworkCallsHandler implements AfterCodebasePopulatedInterface, AfterMethodCallAnalysisInterface, AfterExpressionAnalysisInterface, AfterFunctionLikeAnalysisInterface
{
    private const ROUTE_ATTRIBUTES = [
        'Symfony\Component\Routing\Attribute\Route',
        // removed in Symfony 8.0
        'Symfony\Component\Routing\Annotation\Route',
    ];

    private const CALLBACK_ATTRIBUTE = 'Symfony\Component\Validator\Constraints\Callback';

    /**
     * Method attributes that make the method called, with the caller.
     */
    private const METHOD_ATTRIBUTES = [
        'Symfony\Component\Routing\Attribute\Route' => FrameworkCalls::KERNEL,
        'Symfony\Component\Routing\Annotation\Route' => FrameworkCalls::KERNEL,
        self::CALLBACK_ATTRIBUTE => FrameworkCalls::VALIDATOR,
        'Doctrine\ORM\Mapping\PrePersist' => FrameworkCalls::DOCTRINE_LISTENERS,
        'Doctrine\ORM\Mapping\PostPersist' => FrameworkCalls::DOCTRINE_LISTENERS,
        'Doctrine\ORM\Mapping\PreUpdate' => FrameworkCalls::DOCTRINE_LISTENERS,
        'Doctrine\ORM\Mapping\PostUpdate' => FrameworkCalls::DOCTRINE_LISTENERS,
        'Doctrine\ORM\Mapping\PreRemove' => FrameworkCalls::DOCTRINE_LISTENERS,
        'Doctrine\ORM\Mapping\PostRemove' => FrameworkCalls::DOCTRINE_LISTENERS,
        'Doctrine\ORM\Mapping\PostLoad' => FrameworkCalls::DOCTRINE_LISTENERS,
        'Doctrine\ORM\Mapping\PreFlush' => FrameworkCalls::DOCTRINE_LISTENERS,
        'Twig\Attribute\AsTwigFilter' => FrameworkCalls::TWIG,
        'Twig\Attribute\AsTwigFunction' => FrameworkCalls::TWIG,
        'Twig\Attribute\AsTwigTest' => FrameworkCalls::TWIG,
    ];

    /**
     * Parameter attributes that make the controller argument resolver denormalize the request into the parameter.
     */
    private const PAYLOAD_ATTRIBUTES = [
        'Symfony\Component\HttpKernel\Attribute\MapQueryString',
        'Symfony\Component\HttpKernel\Attribute\MapRequestPayload',
    ];

    /**
     * Twig callables whose second constructor argument is the callable.
     */
    private const TWIG_CALLABLES = [
        'Twig\TwigFilter',
        'Twig\TwigFunction',
        'Twig\TwigTest',
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        foreach ($codebase->classlike_storage_provider->getAll() as $storage) {
            if (null === $storage->location || !$codebase->config->isInProjectDirs($storage->location->file_path)) {
                continue;
            }

            self::callAttributedMethods($codebase, $storage);
        }
    }

    #[\Override]
    public static function afterStatementAnalysis(AfterFunctionLikeAnalysisEvent $event): ?bool
    {
        // references of a method are only kept when they are recorded while the method is analysed
        $caller = $event->getContext()->calling_method_id;
        if (null === $caller) {
            return null;
        }

        foreach ($event->getFunctionlikeStorage()->params as $param) {
            $type = $param->signature_type ?? $param->type;
            foreach ($param->attributes as $attribute) {
                if (null !== $type && in_array($attribute->fq_class_name, self::PAYLOAD_ATTRIBUTES, true)) {
                    foreach (FrameworkCalls::getObjectClasses($type) as $class) {
                        FrameworkCalls::instantiateWithProperties($event->getCodebase(), $class, $caller);
                    }
                }
            }
        }

        return null;
    }

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        // e.g. `$serializer->deserialize($json, Foo::class.'[]', 'json')`
        $methodId = strtolower($event->getDeclaringMethodId());
        if (!str_starts_with($methodId, 'symfony\component\serializer\\') || (!str_ends_with($methodId, '::denormalize') && !str_ends_with($methodId, '::deserialize'))) {
            return;
        }

        $expr = $event->getExpr();
        $typeArg = $expr->getArgs()[1] ?? null;
        if (null === $typeArg) {
            return;
        }

        $codebase = $event->getCodebase();
        $source = $event->getStatementsSource();
        $caller = $event->getContext()->calling_method_id ?? $event->getContext()->calling_function_id;
        $type = $source->getNodeTypeProvider()->getType($typeArg->value);
        if (null === $type || null === $caller) {
            return;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TLiteralClassString || $atomic instanceof TLiteralString) {
                FrameworkCalls::instantiateWithProperties($codebase, (string) preg_replace('/(\[\])+$/', '', $atomic->value), $caller);
            }
        }
    }

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        // e.g. `new TwigFilter('name', [Runtime::class, 'method'])`: Twig loads the runtime for a non-static method
        $expr = $event->getExpr();
        if (!$expr instanceof New_ || !$expr->class instanceof Name || !in_array($expr->class->getAttribute('resolvedName'), self::TWIG_CALLABLES, true)) {
            return null;
        }

        $callableArg = null;
        foreach ($expr->getArgs() as $i => $arg) {
            if (null === $arg->name ? 1 === $i : 'callable' === $arg->name->name) {
                $callableArg = $arg;
            }
        }
        if (!$callableArg instanceof Arg) {
            return null;
        }

        $source = $event->getStatementsSource();
        $callable = self::getLiteralCallable($source->getNodeTypeProvider()->getType($callableArg->value));
        if (null !== $callable) {
            $event->getCodebase()->methodExists(
                $callable[0].'::'.$callable[1],
                $event->getContext()->calling_method_id,
                new CodeLocation($source, $callableArg),
                $source,
            );
        }

        return null;
    }

    private static function callAttributedMethods(Codebase $codebase, ClassLikeStorage $storage): void
    {
        $hasMethodRoute = false;

        foreach ($storage->methods as $methodName => $method) {
            foreach ($method->attributes as $attribute) {
                $caller = self::METHOD_ATTRIBUTES[$attribute->fq_class_name] ?? null;
                if (null !== $caller) {
                    FrameworkCalls::callMethod($codebase, $storage->name, $methodName, $caller);
                }
                $hasMethodRoute = $hasMethodRoute || in_array($attribute->fq_class_name, self::ROUTE_ATTRIBUTES, true);
            }
        }

        foreach ($storage->attributes as $attribute) {
            if ('Symfony\Component\HttpKernel\Attribute\AsController' === $attribute->fq_class_name) {
                FrameworkCalls::callMethod($codebase, $storage->name, '__invoke', FrameworkCalls::KERNEL);
            } elseif (!$hasMethodRoute && in_array($attribute->fq_class_name, self::ROUTE_ATTRIBUTES, true)) {
                // @see \Symfony\Component\Routing\Loader\AttributeClassLoader::load(): a class route without method routes routes __invoke
                FrameworkCalls::callMethod($codebase, $storage->name, '__invoke', FrameworkCalls::KERNEL);
            } elseif (self::CALLBACK_ATTRIBUTE === $attribute->fq_class_name) {
                // e.g. #[Assert\Callback('validate')]
                $callback = self::getCallbackMethod($attribute);
                if (null !== $callback) {
                    FrameworkCalls::callMethod($codebase, $storage->name, $callback, FrameworkCalls::VALIDATOR);
                }
            }
        }
    }

    private static function getCallbackMethod(AttributeStorage $attribute): ?string
    {
        foreach ($attribute->args as $i => $arg) {
            if ((null === $arg->name ? 0 === $i : 'callback' === $arg->name) && $arg->type instanceof Union && $arg->type->isSingleStringLiteral()) {
                return $arg->type->getSingleStringLiteral()->value;
            }
        }

        return null;
    }

    /**
     * @return array{string, string}|null
     */
    private static function getLiteralCallable(?Union $type): ?array
    {
        if (null === $type || !$type->isSingle()) {
            return null;
        }

        $array = $type->getSingleAtomic();
        if (!$array instanceof TKeyedArray || 2 !== count($array->properties) || !isset($array->properties[0], $array->properties[1])) {
            return null;
        }

        $class = $array->properties[0]->isSingle() ? $array->properties[0]->getSingleAtomic() : null;
        $method = $array->properties[1]->isSingleStringLiteral() ? $array->properties[1]->getSingleStringLiteral()->value : null;
        if (null === $method || !($class instanceof TLiteralClassString || $class instanceof TLiteralString)) {
            return null;
        }

        return [$class->value, $method];
    }
}
