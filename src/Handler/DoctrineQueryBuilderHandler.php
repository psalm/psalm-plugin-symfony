<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\SymfonyPsalmPlugin\Issue\QueryBuilderSetParameter;
use Psalm\Type\Union;

class DoctrineQueryBuilderHandler implements AfterMethodCallAnalysisInterface
{
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
    ): void {
        if ('Doctrine\ORM\QueryBuilder::setparameter' === $declaring_method_id) {
            if (isset($expr->args[2])) {
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
