<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Internal\DataFlow\DataFlowNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\PrintNode;

class PrintNodeAnalyzer
{
    /** @var Context */
    private $context;

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

        if ($this->expressionIsEscaped($expression)) {
            return;
        }

        $source = $this->getTaintSource($expression);
        if (null !== $source) {
            $this->context->addSink($node, $source);
        }
    }

    private function expressionIsEscaped(AbstractExpression $expression): bool
    {
        if ($expression instanceof FilterExpression && 'escape' === $expression->getNode('filter')->getAttribute('value')) {
            return true;
        }

        return false;
    }

    private function getTaintSource(AbstractExpression $expression): ?DataFlowNode
    {
        if ($expression instanceof FilterExpression) {
            /** @var AbstractExpression $filteredExpression */
            $filteredExpression = $expression->getNode('node');
            $taintSource = $this->getTaintSource($filteredExpression);
            if (null === $taintSource) {
                return null;
            }

            return $this->context->getTaintDestination($taintSource, $expression);
        }

        if ($expression instanceof NameExpression) {
            return $this->context->taintVariable($expression);
        }

        return null;
    }
}
