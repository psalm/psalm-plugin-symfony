<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Internal\DataFlow\DataFlowNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConditionalExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\ReturnBoolInterface;
use Twig\Node\Expression\ReturnNumberInterface;
use Twig\Node\Expression\Unary\SpreadUnary;
use Twig\Node\Expression\Variable\LocalVariable;
use Twig\Node\Node;
use Twig\Node\PrintNode;

/**
 * @psalm-import-type IncludedVariables from Context
 */
final class PrintNodeAnalyzer
{
    /** @var Context */
    private $context;

    /**
     * @psalm-capabilities read-props
     */
    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function analyzePrintNode(PrintNode $node): void
    {
        $expression = $node->getNode('expr');

        if (!$expression instanceof AbstractExpression) {
            throw new \RuntimeException('The expr node has an expected type.');
        }

        foreach ($this->getTaintSources($expression) as $source) {
            $this->context->addSink($node, $source);
        }
    }

    /**
     * The nodes the taints of the value of $expression come from: those of the variables it reads, through the
     * filters it applies. An escaped value has none.
     *
     * @return list<DataFlowNode>
     */
    public function getTaintSources(Node $expression): array
    {
        if ($expression instanceof FilterExpression) {
            if (self::isEscapingFilter($expression)) {
                return [];
            }

            // what a filter returns comes from what it filters and from its arguments (`format`, `replace`, ...)
            $sources = $this->getTaintSources($expression->getNode('node'));
            if ($expression->hasNode('arguments')) {
                $sources = [...$sources, ...$this->getTaintSources($expression->getNode('arguments'))];
            }

            return array_map(
                fn (DataFlowNode $source): DataFlowNode => $this->context->getTaintDestination($source, $expression),
                $sources,
            );
        }

        if ($expression instanceof FunctionExpression && 'include' === $expression->getAttribute('name')
            && null !== $includedOutput = $this->getIncludedOutput($expression)) {
            return [$includedOutput];
        }

        if ($expression instanceof FunctionExpression) {
            // what a function returns comes from its arguments, through what its PHP callable does with them
            $sources = [];
            foreach ($expression->getNode('arguments') as $argument) {
                $sources = [...$sources, ...$this->getTaintSources($argument)];
            }

            return array_map(
                fn (DataFlowNode $source): DataFlowNode => $this->context->getFunctionTaintDestination($source, $expression),
                $sources,
            );
        }

        if ($expression instanceof GetAttrExpression) {
            return $this->getAttributeTaintSources($expression);
        }

        if ($expression instanceof NameExpression) {
            return $this->context->taintVariable($expression);
        }

        if ($expression instanceof ConditionalExpression) {
            // the condition chooses the branch that is output, but isn't output itself
            return [
                ...$this->getTaintSources($expression->getNode('expr2')),
                ...$this->getTaintSources($expression->getNode('expr3')),
            ];
        }

        if ($expression instanceof ReturnBoolInterface || $expression instanceof ReturnNumberInterface) {
            // a comparison, a test or an arithmetic operation gives a boolean or a number, which holds no markup
            return [];
        }

        // anything else (an attribute, a call, an operator, ...) takes the taints of what it is made of
        $sources = [];
        foreach ($expression as $child) {
            $sources = [...$sources, ...$this->getTaintSources($child)];
        }

        return $sources;
    }

