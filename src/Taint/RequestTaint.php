<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Taint;


use PhpParser\Node\Expr;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\FileManipulation;
use Psalm\Internal\Analyzer\Statements\Expression\ExpressionIdentifier;
use Psalm\Issue\TaintedInput;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterExpressionAnalysisInterface;
use Psalm\StatementsSource;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\ParameterBag;

class RequestTaint implements AfterExpressionAnalysisInterface
{

    public static function afterExpressionAnalysis(Expr $expr, Context $context, StatementsSource $statements_source, Codebase $codebase, array &$file_replacements = [])
    {
        if(!$expr instanceof Expr\MethodCall || strval($expr->name) !== 'get') {
            return;
        }

        $contextVariableName = ExpressionIdentifier::getVarId($expr->var, null);
        if(!array_key_exists($contextVariableName, $context->vars_in_scope)) {
            throw new RuntimeException(sprintf('Type of %s can not be determined because it was not found in context.', $contextVariableName));
        }

        $types = array_keys($context->vars_in_scope[$contextVariableName]->getAtomicTypes());
        if(!in_array(HeaderBag::class, $types)) {
            return;
        }

        $headerName = strtolower($expr->args[0]->value->value);
        if($headerName !== 'user-agent') {
            return;
        }

        IssueBuffer::accepts(new TaintedInput('Detected tainted header', new CodeLocation($statements_source, $expr)));
    }
}
