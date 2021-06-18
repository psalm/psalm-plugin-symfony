<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\Type\Atomic;
use Psalm\Type\Union;

class ParameterBagHandler implements AfterMethodCallAnalysisInterface
{
    /**
     * @var ContainerMeta|null
     */
    private static $containerMeta;

    public static function init(ContainerMeta $containerMeta): void
    {
        self::$containerMeta = $containerMeta;
    }

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
    ): void {
        if (!self::$containerMeta || 'Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface::get' !== $declaring_method_id) {
            return;
        }

        if (!isset($expr->args[0]->value) || !($expr->args[0]->value instanceof String_)) {
            return;
        }

        $argument = $expr->args[0]->value->value;

        // @todo find a better way to calculate return type
        switch (gettype(self::$containerMeta->getParameter($argument))) {
            case 'string':
                $return_type_candidate = new Union([Atomic::create('string')]);
                break;
            case 'boolean':
                $return_type_candidate = new Union([Atomic::create('bool')]);
                break;
            case 'integer':
                $return_type_candidate = new Union([Atomic::create('integer')]);
                break;
            case 'double':
                $return_type_candidate = new Union([Atomic::create('float')]);
                break;
            case 'array':
                $return_type_candidate = new Union([Atomic::create('array')]);
                break;
        }
    }
}
