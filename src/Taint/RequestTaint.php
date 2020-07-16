<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Taint;


use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Plugin\Hook\AfterExpressionAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Type\TaintKindGroup;
use Symfony\Component\HttpFoundation\HeaderBag;

class RequestTaint implements AfterExpressionAnalysisInterface
{
    public static function afterExpressionAnalysis(Expr $expr, Context $context, StatementsSource $statementsSource, Codebase $codebase, array &$fileReplacements = []): void
    {
        if(!$expr instanceof MethodCall || strval($expr->name) !== 'get' || !isset($expr->args[0])) {
            return;
        }

        $firstArgument = $expr->args[0]->value;
        $sourceType = $statementsSource->getNodeTypeProvider()->getType($expr);
        if(!$firstArgument instanceof String_ || $sourceType === null) {
            return;
        }

        $types = $statementsSource->getNodeTypeProvider()->getType($expr->var);
        if($types === null || !in_array(HeaderBag::class, array_keys($types->getAtomicTypes()))) {
            return;
        }

        $headerName = strtolower($firstArgument->value);
        if($headerName !== 'user-agent') {
            return;
        }

        $uniqId = $statementsSource->getFileName() . ':' . $expr->getLine() . '/' . $expr->getStartTokenPos();
        $codebase->addTaintSource(
            $sourceType,
            'tainted-header-'.$uniqId,
            TaintKindGroup::ALL_INPUT,
            new CodeLocation($statementsSource, $expr)
        );
    }
}
