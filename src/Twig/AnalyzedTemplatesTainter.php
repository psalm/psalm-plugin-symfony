<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\SymfonyPsalmPlugin\Exception\TemplateNameUnresolvedException;
use Psalm\Type\Atomic\TKeyedArray;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Twig\Environment;

/**
 * This hook adds paths from all taint sources going to a `Twig\Environment::render()` call to all taint sinks of the corresponding template.
 * The TemplateFileAnalyzer should be declared in configuration.
 */
class AnalyzedTemplatesTainter implements AfterMethodCallAnalysisInterface
{
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $codebase = $event->getCodebase();
        $expr = $event->getExpr();
        $method_id = $event->getMethodId();
        $statements_source = $event->getStatementsSource();

        if (
            null === $codebase->taint_flow_graph
            || !$expr instanceof MethodCall || !\in_array($method_id, [Environment::class.'::render', AbstractController::class.'::render', AbstractController::class.'::renderView'], true) || empty($expr->args)
            || !isset($expr->args[0]->value)
            || !isset($expr->args[1]->value)
        ) {
            return;
        }

        try {
            $templateName = TwigUtils::extractTemplateNameFromExpression($expr->args[0]->value, $statements_source);
        } catch (TemplateNameUnresolvedException $exception) {
            if ($statements_source instanceof StatementsAnalyzer) {
                $statements_source->getProjectAnalyzer()->progress->debug($exception->getMessage());
            }

            return;
        }

        // Taints going _in_ the template
        $methodNode = DataFlowNode::getForMethodArgument(
            $method_id,
            $method_id,
            1,
            new CodeLocation($statements_source, $expr->args[1]),
            new CodeLocation($statements_source, $expr->name)
        );

        $templateParameters = self::generateTemplateParameters($expr->args[1]->value, $statements_source);
        foreach ($templateParameters as $parameterName) {
            $label = $argumentId = strtolower($templateName).'#'.strtolower($parameterName);
            $destinationNode = new DataFlowNode($argumentId, $label, null, null);

            $codebase->taint_flow_graph->addPath($methodNode, $destinationNode, 'arg');
        }

        // Taints going _out_ of the template
        $source = new DataFlowNode($templateName, $templateName, null);
        $return_type_candidate = $event->getReturnTypeCandidate();
        if (null !== $return_type_candidate) {
            foreach ($return_type_candidate->parent_nodes as $sink) {
                $codebase->taint_flow_graph->addPath($source, $sink, '=');
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function generateTemplateParameters(Expr $templateParameters, StatementsSource $source): array
    {
        $type = $source->getNodeTypeProvider()->getType($templateParameters);
        if (null === $type) {
            throw new \RuntimeException(sprintf('Can not retrieve type for the given expression (%s)', get_class($templateParameters)));
        }

        if ($templateParameters instanceof Array_) {
            $parameters = [];
            foreach ($type->parent_nodes as $node) {
                if (preg_match('/array\[\'([a-zA-Z]+)\'\]/', $node->label, $matches)) {
                    $parameters[] = $matches[1];
                }
            }

            return $parameters;
        }

        if ($templateParameters instanceof Variable && array_key_exists('array', $type->getAtomicTypes())) {
            /** @var TKeyedArray $arrayValues */
            $arrayValues = $type->getAtomicTypes()['array'];

            $parameters = [];
            foreach (array_keys($arrayValues->properties) as $parameterName) {
                $parameters[] = (string) $parameterName;
            }

            return $parameters;
        }

        throw new \RuntimeException(sprintf('Can not retrieve template parameters from given expression (%s)', get_class($templateParameters)));
    }
}
