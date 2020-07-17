<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Taint;


use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\FileManipulation;
use Psalm\Plugin\Hook\AfterEveryFunctionCallAnalysisInterface;
use Psalm\Plugin\Hook\AfterExpressionAnalysisInterface;
use Psalm\Plugin\Hook\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type\TaintKindGroup;
use Psalm\Type\Union;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class TwigTaint implements AfterMethodCallAnalysisInterface, AfterExpressionAnalysisInterface
{
    /** @var Expr */
    private static $nextTaintedNode;

    /**
     * This callback is used to spot the calls that may be a sink
     */
    public static function afterMethodCallAnalysis(Expr $expr, string $method_id, string $appearing_method_id, string $declaring_method_id, Context $context, StatementsSource $statementsSource, Codebase $codebase, array &$file_replacements = [], Union &$return_type_candidate = null): void
    {
        if($appearing_method_id !== AbstractController::class.'::render') {
            return;
        }

        $templateName = $expr->args[0]->value;
        if(!$templateName instanceof String_) {
            return;
        }

        if(self::hasAutoescaping($templateName)) {
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
        $codebase->addTaintSink(
            $type,
            'tainted-' . $uniqId,
            TaintKindGroup::ALL_INPUT,
            new CodeLocation($statementsSource, $expr)
        );
    }

    private static function hasAutoescaping(String_ $templateName): bool
    {
        //@todo: implement
        return empty($templateName);
    }
}
