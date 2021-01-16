<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Union;
use RuntimeException;
use SplObjectStorage;
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
            || !isset($expr->args[0]->value)
            || !isset($expr->args[1]->value)
        ) {
            return;
        }

        $templateName = TwigUtils::extractTemplateNameFromExpression($expr->args[0]->value, $statements_source);

        // Taints going _in_ the template
        $templateParameters = self::generateTemplateParameters($expr->args[1]->value, $statements_source);
        foreach ($templateParameters as $sourceNode) {
            $parameterName = $templateParameters[$sourceNode];
            $label = $argumentId = strtolower($templateName).'#'.strtolower($parameterName);
            $destinationNode = new DataFlowNode($argumentId, $label, null, null);

            $codebase->taint_flow_graph->addPath($sourceNode, $destinationNode, 'arg');
        }

        // Taints going _out_ of the template
        $source = new DataFlowNode($templateName, $templateName, null);
        if (null !== $return_type_candidate) {
            foreach ($return_type_candidate->parent_nodes as $sink) {
                $codebase->taint_flow_graph->addPath($source, $sink, '=');
            }
        }
    }

    /**
     * @return SplObjectStorage<DataFlowNode, string>
     */
    private static function generateTemplateParameters(Expr $templateParameters, StatementsSource $source): SplObjectStorage
    {
        $type = $source->getNodeTypeProvider()->getType($templateParameters);
        if (null === $type) {
            throw new RuntimeException(sprintf('Can not retrieve type for the given expression (%s)', get_class($templateParameters)));
        }

        if ($templateParameters instanceof Array_) {
            /** @var SplObjectStorage<DataFlowNode, string> $parameters */
            $parameters = new SplObjectStorage();
            foreach ($type->parent_nodes as $node) {
                if (preg_match('/array\[\'([a-zA-Z]+)\'\]/', $node->label, $matches)) {
                    $parameters[$node] = $matches[1];
                }
            }

            return $parameters;
        }

        if ($templateParameters instanceof Variable && array_key_exists('array', $type->getAtomicTypes())) {
            /** @var TKeyedArray $arrayValues */
            $arrayValues = $type->getAtomicTypes()['array'];

            /** @var SplObjectStorage<DataFlowNode, string> $parameters */
            $parameters = new SplObjectStorage();
            foreach ($arrayValues->properties as $parameterName => $parameterType) {
                foreach ($parameterType->parent_nodes as $node) {
                    $parameters[$node] = (string) $parameterName;
                }
            }

            return $parameters;
        }

        throw new RuntimeException(sprintf('Can not retrieve template parameters from given expression (%s)', get_class($templateParameters)));
    }
}
