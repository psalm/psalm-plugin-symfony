<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\StaticCall;
use Psalm\CodeLocation;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\StatementsSource;
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
     * implementing it, with the arguments naming the template, giving its variables and, if any, naming the directory
     * the template is named relative to.
     *
     * @var list<array{class: string, method: lowercase-string, template: int<0, max>, context: int<0, max>, directory: int<0, max>|null}>
     */
    private static array $renderMethods = [
        ['class' => Environment::class, 'method' => 'render', 'template' => 0, 'context' => 1, 'directory' => null],
        ['class' => AbstractController::class, 'method' => 'render', 'template' => 0, 'context' => 1, 'directory' => null],
        ['class' => AbstractController::class, 'method' => 'renderview', 'template' => 0, 'context' => 1, 'directory' => null],
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
     * it outputs. With $templateDirectoryArgument, the template is named relative to the directory that argument names
     * (an absolute path, like `__DIR__`), not to the template root.
     *
     * @param int<0, max>      $templateArgument
     * @param int<0, max>      $contextArgument
     * @param int<0, max>|null $templateDirectoryArgument
     */
    public static function addRenderMethod(string $class, string $method, int $templateArgument = 0, int $contextArgument = 1, ?int $templateDirectoryArgument = null): void
    {
        self::$renderMethods[] = [
            'class' => ltrim($class, '\\'),
            'method' => strtolower($method),
            'template' => $templateArgument,
            'context' => $contextArgument,
            'directory' => $templateDirectoryArgument,
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
        if (null === $renderMethod
            || !isset($args[$renderMethod['template']], $args[$renderMethod['context']])
            || (null !== $renderMethod['directory'] && !isset($args[$renderMethod['directory']]))
        ) {
            return;
        }

        try {
            $templateName = null === $renderMethod['directory']
                ? TwigUtils::extractTemplateNameFromExpression($args[$renderMethod['template']]->value, $statements_source)
                : TwigUtils::extractTemplateNameInDirectory(
                    $args[$renderMethod['directory']]->value,
                    $args[$renderMethod['template']]->value,
                    $statements_source,
                    $codebase,
                );
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
            if (null === self::$staticCallTemplates) {
                /** @var \WeakMap<StaticCall, string> */
                $staticCallTemplates = new \WeakMap();
                self::$staticCallTemplates = $staticCallTemplates;
            }

            self::$staticCallTemplates[$expr] = $templateName;

            return;
        }

        $return = self::taintReturn($codebase, $statements_source, $expr, $templateName, $event->getReturnTypeCandidate());
        if (null !== $return) {
            $event->setReturnTypeCandidate($return);
        }
    }

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        if (!$expr instanceof StaticCall || null === self::$staticCallTemplates) {
            return null;
        }

        $templateName = self::$staticCallTemplates[$expr] ?? null;
        if (null === $templateName) {
            return null;
        }

        unset(self::$staticCallTemplates[$expr]);
        $statementsSource = $event->getStatementsSource();
        $nodeTypeProvider = $statementsSource->getNodeTypeProvider();
        $return = self::taintReturn($event->getCodebase(), $statementsSource, $expr, $templateName, $nodeTypeProvider->getType($expr));
        if (null !== $return) {
            $nodeTypeProvider->setType($expr, $return);
        }

        return null;
    }

    /**
     * @param lowercase-string $calledMethod
     *
     * @return array{class: string, method: lowercase-string, template: int<0, max>, context: int<0, max>, directory: int<0, max>|null}|null
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

    /**
     * The type of what the render call $call returns, $return, coming from a node of the call: the output of the
     * template it renders flows into it, and what its method returns. The return node of a method whose calls Psalm
     * doesn't specialize is shared by all its calls: the output of the template should not reach the other calls.
     */
    private static function taintReturn(Codebase $codebase, StatementsSource $statements_source, Expr $call, string $templateName, ?Union $return): ?Union
    {
        if (null === $return || !$codebase->tracksTaintFlow($statements_source)) {
            return null;
        }

        $output = $codebase->getTaintNode($statements_source, 'render '.$templateName, new CodeLocation($statements_source, $call), $return);
        $codebase->addTaintPath($statements_source, Context::getForTemplate($templateName), $output);

        return $return->setParentNodes([$output]);
    }
}
