<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\MethodIdentifier;
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
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Serializer;

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

    /** @var array<class-string, string> */
    private const INVOKABLE_CLASS_ATTRIBUTES = [
        AsController::class => 'Symfony\Component\HttpKernel\Kernel::handle',
    ];

    /** @var array<string, string> */
    private const CALLABLE_METHOD_ATTRIBUTES = [
        Route::class => 'Symfony\Component\HttpKernel\Kernel::handle',
        'Symfony\Component\Validator\Constraints\Callback' => 'Symfony\Component\Validator\Validator\TraceableValidator::validate',
        'Doctrine\ORM\Mapping\PrePersist' => 'Doctrine\ORM\EntityManager::flush',
        'Doctrine\ORM\Mapping\PostPersist' => 'Doctrine\ORM\EntityManager::flush',
        'Doctrine\ORM\Mapping\PreUpdate' => 'Doctrine\ORM\EntityManager::flush',
        'Doctrine\ORM\Mapping\PostUpdate' => 'Doctrine\ORM\EntityManager::flush',
        'Doctrine\ORM\Mapping\PreRemove' => 'Doctrine\ORM\EntityManager::flush',
        'Doctrine\ORM\Mapping\PostRemove' => 'Doctrine\ORM\EntityManager::flush',
        'Doctrine\ORM\Mapping\PostLoad' => 'Doctrine\ORM\EntityManager::flush',
        'Doctrine\ORM\Mapping\PreFlush' => 'Doctrine\ORM\EntityManager::flush',
    ];

    /** @var array<string, string> */
    private const MAP_PAYLOAD_ATTRIBUTES = [
        'Symfony\Component\HttpKernel\Attribute\MapQueryString' => 'Symfony\Component\HttpKernel\Kernel::handle',
        'Symfony\Component\HttpKernel\Attribute\MapRequestPayload' => 'Symfony\Component\HttpKernel\Kernel::handle',
    ];

    /** @var array<string, string> */
    private const CALLABLE_TAGS = [
        'kernel.event_listener' => 'Symfony\Component\EventDispatcher\EventDispatcher::dispatch',
        'messenger.message_handler' => 'Symfony\Component\Messenger\Middleware\HandleMessageMiddleware::handle',
        'doctrine.orm.entity_listener' => 'Doctrine\ORM\Event\ListenersInvoker::invoke',
    ];

    private static ?ContainerMeta $containerMeta = null;

    /** @var array<string, true> */
    private static array $markedDtoConstructors = [];

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

        if (!isset($expr->args[0])) {
            return;
        }

        $firstArg = $expr->args[0];
        if (!$firstArg instanceof Arg) {
            return;
        }
        $secondArg = $expr->args[1] ?? null;

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

            // when calling e.g. `$denormalizer->denormalize([], Foo::class)` mark constructor of Foo and sub-objects as used
            if (self::isDenormalizerMethod($declaring_method_id) && $secondArg instanceof Arg && ($value = $secondArg->value) instanceof ClassConstFetch) {
                $className = $value->class->getAttribute('resolvedName');
                $visited = [];
                self::markConstructorChainAsUsed($className, $codebase, $declaring_method_id, $visited);
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
        $storage = $event->getStorage();

        // When parameter has mapping attribute (#[MapRequestPayload]), map class target and sub-objects as used
        foreach ($storage->methods as $method) {
            foreach ($method->params as $param) {
                foreach ($param->attributes as $attribute) {
                    $mapCaller = self::MAP_PAYLOAD_ATTRIBUTES[$attribute->fq_class_name] ?? null;
                    if (null === $mapCaller) {
                        continue;
                    }
                    $type = $param->signature_type ?? $param->type;
                    if (null === $type) {
                        continue;
                    }
                    foreach (self::extractNamedObjectClassNames($type) as $className) {
                        self::markConstructorChainAsUsed($className, $codebase, $mapCaller, self::$markedDtoConstructors);
                    }
                }
            }
        }

        // Mark invokable classes (e.g. #[AsController]) as used.
        foreach (self::INVOKABLE_CLASS_ATTRIBUTES as $attributeClass => $callerIdentifier) {
            if ($storage->hasAttributeIncludingParents($attributeClass, $codebase)) {
                $identifier = new MethodIdentifier($storage->name, '__invoke');
                $codebase->methodExists($identifier, null, $callerIdentifier);
            }
        }

        // Mark EventSubscriber methods as used.
        $dispatchCaller = new MethodIdentifier(EventDispatcher::class, 'dispatch');
        if (is_subclass_of($class = $storage->name, EventSubscriberInterface::class) && method_exists($class, 'getSubscribedEvents')) {
            foreach ($class::getSubscribedEvents() as $params) {
                if (\is_string($params)) {
                    $codebase->methodExists($class.'::'.$params, null, $dispatchCaller);
                } elseif (isset($params[0]) && \is_string($params[0])) {
                    $codebase->methodExists($class.'::'.$params[0], null, $dispatchCaller);
                } else {
                    foreach ($params as $listener) {
                        if (!isset($listener[0])) {
                            continue;
                        }
                        $codebase->methodExists($class.'::'.$listener[0], null, $dispatchCaller);
                    }
                }
            }
        }

        // map methods as used with certain attributes (e.g. #[Route])
        foreach ($storage->methods as $method) {
            if (null === $method->cased_name) {
                continue;
            }
            foreach ($method->attributes as $attribute) {
                $callerIdentifier = self::CALLABLE_METHOD_ATTRIBUTES[$attribute->fq_class_name] ?? null;
                if (null === $callerIdentifier) {
                    continue;
                }
                $codebase->methodExists($storage->name.'::'.$method->cased_name, null, $callerIdentifier);
            }
        }

        if (!self::$containerMeta instanceof ContainerMeta) {
            return;
        }

        $fileStorage = $codebase->file_storage_provider->get($event->getStatementsSource()->getFilePath());

        if (\in_array($storage->name, self::GET_CLASSLIKES)) {
            foreach (self::$containerMeta->getClassNames() as $className) {
                $codebase->queueClassLikeForScanning($className);
                $fileStorage->referenced_classlikes[strtolower($className)] = $className;
            }
        }
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        if (null === self::$containerMeta) {
            return;
        }

        // mark constructors of all services as used
        $callerIdentifier = new MethodIdentifier(Container::class, 'get');
        foreach (self::$containerMeta->getDefinitions() as $definition) {
            if ($definition->hasTag('container.excluded')) {
                continue;
            }
            if (null === $class = $definition->getClass()) {
                continue;
            }
            $event->getCodebase()->file_reference_provider->addMethodReferenceToClass((string) $callerIdentifier, \strtolower($class));
            $identifier = new MethodIdentifier($class, '__construct');
            $event->getCodebase()->methodExists($identifier, null, $callerIdentifier);
            foreach ($definition->getMethodCalls() as $methodCall) {
                if (is_array($methodCall) && isset($methodCall[0]) && is_string($methodCall[0])) {
                    $identifier = new MethodIdentifier($class, \strtolower($methodCall[0]));
                    $event->getCodebase()->methodExists($identifier, null, $callerIdentifier);
                }
            }
        }

        // find tagged services and mark `method` as called
        foreach (self::CALLABLE_TAGS as $tagName => $callerIdentifier) {
            foreach (self::$containerMeta->findTaggedServices($tagName) as $id => $tags) {
                foreach ($tags as $tag) {
                    if (!is_array($tag) || !array_key_exists('method', $tag)) {
                        continue;
                    }
                    $class = self::$containerMeta->get($id)->getClass();
                    if (null === $class) {
                        continue;
                    }
                    $method = $tag['method'] ?: '__invoke';
                    $event->getCodebase()->methodExists($class.'::'.$method, null, $callerIdentifier);
                }
            }
        }

        // get factories from container and mark container as caller
        $callerIdentifier = new MethodIdentifier(Container::class, 'get');
        foreach (self::$containerMeta->getInstanceClassFactories() as $factory) {
            try {
                $class = $factory[0] instanceof Reference ? self::$containerMeta->get((string) $factory[0])->getClass() : $factory[0];
            } catch (ServiceNotFoundException) {
                continue;
            }
            if (null === $class) {
                continue;
            }
            $event->getCodebase()->methodExists($class.'::'.$factory[1], null, $callerIdentifier);
        }
    }

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

    private static function followsParameterNamingConvention(string $name): bool
    {
        if (str_starts_with($name, 'env(')) {
            return true;
        }

        return self::followsNamingConvention($name);
    }

    /**
     * @see https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions
     */
    private static function followsNamingConvention(string $name): bool
    {
        return !preg_match('/[A-Z]/', $name);
    }

    private static function isDenormalizerMethod(string $methodName): bool
    {
        if (!str_starts_with($methodName, 'Symfony\Component\Serializer\\')) {
            return false;
        }

        return str_ends_with($methodName, 'denormalize') || str_ends_with($methodName, 'deserialize');
    }

    /** @param array<string, true> $visited guard against circular references */
    private static function markConstructorChainAsUsed(string $className, Codebase $codebase, string $callingMethodId, array &$visited): void
    {
        if (isset($visited[$className])) {
            return;
        }
        $visited[$className] = true;

        if ($codebase->classlike_storage_provider->has($className)) {
            $classStorage = $codebase->classlike_storage_provider->get($className);
            if (null !== $classStorage->location) {
                $codebase->classExists($className, $classStorage->location, null, $callingMethodId);
            }
        }

        $codebase->methodExists(new MethodIdentifier($className, '__construct'), null, $callingMethodId);

        if (!$codebase->classlike_storage_provider->has($className)) {
            return;
        }

        $classStorage = $codebase->classlike_storage_provider->get($className);

        foreach ($classStorage->properties as $propertyStorage) {
            $type = $propertyStorage->type ?? $propertyStorage->signature_type;
            if (null === $type) {
                continue;
            }
            foreach (self::extractNamedObjectClassNames($type) as $nestedClassName) {
                self::markConstructorChainAsUsed($nestedClassName, $codebase, $callingMethodId, $visited);
            }
        }
    }

    /** @return string[] */
    private static function extractNamedObjectClassNames(Union $type): array
    {
        $classNames = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject) {
                $classNames[] = $atomic->value;
            } elseif ($atomic instanceof TKeyedArray) {
                // covers list<SubObject>, non-empty-list<SubObject>, and array{key: SubObject}
                foreach ($atomic->properties as $propType) {
                    foreach (self::extractNamedObjectClassNames($propType) as $name) {
                        $classNames[] = $name;
                    }
                }
            } elseif ($atomic instanceof TArray) {
                // covers SubObject[] (array<array-key, SubObject>) and TNonEmptyArray
                foreach (self::extractNamedObjectClassNames($atomic->type_params[1]) as $name) {
                    $classNames[] = $name;
                }
            }
        }

        return $classNames;
    }
}
