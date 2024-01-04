<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\SymfonyPsalmPlugin\Issue\QueryBuilderSetParameter;

class DoctrineQueryBuilderHandler implements AfterMethodCallAnalysisInterface
{
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $expr = $event->getExpr();
        $declaring_method_id = $event->getDeclaringMethodId();
        $statements_source = $event->getStatementsSource();
        $context = $event->getContext();

        if ('Doctrine\ORM\QueryBuilder::setparameter' === $declaring_method_id) {
            if (isset($expr->args[2])) {
                return;
            }

            if (!isset($expr->args[1]->value)) {
                return;
            }
            $value = $expr->args[1]->value;

            if (self::isValueObject($value, $context)) {
                IssueBuffer::accepts(
                    new QueryBuilderSetParameter(new CodeLocation($statements_source, $value)),
                    $statements_source->getSuppressedIssues()
                );
            }
        }
    }

    private static function isValueObject(Expr $value, Context $context): bool
    {
        if ($value instanceof Expr\Variable) {
            $varName = $value->name;
            if (is_string($varName)) {
                $varName = '$'.$varName;
                if ($context->hasVariable($varName)) {
                    $type = $context->vars_in_scope[$varName];

                    return $type->hasObjectType();
                }
            }
        }

        if ($value instanceof Expr\New_) {
            return true;
        }

        return false;
    }
}
