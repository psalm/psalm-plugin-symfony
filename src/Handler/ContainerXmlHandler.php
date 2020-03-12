<?php

namespace Seferov\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Seferov\SymfonyPsalmPlugin\Issue\PrivateService;
use Seferov\SymfonyPsalmPlugin\Issue\ServiceNotFound;
use Seferov\SymfonyPsalmPlugin\Symfony\ContainerMeta;

class ContainerXmlHandler implements AfterMethodCallAnalysisInterface
{
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

        if ($expr->args[0]->value instanceof String_) {
            $serviceId = (string) $expr->args[0]->value->value;
        } elseif ($expr->args[0]->value instanceof ClassConstFetch) {
            $serviceId = (string) $expr->args[0]->value->class->getAttribute('resolvedName');
        } else {
            return;
        }

        if (!self::$containerMeta) {
            throw new \Exception('\Seferov\SymfonyPsalmPlugin\Handler\ContainerXmlHandler::init() must be run to initialize this hook');
        }

        $service = self::$containerMeta->get($serviceId);
        if ($service) {
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
}
