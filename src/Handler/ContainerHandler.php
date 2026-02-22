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
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;
use Psalm\SymfonyPsalmPlugin\Issue\NamingConventionViolation;
use Psalm\SymfonyPsalmPlugin\Issue\PrivateService;
use Psalm\SymfonyPsalmPlugin\Issue\ServiceNotFound;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

final class ContainerHandler implements AfterMethodCallAnalysisInterface, AfterClassLikeVisitInterface, AfterCodebasePopulatedInterface, BeforeAddIssueInterface
{
    private const GET_CLASSLIKES = [
        'Psr\Container\ContainerInterface',
        'Symfony\Component\DependencyInjection\ContainerInterface',
        'Symfony\Component\DependencyInjection\Container',
        'Symfony\Bundle\FrameworkBundle\Controller\AbstractController',
        'Symfony\Bundle\FrameworkBundle\Controller\ControllerTrait',
        'Symfony\Bundle\FrameworkBundle\Test\TestContainer',
    ];

    private static ?ContainerMeta $containerMeta = null;

    /**
     * @var array<string> collection of cower-cased class names that are present in the container
     */
    private static array $containerClassNames = [];

    public static function init(ContainerMeta $containerMeta): void
    {
        self::$containerMeta = $containerMeta;

        self::$containerClassNames = array_map(function (string $className): string {
            return strtolower($className);
        }, self::$containerMeta->getClassNames());
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

            // when calling `$denormalizer->denormalize([], Foo::class)` mark constructor of Foo as used
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

        foreach ($event->getCodebase()->classlike_storage_provider->getAll() as $name => $storage) {
            if (in_array($name, self::$containerClassNames, true)) {
                $storage->suppressed_issues[] = 'UnusedClass';
            }
        }
    }

    #[\Override]
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        $data = $event->getIssue()->toIssueData('error');
        if ('PossiblyUnusedMethod' === $data->type
            && '__construct' === $data->selected_text
            && null !== $data->dupe_key
            && in_array(preg_replace('/::__construct$/', '', $data->dupe_key), self::$containerClassNames, true)) {
            // Don't report service constructors as PossiblyUnusedMethod
            return false;
        }

        return null;
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

    /**
     * Marks the constructor of $className as used, then recurses into all
     * property types to mark their constructors as used too. This is needed
     * because Symfony's Serializer constructs the full object graph at
     * runtime without any static instantiation in PHP code.
     *
     * @param array<string, true> $visited guard against circular references
     */
    private static function markConstructorChainAsUsed(string $className, Codebase $codebase, string $callingMethodId, array &$visited): void
    {
        if (isset($visited[$className])) {
            return;
        }
        $visited[$className] = true;

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

    /**
     * Recursively extracts fully-qualified class names from a Union type,
     * unwrapping array containers (SubObject[], array<K,V>, list<SubObject>)
     * so that collection-typed properties are also followed.
     *
     * @return list<string>
     */
    private static function extractNamedObjectClassNames(Union $type): array
    {
        $classNames = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject) {
                $classNames[] = $atomic->value;
            } elseif ($atomic instanceof TKeyedArray) {
                // covers list<SubObject>, non-empty-list<SubObject>, and array{key: SubObject}
                // for list<T>, properties[0] already holds T (same as fallback_params[1])
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
