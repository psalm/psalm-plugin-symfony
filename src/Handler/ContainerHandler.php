<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\SymfonyPsalmPlugin\Issue\NamingConventionViolation;
use Psalm\SymfonyPsalmPlugin\Issue\PrivateService;
use Psalm\SymfonyPsalmPlugin\Issue\ServiceNotFound;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\SymfonyPsalmPlugin\Symfony\FrameworkCalls;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;

final class ContainerHandler implements AfterMethodCallAnalysisInterface, AfterClassLikeVisitInterface, AfterCodebasePopulatedInterface
{
    private const GET_CLASSLIKES = [
        'Psr\Container\ContainerInterface',
        'Symfony\Component\DependencyInjection\ContainerInterface',
        'Symfony\Component\DependencyInjection\Container',
        'Symfony\Bundle\FrameworkBundle\Controller\AbstractController',
        'Symfony\Bundle\FrameworkBundle\Controller\ControllerTrait',
        'Symfony\Bundle\FrameworkBundle\Test\TestContainer',
    ];

    /**
     * @see \Doctrine\ORM\Events
     */
    private const DOCTRINE_LIFECYCLE_EVENTS = ['preRemove', 'postRemove', 'prePersist', 'postPersist', 'preUpdate', 'postUpdate', 'postLoad', 'preFlush'];

    private static ?ContainerMeta $containerMeta = null;

    public static function init(ContainerMeta $containerMeta): void
    {
        self::$containerMeta = $containerMeta;
    }

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $declaring_method_id = $event->getDeclaringMethodId();
        $statements_source = $event->getStatementsSource();
        $expr = $event->getExpr();
        $codebase = $event->getCodebase();
        $context = $event->getContext();
        if ($expr instanceof \PhpParser\Node\Expr\MethodCall && null !== ServiceLocatorHandler::getServices($statements_source->getNodeTypeProvider()->getType($expr->var))) {
            return;
        }

        if (!isset($expr->args[0])) {
            return;
        }

        $firstArg = $expr->args[0];
        if (!$firstArg instanceof Arg) {
            return;
        }

        if (!self::isContainerMethod($declaring_method_id, 'get')) {
            if (self::isContainerMethod($declaring_method_id, 'getparameter')) {
                $argument = $firstArg->value;
                if ($argument instanceof String_ && !self::followsParameterNamingConvention($argument->value) && false === strpos($argument->value, '\\')) {
                    IssueBuffer::accepts(
                        new NamingConventionViolation(new CodeLocation($statements_source, $argument)),
                        $statements_source->getSuppressedIssues()
                    );
                }
            }

            return;
        }

        if (!self::$containerMeta) {
            if ($event->getReturnTypeCandidate() && $firstArg->value instanceof ClassConstFetch) {
                $className = (string) $firstArg->value->class->getAttribute('resolvedName');
                if (!in_array($className, ['self', 'parent', 'static'])) {
                    $event->setReturnTypeCandidate(new Union([new TNamedObject($className)]));
                }
            }

            return;
        }

        $idArgument = $firstArg->value;

        if ($idArgument instanceof String_) {
            $serviceId = $idArgument->value;
        } elseif ($idArgument instanceof ClassConstFetch) {
            $className = (string) $idArgument->class->getAttribute('resolvedName');
            if ('self' === $className) {
                $className = $event->getStatementsSource()->getSource()->getFQCLN();
            }
            if (!$idArgument->name instanceof Identifier || null === $className) {
                return;
            }

            if ('class' === $idArgument->name->name) {
                $serviceId = $className;
            } else {
                try {
                    $serviceId = \constant($className.'::'.$idArgument->name->name);
                } catch (\Exception) {
                    return;
                }
            }
        } else {
            return;
        }

