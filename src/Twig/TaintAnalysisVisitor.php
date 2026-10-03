<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\ForNode;
use Twig\Node\IncludeNode;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\Node\SetNode;
use Twig\NodeVisitor\NodeVisitorInterface;

final class TaintAnalysisVisitor implements NodeVisitorInterface
{
    /** @var Context */
    private $context;

    /** @var PrintNodeAnalyzer */
    private $expressionAnalyzer;

    /**
     * @psalm-capabilities read-props
     */
    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->expressionAnalyzer = new PrintNodeAnalyzer($context);
    }

    #[\Override]
    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof PrintNode) {
            $this->expressionAnalyzer->analyzePrintNode($node);
        }

        if ($node instanceof SetNode && !$node->getAttribute('capture')) {
            /** @var array<NameExpression> $names */
            $names = $node->getNode('names');
            // several names are given one value each, a single name the whole value
            $values = 1 < \count($names) ? iterator_to_array($node->getNode('values')) : [$node->getNode('values')];

            foreach ($names as $i => $name) {
                if (isset($values[$i])) {
                    $this->context->taintAssignmentFromSources($name, $this->expressionAnalyzer->getTaintSources($values[$i]));
                }
            }
        }

        if ($node instanceof ForNode) {
            // the loop variables take the taints of the keys and of the values of what is looped over
            $sources = $this->expressionAnalyzer->getTaintSources($node->getNode('seq'));
            foreach (['key_target' => 'arraykey-fetch', 'value_target' => 'arrayvalue-fetch'] as $target => $pathType) {
                $variable = $node->getNode($target);
                if ($variable instanceof NameExpression) {
                    $this->context->taintAssignmentFromSources($variable, $sources, $pathType);
                }
            }
        }

        if ($node instanceof IncludeNode) {
            $this->analyzeIncludeNode($node);
        }

        return $node;
    }

    /**
     * @psalm-pure
     */
    #[\Override]
    public function leaveNode(Node $node, Environment $env): ?Node
    {
        return $node;
    }

    /**
     * @psalm-pure
     */
    #[\Override]
    public function getPriority()
    {
        return 0;
    }

    private function analyzeIncludeNode(IncludeNode $node): void
    {
        $template = $node->getNode('expr');
        if (!$template instanceof ConstantExpression || !\is_string($template->getAttribute('value'))) {
            // which template is included is only known when rendering
            return;
        }

        $withVariables = [];
        if ($node->hasNode('variables')) {
            $variables = $node->getNode('variables');
            if (!$variables instanceof ArrayExpression) {
                return;
            }

            foreach ($variables->getKeyValuePairs() as ['key' => $key, 'value' => $value]) {
                if ($key instanceof ConstantExpression) {
                    $withVariables[(string) $key->getAttribute('value')] = $this->expressionAnalyzer->getTaintSources($value);
                }
            }
        }

        $this->context->taintInclude($node, $template->getAttribute('value'), $withVariables, (bool) $node->getAttribute('only'));
    }
}
