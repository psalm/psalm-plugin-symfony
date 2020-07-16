<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Taint;


use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\FileManipulation;
use Psalm\Plugin\Hook\AfterExpressionAnalysisInterface;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Type\TaintKindGroup;
use Psalm\Type\Union;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderBag;


/*
 * The `afterMethodCallAnalysis` is used to spot the tainted method calls, while the
 * `afterExpressionAnalysis` is used to actually add the taint source to the code base.
 * This is not really perfect, but it's the less "magical".
 */
class RequestTaint implements AfterMethodCallAnalysisInterface, AfterExpressionAnalysisInterface
{
    /** @var Expr */
    private static $nextTaintedNode;

    public static function afterMethodCallAnalysis(Expr $expr, string $method_id, string $appearing_method_id, string $declaring_method_id, Context $context, StatementsSource $statementsSource, Codebase $codebase, array &$file_replacements = [], Union &$return_type_candidate = null): void
    {
        if($method_id !== HeaderBag::class.'::get') {
            return;
        }

        $firstArgument = $expr->args[0]->value;
        if(!$firstArgument instanceof String_) {
            return;
        }

        $headerName = strtolower($firstArgument->value);
        if($headerName !== 'user-agent') {
            return;
        }

        static::$nextTaintedNode = $expr;
    }

    public static function afterExpressionAnalysis(Expr $expr, Context $context, StatementsSource $statementsSource, Codebase $codebase, array &$fileReplacements = []): void
    {
        if ($expr !== static::$nextTaintedNode) {
            return;
        }
        static::$nextTaintedNode = null;

        $type = $statementsSource->getNodeTypeProvider()->getType($expr);
        if ($type === null) {
            throw new \RuntimeException('Can not guess the type of the expression.');
        }

        $uniqId = $statementsSource->getFileName() . ':' . $expr->getLine() . '/' . $expr->getStartTokenPos();
        $codebase->addTaintSource(
            $type,
            'tainted-' . $uniqId,
            TaintKindGroup::ALL_INPUT,
            new CodeLocation($statementsSource, $expr)
        );
    }
}
