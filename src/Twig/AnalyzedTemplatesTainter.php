<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Type\Union;
use Twig\Environment;

/**
 * This hook adds paths from all taint sources going to a `Twig\Environment::render()` call to all taint sinks of the corresponding template.
 * The TemplateFileAnalyzer should be declared in configuration.
 */
class AnalyzedTemplatesTainter implements AfterMethodCallAnalysisInterface
{
    public static function afterMethodCallAnalysis(Expr $expr, string $method_id, string $appearing_method_id, string $declaring_method_id, Context $context, StatementsSource $statements_source, Codebase $codebase, array &$file_replacements = [], Union &$return_type_candidate = null): void
    {
        if (
            null === $codebase->taint_flow_graph
            || !$expr instanceof MethodCall || $method_id !== Environment::class.'::render' || empty($expr->args)
            || !isset($expr->args[0]->value) || !$expr->args[0]->value instanceof String_
            || !isset($expr->args[1]->value) || !$expr->args[1]->value instanceof Array_
        ) {
            return;
        }

        $template_name = $expr->args[0]->value->value;
        $twig_arguments_type = $statements_source->getNodeTypeProvider()->getType($expr->args[1]->value);

        if (null === $twig_arguments_type) {
            return;
        }

        foreach ($twig_arguments_type->parent_nodes as $source_taint) {
            preg_match('/array\[\'([a-zA-Z]+)\'\]/', $source_taint->label, $matches);
            $sink_taint = TemplateFileAnalyzer::getTaintNodeForTwigNamedVariable(
                $template_name, $matches[1]
            );
            $codebase->taint_flow_graph->addPath($source_taint, $sink_taint, 'arg');
        }
    }
}