    /**
     * `item.name` and `item['name']` give the value of the key `name` of `item`, whose other keys can hold other taints:
     * like an array fetch in PHP. A method call gives the taints of `item` and of what the methods it may call return
     * (see Context::getMethodCallTaintDestination()). An attribute the analysis can't tell gives the taints of the
     * whole of `item`, and of its arguments.
     *
     * @return list<DataFlowNode>
     */
    private function getAttributeTaintSources(GetAttrExpression $expression): array
    {
        $sources = $this->getTaintSources($expression->getNode('node'));
        $attribute = $expression->getNode('attribute');

        // 'method' is the value of the internal Twig\Template::METHOD_CALL
        if ('method' !== $expression->getAttribute('type') && $attribute instanceof ConstantExpression) {
            $key = (string) $attribute->getAttribute('value');

            return array_map(
                fn (DataFlowNode $source): DataFlowNode => $this->context->getFetchDestination(
                    $source,
                    $expression,
                    $key,
                    "arrayvalue-fetch-'".$key."'",
                ),
                $sources,
            );
        }

        $arguments = $expression->hasNode('arguments') ? $expression->getNode('arguments') : null;
        $argumentSources = 'method' === $expression->getAttribute('type') && $attribute instanceof ConstantExpression
            ? $this->getArgumentTaintSources($arguments)
            : null;
        if (null !== $argumentSources) {
            // what the receiver holds stays, as a method of an object specialized by instance returns what it holds
            $call = $this->context->getMethodCallTaintDestination($expression, (string) $attribute->getAttribute('value'), $argumentSources);
            if (null !== $call) {
                return [...$sources, $call];
            }
        }

        if (null !== $arguments) {
            $sources = [...$sources, ...$this->getTaintSources($arguments)];
        }

        return $sources;
    }

    /**
     * The nodes of each argument of a method call, by position. Null if the analysis can't tell their positions.
     *
     * @return list<list<DataFlowNode>>|null
     */
    private function getArgumentTaintSources(?Node $arguments): ?array
    {
        if (null === $arguments) {
            return [];
        }

        if (!$arguments instanceof ArrayExpression) {
            return null;
        }

        $argumentSources = [];
        foreach ($arguments->getKeyValuePairs() as $position => ['key' => $key, 'value' => $value]) {
            // Twig >= 3.15 gives positions as local variables
            $keyValue = match (true) {
                $key instanceof LocalVariable => $key->getAttribute('name'),
                $key instanceof ConstantExpression => $key->getAttribute('value'),
                default => null,
            };
            if ($keyValue !== $position || $value instanceof SpreadUnary) {
                // a named argument, or arguments spread from an array
                return null;
            }

            $argumentSources[] = $this->getTaintSources($value);
        }

        return $argumentSources;
    }

    /**
     * The variables an include gives the included template from $variables, the expression of its `with`.
     *
     * @return IncludedVariables
     */
    public function getIncludedVariables(?Node $variables): array
    {
        $includedVariables = ['keyed' => [], 'unkeyed' => [], 'whole' => []];

        if (null === $variables) {
            return $includedVariables;
        }

        if (!$variables instanceof ArrayExpression) {
            // an array the analysis can't tell the keys of: its own keyed paths tell what is in which key
            $includedVariables['whole'] = $this->getTaintSources($variables);

            return $includedVariables;
        }

        foreach ($variables->getKeyValuePairs() as ['key' => $key, 'value' => $value]) {
            $sources = $this->getTaintSources($value);
            if ($key instanceof ConstantExpression) {
                $includedVariables['keyed'][(string) $key->getAttribute('value')] = $sources;
            } else {
                $includedVariables['unkeyed'] = [...$includedVariables['unkeyed'], ...$sources];
            }
        }

        return $includedVariables;
    }

    /**
     * `include('part.html.twig', variables, with_context)` displays what the included template outputs. Null if which
     * template is included is only known when rendering.
     */
    private function getIncludedOutput(FunctionExpression $expression): ?DataFlowNode
    {
        $template = Context::getArgument($expression, 0, 'template');
        if (!$template instanceof ConstantExpression || !\is_string($template->getAttribute('value'))) {
            return null;
        }

        $withContext = Context::getArgument($expression, 2, 'with_context');

        return $this->context->includeTemplate(
            $expression,
            $template->getAttribute('value'),
            $this->getIncludedVariables(Context::getArgument($expression, 1, 'variables')),
            !$withContext instanceof ConstantExpression || false !== $withContext->getAttribute('value'),
        );
    }

    private static function isEscapingFilter(FilterExpression $expression): bool
    {
        $filterName = Context::getFilterName($expression);

        return 'escape' === $filterName || 'e' === $filterName;
    }
}
