<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Tests\Fixture\Twig;

use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Plugin\EventHandler\AfterFunctionCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFunctionCallAnalysisEvent;
use Psalm\SymfonyPsalmPlugin\Twig\Context;
use Psalm\Type\TaintKind;

/**
 * An integration giving apart what a variable of a template holds and what it gives when printed:
 * render_view($template, $value, $printed) renders $template, whose variable `value` holds $value, and gives $printed
 * when printed, like an object whose __toString escapes what it holds.
 */
final class ChannelPlugin implements AfterFunctionCallAnalysisInterface
{
    #[\Override]
    public static function afterFunctionCallAnalysis(AfterFunctionCallAnalysisEvent $event): void
    {
        $graph = $event->getCodebase()->taint_flow_graph;
        $args = $event->getExpr()->getArgs();
        if (null === $graph || 'render_view' !== $event->getFunctionId() || !$args[0]->value instanceof String_) {
            return;
        }

        $template = $args[0]->value->value;
        $types = $event->getStatementsSource()->getNodeTypeProvider();
        foreach ([1 => Context::getForTemplateVariableObject($template, 'value'), 2 => Context::getForTemplateVariableString($template, 'value')] as $i => $variable) {
            $graph->addNode($variable);
            foreach ($types->getType($args[$i]->value)?->parent_nodes ?? [] as $parentNode) {
                $graph->addPath($parentNode, $variable, 'arg');
            }
        }

        // what the template outputs is rendered where render_view() is called
        $location = new CodeLocation($event->getStatementsSource(), $event->getExpr());
        $output = Context::getForTemplate($template);
        $rendered = DataFlowNode::getForAssignment('rendered view', $location);
        $sink = DataFlowNode::getForTaint('rendered view', $location, TaintKind::INPUT_HTML | TaintKind::INPUT_HAS_QUOTES);
        $graph->addNode($output);
        $graph->addNode($rendered);
        $graph->addSink($sink);
        $graph->addPath($output, $rendered, 'arg');
        $graph->addPath($rendered, $sink, 'arg');
    }
}
