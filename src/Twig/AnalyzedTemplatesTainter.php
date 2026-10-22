<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\StaticCall;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\SymfonyPsalmPlugin\Exception\TemplateNameUnresolvedException;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Union;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Twig\Environment;

/**
 * This hook adds paths from all taint sources going to a `Twig\Environment::render()` call, or a call of another
 * method rendering a template (see addRenderMethod()), to all taint sinks of the corresponding template.
 * The TemplateFileAnalyzer should be declared in configuration.
 */
final class AnalyzedTemplatesTainter implements AfterMethodCallAnalysisInterface, AfterExpressionAnalysisInterface
{
    /**
     * The methods rendering a template: those of the class, of the classes extending it and of the classes
     * implementing it, with the arguments naming the template and giving its variables.
     *
     * @var list<array{class: string, method: lowercase-string, template: int<0, max>, context: int<0, max>}>
     */
    private static array $renderMethods = [
        ['class' => Environment::class, 'method' => 'render', 'template' => 0, 'context' => 1],
        ['class' => AbstractController::class, 'method' => 'render', 'template' => 0, 'context' => 1],
        ['class' => AbstractController::class, 'method' => 'renderview', 'template' => 0, 'context' => 1],
    ];

    /**
     * The templates static calls render, until their returns are known (see afterExpressionAnalysis()).
     *
     * @var \WeakMap<StaticCall, string>|null
     */
    private static ?\WeakMap $staticCallTemplates = null;

    /**
     * Makes the calls of $class::$method, and of the methods overriding it, render the template named by their argument
     * $templateArgument with the variables of their argument $contextArgument (both counted from 0), and return what
     * it outputs.
     *
     * @param int<0, max> $templateArgument
     * @param int<0, max> $contextArgument
     */
    public static function addRenderMethod(string $class, string $method, int $templateArgument = 0, int $contextArgument = 1): void
    {
        self::$renderMethods[] = [
            'class' => ltrim($class, '\\'),
            'method' => strtolower($method),
            'template' => $templateArgument,
            'context' => $contextArgument,
        ];
    }

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $codebase = $event->getCodebase();
        $expr = $event->getExpr();
        $statements_source = $event->getStatementsSource();

        if (null === $codebase->taint_flow_graph) {
            return;
        }

        [$calledClass, $calledMethod] = explode('::', $event->getMethodId());
        $renderMethod = self::findRenderMethod($codebase, $calledClass, strtolower($calledMethod));
        $args = $expr->getArgs();
        if (null === $renderMethod || !isset($args[$renderMethod['template']], $args[$renderMethod['context']])) {
            return;
        }

        try {
            $templateName = TwigUtils::extractTemplateNameFromExpression($args[$renderMethod['template']]->value, $statements_source);
        } catch (TemplateNameUnresolvedException $exception) {
            if ($statements_source instanceof StatementsAnalyzer) {
                $statements_source->getProjectAnalyzer()->progress->debug($exception->getMessage());
            }

            return;
        }

        // Taints going _in_ the template: those of the parameters of this call, rather than of every call to the
        // method. Each variable of the template holds the parameter of its name.
        $parameters = $statements_source->getNodeTypeProvider()->getType($args[$renderMethod['context']]->value);
        if (null === $parameters) {
            return;
        }

        $parameterNames = [];
        $hasUnknownParameters = false;
        foreach ($parameters->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TKeyedArray) {
                $parameterNames = [...$parameterNames, ...array_map('strval', array_keys($atomic->properties))];
                $hasUnknownParameters = $hasUnknownParameters || null !== $atomic->fallback_params;
                foreach ($atomic->properties as $parameterName => $parameterType) {
                    Context::taintTemplateVariableAttributes($codebase, $templateName, (string) $parameterName, $parameterType);
                }
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

        // Taints going _out_ of the template. Psalm gives a static call the taints of its return after this event.
        if ($expr instanceof StaticCall) {
            self::$staticCallTemplates ??= new \WeakMap();
            self::$staticCallTemplates[$expr] = $templateName;

            return;
        }

        self::taintReturn($codebase, $templateName, $event->getReturnTypeCandidate());
    }

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        if ($expr instanceof StaticCall && null !== self::$staticCallTemplates && isset(self::$staticCallTemplates[$expr])) {
            $templateName = self::$staticCallTemplates[$expr];
            unset(self::$staticCallTemplates[$expr]);
            self::taintReturn($event->getCodebase(), $templateName, $event->getStatementsSource()->getNodeTypeProvider()->getType($expr));
        }

        return null;
    }

    /**
     * @param lowercase-string $calledMethod
     *
     * @return array{class: string, method: lowercase-string, template: int<0, max>, context: int<0, max>}|null
     */
    private static function findRenderMethod(Codebase $codebase, string $calledClass, string $calledMethod): ?array
    {
        foreach (self::$renderMethods as $renderMethod) {
            if ($renderMethod['method'] === $calledMethod
                && (
                    0 === strcasecmp($renderMethod['class'], $calledClass)
                    || $codebase->classExtendsOrImplements($calledClass, $renderMethod['class'])
                    || $codebase->interfaceExtends($calledClass, $renderMethod['class'])
                )
            ) {
                return $renderMethod;
            }
        }

        return null;
    }

    private static function taintReturn(Codebase $codebase, string $templateName, ?Union $return): void
    {
        if (null === $codebase->taint_flow_graph || null === $return) {
            return;
        }

        $source = Context::getForTemplate($templateName);
        foreach ($return->parent_nodes as $sink) {
            $codebase->taint_flow_graph->addPath($source, $sink, '=');
        }
    }
}
