<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Psalm\Internal\Analyzer\Statements\Expression\Call\MethodCallAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\SymfonyPsalmPlugin\Exception\TemplateNameUnresolvedException;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;
use Twig\Environment;

/**
 * This hook transforms a call to `Twig\Environment::render()` in a call to the actual twig compiled template `doDisplay()` method.
 */
final class CachedTemplatesTainter implements MethodReturnTypeProviderInterface
{
    /**
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Environment::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();
        $method_name_lowercase = $event->getMethodNameLowercase();
        $context = $event->getContext();
        $call_args = $event->getCallArgs();

        if (!$source instanceof StatementsAnalyzer) {
            throw new \RuntimeException(sprintf('The %s::%s hook can only be called using a %s.', __CLASS__, __METHOD__, StatementsAnalyzer::class));
        }

        if ('render' !== $method_name_lowercase) {
            return null;
        }

        $fake_method_call = new MethodCall(
            new Variable(
                '__fake_twig_env_var__'
            ),
            new Identifier(
                'doDisplay'
            ),
            isset($call_args[1]) ? [$call_args[1]] : []
        );

        if (!isset($call_args[0])) {
            return null;
        }

        try {
            $templateName = TwigUtils::extractTemplateNameFromExpression($call_args[0]->value, $source);
        } catch (TemplateNameUnresolvedException $exception) {
            if ($source instanceof StatementsAnalyzer) {
                $source->getProjectAnalyzer()->progress->debug($exception->getMessage());
            }

            return null;
        }

        $cacheClassName = CachedTemplatesMapping::getCacheClassName($templateName);

        $context->vars_in_scope['$__fake_twig_env_var__'] = new Union([
            new TNamedObject($cacheClassName),
        ]);

        MethodCallAnalyzer::analyze(
            $source,
            $fake_method_call,
            $context
        );

        self::addGeneratedOutputSink($source, $event, $fake_method_call);

        return null;
    }

    /**
     * A template compiled by Twig 3.9 or later yields its output instead of echoing it, so nothing outputs what the
     * generator returned by `doDisplay()` holds: this makes that the output.
     */
    private static function addGeneratedOutputSink(StatementsAnalyzer $source, MethodReturnTypeProviderEvent $event, MethodCall $fakeMethodCall): void
    {
        $taintFlowGraph = $source->taint_flow_graph;
        $generator = $source->node_data->getType($fakeMethodCall);
        if (null === $taintFlowGraph || null === $generator) {
            return;
        }

        $sink = DataFlowNode::getForCallableArg(
            'builtin',
            'twig_render',
            0,
            $event->getCodeLocation(),
            TaintKind::INPUT_HTML | TaintKind::INPUT_HAS_QUOTES
        );
        $taintFlowGraph->addSink($sink);

        foreach ($generator->parent_nodes as $parentNode) {
            $taintFlowGraph->addPath($parentNode, $sink, 'arrayvalue-fetch');
        }
    }
}
