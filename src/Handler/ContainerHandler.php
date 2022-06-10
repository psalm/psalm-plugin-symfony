<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use function constant;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\SymfonyPsalmPlugin\Issue\NamingConventionViolation;
use Psalm\SymfonyPsalmPlugin\Issue\PrivateService;
use Psalm\SymfonyPsalmPlugin\Issue\ServiceNotFound;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

class ContainerHandler implements AfterMethodCallAnalysisInterface, AfterClassLikeVisitInterface
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
     * @var ContainerMeta|null
     */
    private static $containerMeta;

    public static function init(ContainerMeta $containerMeta): void
    {
        self::$containerMeta = $containerMeta;
    }

    /**
     * {@inheritdoc}
     */
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

        if (!self::isContainerMethod($declaring_method_id, 'get')) {
            if (self::isContainerMethod($declaring_method_id, 'getparameter')) {
                $argument = $firstArg->value;
                if ($argument instanceof String_ && !self::followsNamingConvention($argument->value) && false === strpos($argument->value, '\\')) {
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
            if (!$idArgument->name instanceof Identifier || !$className) {
                return;
            }

            if ('class' === $idArgument->name->name) {
                $serviceId = $className;
            } else {
                try {
                    $serviceId = constant($className.'::'.$idArgument->name->name);
                } catch (\Exception $e) {
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
            if ($class) {
                $codebase->classlikes->addFullyQualifiedClassName($class);
                $event->setReturnTypeCandidate(new Union([new TNamedObject($class)]));
            }

            if (!$service->isPublic()) {
                /** @var class-string $kernelTestCaseClass */
                $kernelTestCaseClass = 'Symfony\Bundle\FrameworkBundle\Test\KernelTestCase';
                $isTestContainer = $context->parent &&
                    ($kernelTestCaseClass === $context->parent
                        || is_subclass_of($context->parent, $kernelTestCaseClass)
                    );
                if (!$isTestContainer) {
                    IssueBuffer::accepts(
                        new PrivateService($serviceId, new CodeLocation($statements_source, $firstArg->value)),
                        $statements_source->getSuppressedIssues()
                    );
                }
            }
        } catch (ServiceNotFoundException $e) {
            IssueBuffer::accepts(
                new ServiceNotFound($serviceId, new CodeLocation($statements_source, $firstArg->value)),
                $statements_source->getSuppressedIssues()
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
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

    private static function isContainerMethod(string $declaringMethodId, string $methodName): bool
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
     * @see https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions
     */
    private static function followsNamingConvention(string $name): bool
    {
        return !preg_match('/[A-Z]/', $name);
    }
}
