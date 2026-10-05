<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use PhpParser\Node\Expr\MethodCall;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\SymfonyPsalmPlugin\Exception\TemplateNameUnresolvedException;
use Psalm\Type\Atomic\TKeyedArray;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Twig\Environment;

/**
 * This hook adds paths from all taint sources going to a `Twig\Environment::render()` call to all taint sinks of the corresponding template.
 * The TemplateFileAnalyzer should be declared in configuration.
 */
final class AnalyzedTemplatesTainter implements AfterMethodCallAnalysisInterface
{
    #[\Override]
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

        // Taints going _in_ the template: those of the parameters of this call, rather than of every call to the
        // method. Each variable of the template holds the parameter of its name.
        $parameters = $statements_source->getNodeTypeProvider()->getType($expr->args[1]->value);
        if (null === $parameters) {
            return;
        }

        $parameterNames = [];
        $hasUnknownParameters = false;
        foreach ($parameters->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TKeyedArray) {
                $parameterNames = [...$parameterNames, ...array_map('strval', array_keys($atomic->properties))];
                $hasUnknownParameters = $hasUnknownParameters || null !== $atomic->fallback_params;
            } else {
                $hasUnknownParameters = true;
            }
        }

        foreach ($parameters->parent_nodes as $parameterNode) {
            foreach (array_unique($parameterNames) as $parameterName) {
                $codebase->taint_flow_graph->addPath(
                    $parameterNode,
                    Context::getForTemplateVariable($templateName, $parameterName),
                    "arrayvalue-fetch-'".$parameterName."'",
                );
            }

            if ($hasUnknownParameters) {
                $codebase->taint_flow_graph->addPath($parameterNode, Context::getForTemplateContext($templateName), '=');
            }
        }

        // Taints going _out_ of the template
        $source = Context::getForTemplate($templateName);
        $return_type_candidate = $event->getReturnTypeCandidate();
        if (null !== $return_type_candidate) {
            foreach ($return_type_candidate->parent_nodes as $sink) {
                $codebase->taint_flow_graph->addPath($source, $sink, '=');
            }
        }
    }
}
