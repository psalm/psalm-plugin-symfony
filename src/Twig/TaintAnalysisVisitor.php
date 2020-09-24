<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Twig\Environment;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\Node\SetNode;
use Twig\NodeVisitor\NodeVisitorInterface;

class TaintAnalysisVisitor implements NodeVisitorInterface
{
    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof PrintNode) {
            $analyzer = new PrintNodeAnalyzer($this->context);
            $analyzer->analyzePrintNode($node);
        }

        if ($node instanceof SetNode) {
            /** @var array<NameExpression> $names */
            $names = $node->getNode('names');
            /** @var array<AbstractExpression> $values */
            $values = $node->getNode('values')->getIterator();

            foreach ($names as $i => $name) {
                if (!isset($values[$i]) || !$values[$i] instanceof NameExpression) {
                    continue;
                }

                $this->context->taintAssignment($name, $values[$i]);
            }
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        return $node;
    }

    public function getPriority()
    {
        return 0;
    }
}
