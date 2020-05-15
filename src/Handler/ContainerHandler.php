<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

class ContainerHandler implements AfterMethodCallAnalysisInterface
{
    const GET_CLASSLIKES = [
        'Psr\Container\ContainerInterface',
        'Symfony\Component\DependencyInjection\ContainerInterface',
        'Symfony\Bundle\FrameworkBundle\Controller\AbstractController',
        'Symfony\Bundle\FrameworkBundle\Controller\ControllerTrait',
    ];

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
        if (!self::isContainerGet($declaring_method_id)) {
            return;
        }

        if ($return_type_candidate && $expr->args[0]->value instanceof ClassConstFetch) {
            $className = (string) $expr->args[0]->value->class->getAttribute('resolvedName');
            $return_type_candidate = new Union([new TNamedObject($className)]);
        }
    }

    public static function isContainerGet(string $declaring_method_id): bool
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
}