        try {
            $service = self::$containerMeta->get($serviceId, $context->self);

            if (!self::followsNamingConvention($serviceId) && false === strpos($serviceId, '\\')) {
                IssueBuffer::accepts(
                    new NamingConventionViolation(new CodeLocation($statements_source, $firstArg->value)),
                    $statements_source->getSuppressedIssues()
                );
            }

            $class = $service->getClass();
            if (null !== $class) {
                $codebase->classlikes->addFullyQualifiedClassName($class);
                $event->setReturnTypeCandidate(new Union([new TNamedObject($class)]));
            }

            if (!$service->isPublic()) {
                /** @var class-string $kernelTestCaseClass */
                $kernelTestCaseClass = 'Symfony\Bundle\FrameworkBundle\Test\KernelTestCase';
                $isTestContainer = null !== $context->parent
                    && ($kernelTestCaseClass === $context->parent
                        || is_subclass_of($context->parent, $kernelTestCaseClass)
                    );
                if (!$isTestContainer) {
                    IssueBuffer::accepts(
                        new PrivateService($serviceId, new CodeLocation($statements_source, $firstArg->value)),
                        $statements_source->getSuppressedIssues()
                    );
                }
            }
        } catch (ServiceNotFoundException) {
            IssueBuffer::accepts(
                new ServiceNotFound($serviceId, new CodeLocation($statements_source, $firstArg->value)),
                $statements_source->getSuppressedIssues()
            );
        }
    }

    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $codebase = $event->getCodebase();
        $statements_source = $event->getStatementsSource();
        $storage = $event->getStorage();

        $fileStorage = $codebase->file_storage_provider->get($statements_source->getFilePath());

        if (\in_array($storage->name, ContainerHandler::GET_CLASSLIKES)) {
            if (self::$containerMeta) {
                foreach (self::$containerMeta->getClassNames() as $className) {
                    $codebase->queueClassLikeForScanning($className);
                    $fileStorage->referenced_classlikes[strtolower($className)] = $className;
                }
            }
        }
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        if (null === self::$containerMeta) {
            return;
        }

        $codebase = $event->getCodebase();

        foreach (self::$containerMeta->getDefinitions() as $definition) {
            if ($definition->isAbstract() || $definition->hasTag('container.excluded')) {
                continue;
            }

            $class = self::getClass($definition);
            if (null !== $class) {
                FrameworkCalls::useClass($codebase, $class, FrameworkCalls::CONTAINER);
                // synthetic services, e.g. the kernel, are created outside of the container
                if (null === $definition->getFactory() && !$definition->isSynthetic()) {
                    FrameworkCalls::callMethod($codebase, $class, '__construct', FrameworkCalls::CONTAINER);
                }
            }

            foreach ([$definition->getFactory(), $definition->getConfigurator()] as $callable) {
                if (is_array($callable) && is_string($callable[1] ?? null) && null !== $callableClass = self::getCallableClass($callable[0] ?? null, $class)) {
                    FrameworkCalls::callMethod($codebase, $callableClass, $callable[1], FrameworkCalls::CONTAINER);
                }
            }

            foreach ($definition->getMethodCalls() as [$method, $arguments]) {
                if (null !== $class) {
                    FrameworkCalls::callMethod($codebase, $class, $method, FrameworkCalls::CONTAINER);
                }

                // the listeners that RegisterListenersPass registered, subscribers included, with their method names resolved
                if ('addListener' === $method && is_array($listener = $arguments[1] ?? null) && is_string($listener[1] ?? null) && null !== $listenerClass = self::getCallableClass($listener[0] ?? null, null)) {
                    FrameworkCalls::callMethod($codebase, $listenerClass, $listener[1], FrameworkCalls::EVENT_DISPATCHER);
                }
            }

            if (null !== $class) {
                foreach ($definition->getTags() as $name => $tags) {
                    foreach ($tags as $tag) {
                        self::callTaggedMethods($codebase, $definition, $class, $name, $tag);
                    }
                }
            }
        }
    }

    /**
     * Calls the methods that a tag names, or that the pass of the tag picks when the tag names none.
     */
    private static function callTaggedMethods(Codebase $codebase, Definition $definition, string $class, string $name, mixed $tag): void
    {
        if (!is_array($tag)) {
            return;
        }

        $method = self::getNonEmptyString($tag['method'] ?? null);
        $event = self::getNonEmptyString($tag['event'] ?? null);

        switch ($name) {
            case 'kernel.event_listener':
                // @see \Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass::process()
                if (null === $event) {
                    if ($definition->hasTag('kernel.event_subscriber')) {
                        return;
                    }
                    $method ??= '__invoke';
                } elseif (null === $method) {
                    // e.g. onKernelFinishRequest for kernel.finish_request
                    $method = 'on'.str_replace(' ', '', ucwords((string) preg_replace('/[^a-z0-9]/i', ' ', $event)));
                    if (!FrameworkCalls::methodExists($codebase, $class, $method)) {
                        $method = '__invoke';
                    }
                }
                FrameworkCalls::callMethod($codebase, $class, $method, FrameworkCalls::EVENT_DISPATCHER);

                return;
            case 'messenger.message_handler':
                FrameworkCalls::callMethod($codebase, $class, $method ?? '__invoke', FrameworkCalls::MESSENGER);

                return;
            case 'doctrine.orm.entity_listener':
                // @see \Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\EntityListenerPass::attachToListener()
                if (null !== $method) {
                    $methods = [$method];
                } elseif (null !== $event) {
                    $methods = [!FrameworkCalls::methodExists($codebase, $class, $event) && FrameworkCalls::methodExists($codebase, $class, '__invoke') ? '__invoke' : $event];
                } else {
                    // listeners of #[EntityListeners] mappings: Doctrine calls the methods named after the events
                    $methods = array_filter(self::DOCTRINE_LIFECYCLE_EVENTS, static fn (string $event): bool => FrameworkCalls::methodExists($codebase, $class, $event));
                }
                foreach ($methods as $method) {
                    FrameworkCalls::callMethod($codebase, $class, $method, FrameworkCalls::DOCTRINE_LISTENERS);
                }

                return;
            case 'doctrine.event_listener':
                // the event manager calls the method named after the event
                if (null !== $event) {
                    FrameworkCalls::callMethod($codebase, $class, $event, FrameworkCalls::DOCTRINE_EVENT_MANAGER);
                }

                return;
        }

        // other tags that name a method, e.g. kernel.reset
        if (null !== $method) {
            FrameworkCalls::callMethod($codebase, $class, $method, FrameworkCalls::CONTAINER);
        }
    }

    /**
     * @psalm-pure
     */
    private static function getNonEmptyString(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }

    private static function getClass(Definition $definition): ?string
    {
        $class = $definition->getClass();

        return null === $class || str_starts_with($class, '%') ? null : $class;
    }

    /**
     * @param string|null $selfClass the class of the service, for a callable of the service itself
     */
    private static function getCallableClass(mixed $target, ?string $selfClass): ?string
    {
        if ($target instanceof ServiceClosureArgument) {
            $target = $target->getValues()[0] ?? null;
        }

        if ($target instanceof Reference) {
            try {
                return null === self::$containerMeta ? null : self::getClass(self::$containerMeta->get((string) $target));
            } catch (ServiceNotFoundException) {
                return null;
            }
        }

        if ($target instanceof Definition) {
            return self::getClass($target);
        }

        if (is_string($target) && !str_starts_with($target, '%')) {
            return $target;
        }

        return null === $target ? $selfClass : null;
    }

    /**
     * @psalm-pure
     */
    public static function isContainerMethod(string $declaringMethodId, string $methodName): bool
    {
        return in_array(
            $declaringMethodId,
            array_map(
                function ($c) use ($methodName) {
                    return $c.'::'.$methodName;
                },
                self::GET_CLASSLIKES
            ),
            true
        );
    }

    /**
     * @psalm-pure
     */
    private static function followsParameterNamingConvention(string $name): bool
    {
        if (str_starts_with($name, 'env(')) {
            return true;
        }

        return self::followsNamingConvention($name);
    }

    /**
     * @see https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions
     *
     * @psalm-pure
     */
    private static function followsNamingConvention(string $name): bool
    {
        return !preg_match('/[A-Z]/', $name);
    }
}
