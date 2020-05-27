<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\FileSource;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterClassLikeVisitInterface;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\SymfonyPsalmPlugin\Issue\NamingConventionViolation;
use Psalm\SymfonyPsalmPlugin\Issue\PrivateService;
use Psalm\SymfonyPsalmPlugin\Issue\ServiceNotFound;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

class ContainerHandler implements AfterMethodCallAnalysisInterface, AfterClassLikeVisitInterface
{
    private const GET_CLASSLIKES = [
        'Psr\Container\ContainerInterface',
        'Symfony\Component\DependencyInjection\ContainerInterface',
        'Symfony\Bundle\FrameworkBundle\Controller\AbstractController',
        'Symfony\Bundle\FrameworkBundle\Controller\ControllerTrait',
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
    public static function afterMethodCallAnalysis(
        Expr $expr,
        string $method_id,
        string $appearing_method_id,
        string $declaring_method_id,
        Context $context,
        StatementsSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = [],
        Union &$return_type_candidate = null
    ) {
        if (!ContainerHandler::isContainerGet($declaring_method_id)) {
            return;
        }

        if (!self::$containerMeta) {
            if ($return_type_candidate && $expr->args[0]->value instanceof ClassConstFetch) {
                $className = (string) $expr->args[0]->value->class->getAttribute('resolvedName');
                if (!in_array($className, ['self', 'parent', 'static'])) {
                    $return_type_candidate = new Union([new TNamedObject($className)]);
                }
            }

            return;
        }

        if ($expr->args[0]->value instanceof String_) {
            $serviceId = (string) $expr->args[0]->value->value;
        } elseif ($expr->args[0]->value instanceof ClassConstFetch) {
            $serviceId = (string) $expr->args[0]->value->class->getAttribute('resolvedName');
        } else {
            return;
        }

        $service = self::$containerMeta->get($serviceId);
        if ($service) {
            if (!self::followsConvention($serviceId) && !class_exists($service->getClassName())) {
                IssueBuffer::accepts(
                    new NamingConventionViolation(new CodeLocation($statements_source, $expr->args[0]->value)),
                    $statements_source->getSuppressedIssues()
                );
            }

            if ($service->isPublic()) {
                $class = $service->getClassName();
                if ($class) {
                    /** @psalm-suppress InternalMethod */
                    $codebase->classlikes->addFullyQualifiedClassName($class);
                    $return_type_candidate = new Union([new TNamedObject($class)]);
                }
            } else {
                IssueBuffer::accepts(
                    new PrivateService($serviceId, new CodeLocation($statements_source, $expr->args[0]->value)),
                    $statements_source->getSuppressedIssues()
                );
            }
        } else {
            IssueBuffer::accepts(
                new ServiceNotFound($serviceId, new CodeLocation($statements_source, $expr->args[0]->value)),
                $statements_source->getSuppressedIssues()
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public static function afterClassLikeVisit(
        ClassLike $class_node,
        ClassLikeStorage $class_storage,
        FileSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = []
    ) {
        if (\in_array($class_storage->name, ContainerHandler::GET_CLASSLIKES)) {
            if (self::$containerMeta) {
                $file_path = $statements_source->getFilePath();
                $file_storage = $codebase->file_storage_provider->get($file_path);

                foreach (self::$containerMeta->getClassNames() as $className) {
                    $codebase->queueClassLikeForScanning($className);
                    $file_storage->referenced_classlikes[strtolower($className)] = $className;
                }
            }
        }
    }

    private static function isContainerGet(string $declaring_method_id): bool
    {
        return in_array(
            $declaring_method_id,
            array_map(
                function($c) {
                    return $c . '::get';
                },
                self::GET_CLASSLIKES
            ),
            true
        );
    }

    /**
     * @see https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions
     */
    private static function followsConvention(string $name): bool
    {
        return !preg_match('/[A-Z]/', $name);
    }
}
