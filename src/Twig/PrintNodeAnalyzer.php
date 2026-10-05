<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Internal\DataFlow\DataFlowNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ConditionalExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\ReturnBoolInterface;
use Twig\Node\Expression\ReturnNumberInterface;
use Twig\Node\Node;
use Twig\Node\PrintNode;

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

            return array_map(
                fn (DataFlowNode $source): DataFlowNode => $this->context->getTaintDestination($source, $expression),
                $this->getTaintSources($expression->getNode('node')),
            );
        }

        if ($expression instanceof NameExpression) {
            return [$this->context->taintVariable($expression)];
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

    private static function isEscapingFilter(FilterExpression $expression): bool
    {
        $filterName = $expression->getNode('filter')->getAttribute('value');

        return 'escape' === $filterName || 'e' === $filterName;
    }
}
