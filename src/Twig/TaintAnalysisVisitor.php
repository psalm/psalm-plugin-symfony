<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Twig\Environment;
use Twig\Node\EmbedNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\AssignNameExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\ForNode;
use Twig\Node\IfNode;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\Node\SetNode;
use Twig\NodeTraverser;
use Twig\NodeVisitor\NodeVisitorInterface;

final class TaintAnalysisVisitor implements NodeVisitorInterface
{
    /** @var Context */
    private $context;

    /** @var PrintNodeAnalyzer */
    private $expressionAnalyzer;

    /** @var iterable<ModuleNode> the templates embedded in the template, which `embed` displays */
    private $embeddedTemplates = [];

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
        if ($node instanceof ModuleNode) {
            $this->analyzeModuleNode($node);
        }

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
                    $this->context->taintAssignmentFromSources(
                        $name,
                        $this->expressionAnalyzer->getTaintSources($values[$i]),
                        objectsOf: self::getVariable($values[$i]),
                    );
                }
            }
        }

        if ($node instanceof IfNode) {
            $this->context->enterConditional();
        }

        if ($node instanceof ForNode) {
            $this->analyzeForNode($node);
        }

        if ($node instanceof IncludeNode) {
            $this->analyzeIncludeNode($node, $env);
        }

        return $node;
    }

    #[\Override]
    public function leaveNode(Node $node, Environment $env): ?Node
    {
        if ($node instanceof IfNode) {
            $this->context->leaveConditional();
        }

        if ($node instanceof ForNode) {
            $this->context->leaveLoop();
        }

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

    /**
     * A template extending another one displays what the other one outputs, given its context.
     */
    private function analyzeModuleNode(ModuleNode $node): void
    {
        /** @var iterable<ModuleNode> */
        $this->embeddedTemplates = $node->getAttribute('embedded_templates') ?? [];

        $parent = $node->hasNode('parent') ? $node->getNode('parent') : null;
        if ($parent instanceof ConstantExpression && \is_string($parent->getAttribute('value'))) {
            $this->context->addOutput($this->context->includeTemplate(
                $node,
                $parent->getAttribute('value'),
                $this->expressionAnalyzer->getIncludedVariables(null),
                true,
            ));
        }
    }

    /**
     * The loop variables take the taints of the keys and of the values of what is looped over, in the loop only.
     */
    private function analyzeForNode(ForNode $node): void
    {
        $sequence = $node->getNode('seq');
        $sources = $this->expressionAnalyzer->getTaintSources($sequence);

        $targets = [];
        foreach (['key_target' => 'arraykey-fetch', 'value_target' => 'arrayvalue-fetch'] as $target => $pathType) {
            $variable = $node->getNode($target);
            if ($variable instanceof NameExpression) {
                $targets[] = [$variable, $pathType];
            }
        }

        $this->context->enterLoop(array_map(static fn (array $target): string => (string) $target[0]->getAttribute('name'), $targets));
        foreach ($targets as [$variable, $pathType]) {
            // the value target holds the objects of the items of a variable looped over
            $objectsOf = 'arrayvalue-fetch' === $pathType ? self::getVariable($sequence) : null;
            $this->context->taintAssignmentFromSources($variable, $sources, $pathType, true, $objectsOf, true);
        }
    }

    /**
     * The variable $value reads, if it is only that. Twig >= 3.15 gives the value of a single `set` as a list of it.
     */
    private static function getVariable(Node $value): ?NameExpression
    {
        if (!$value instanceof AbstractExpression && 1 === \count($value)) {
            $value = $value->getNode('0');
        }

        return $value instanceof NameExpression && !$value instanceof AssignNameExpression ? $value : null;
    }

    /**
     * `include` and `embed` display what the included template outputs, given the variables of `with` and unless
     * `only` the context of this template. The blocks an `embed` overrides are displayed with this context.
     */
    private function analyzeIncludeNode(IncludeNode $node, Environment $env): void
    {
        $embeddedTemplate = null;
        if ($node instanceof EmbedNode) {
            // the template embedded, which extends the template displayed and overrides its blocks
            foreach ($this->embeddedTemplates as $template) {
                if ($template->getAttribute('index') === $node->getAttribute('index')) {
                    $embeddedTemplate = $template;
                }
            }
            $template = $embeddedTemplate?->hasNode('parent') ? $embeddedTemplate->getNode('parent') : null;
        } else {
            $template = $node->getNode('expr');
        }

        if ($template instanceof ConstantExpression && \is_string($template->getAttribute('value'))) {
            $this->context->addOutput($this->context->includeTemplate(
                $node,
                $template->getAttribute('value'),
                $this->expressionAnalyzer->getIncludedVariables($node->hasNode('variables') ? $node->getNode('variables') : null),
                !$node->getAttribute('only'),
            ));
        }

        if (null !== $embeddedTemplate) {
            (new NodeTraverser($env, [$this]))->traverse($embeddedTemplate->getNode('blocks'));
        }
    }
}
