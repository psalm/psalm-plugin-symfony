<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\CodeLocation;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\Internal\DataFlow\DataFlowNode;
use Twig\Environment;
use Twig\Node\Expression\AssignNameExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\Source;

final class Context
{
    /** @var array<string, DataFlowNode> */
    private $unassignedVariables = [];

    /** @var array<string, DataFlowNode> */
    private $localVariables = [];

    /** @var Source */
    private $sourceContext;

    /** @var TaintFlowGraph */
    private $taint;

    /** @var array<DataFlowNode> */
    private $parentNodes = [];

    /** @var Environment */
    private $twig;

    /** @var array<string, list<string>> template name => the variables it reads from its context */
    private static $contextVariables = [];

    /**
     * @psalm-capabilities read-props
     */
    public function __construct(Source $sourceContext, TaintFlowGraph $taint, Environment $twig)
    {
        $this->sourceContext = $sourceContext;
        $this->taint = $taint;
        $this->twig = $twig;
    }

    public function addSink(Node $node, DataFlowNode $source): void
    {
        $codeLocation = $this->getNodeLocation($node);

        $sinkName = 'twig_unknown';
        if ($node instanceof PrintNode) {
            $sinkName = 'twig_print';
        }

        $sink = DataFlowNode::getForAssignment($sinkName, $codeLocation);

        $this->taint->addNode($sink);
        $this->taint->addPath($source, $sink, 'arg');
        $this->parentNodes[] = $sink;
    }

    public function taintVariable(NameExpression $expression): DataFlowNode
    {
        /** @var string $variableName */
        $variableName = $expression->getAttribute('name');

        $sinkNode = DataFlowNode::getForAssignment($variableName, $this->getNodeLocation($expression));

        $this->taint->addNode($sinkNode);
        $sinkNode = $this->addVariableTaintNode($expression);

        return $this->addVariableUsage($variableName, $sinkNode);
    }

    public function getTaintDestination(DataFlowNode $taintSource, FilterExpression $expression): DataFlowNode
    {
        /** @var string $filterName */
        $filterName = $expression->getNode('filter')->getAttribute('value');

        $returnLocation = $this->getNodeLocation($expression);
        $taintDestination = DataFlowNode::getForAssignment('filter_'.$filterName, $returnLocation);

        $this->taint->addNode($taintDestination);
        $this->taint->addPath($taintSource, $taintDestination, 'arg');

        return $taintDestination;
    }

    /**
     * Assigns $destinationVariable a value whose taints come from $sources.
     *
     * @param list<DataFlowNode> $sources
     */
    public function taintAssignmentFromSources(NameExpression $destinationVariable, array $sources): void
    {
        /** @var string $destinationName */
        $destinationName = $destinationVariable->getAttribute('name');
        $taintDestination = $this->addVariableTaintNode($destinationVariable);

        foreach ($sources as $source) {
            $this->taint->addPath($source, $taintDestination, 'arg');
        }

        $this->localVariables[$destinationName] = $taintDestination;
    }

    /**
     * Makes the variables of an included template take the taints of what the include gives them: the variables
     * of `with`, and unless it is `only` the variables of the including template.
     *
     * @param array<string, list<DataFlowNode>> $withVariables
     */
    public function taintInclude(Node $includeNode, string $includedTemplateName, array $withVariables, bool $only): void
    {
        $location = $this->getNodeLocation($includeNode);

        // what the included template outputs is part of what this one outputs
        $includedOutput = self::getForTemplate($includedTemplateName);
        $this->taint->addNode($includedOutput);
        $this->parentNodes[] = $includedOutput;

        foreach ($withVariables as $variableName => $sources) {
            $destination = self::getForTemplateVariable(strtolower($includedTemplateName).'#'.strtolower($variableName));
            $this->taint->addNode($destination);
            foreach ($sources as $source) {
                $this->taint->addPath($source, $destination, 'arg');
            }
        }

        if ($only) {
            return;
        }

        foreach ($this->getContextVariables($includedTemplateName) as $variableName) {
            if (isset($withVariables[$variableName])) {
                continue;
            }

            $usage = DataFlowNode::getForAssignment($variableName, $location);
            $this->taint->addNode($usage);
            $source = $this->addVariableUsage($variableName, $usage);

            $destination = self::getForTemplateVariable(strtolower($includedTemplateName).'#'.strtolower($variableName));
            $this->taint->addNode($destination);
            $this->taint->addPath($source, $destination, 'arg');
        }
    }

    public function taintUnassignedVariables(string $templateName): void
    {
        foreach ($this->unassignedVariables as $variableName => $taintable) {
            $label = strtolower($templateName).'#'.strtolower($variableName);
            $taintSource = self::getForTemplateVariable($label);

            $this->taint->addNode($taintSource);
            $this->taint->addPath($taintSource, $taintable, 'arg');
        }
    }

    public function taintSinks(string $templateName): void
    {
        $sink = self::getForTemplate($templateName);
        $this->taint->addNode($sink);
        foreach ($this->parentNodes as $source) {
            $this->taint->addPath($source, $sink, 'return');
        }
    }

    /**
     * The node of what the template outputs, which taints flow out of the template through.
     *
     * @psalm-pure
     */
    public static function getForTemplate(string $templateName): DataFlowNode
    {
        return DataFlowNode::getForPropertyFetch($templateName);
    }

    /**
     * The node of a variable of the template (`<template name>#<variable name>`, lowercased), which taints
     * flow into the template through.
     *
     * @psalm-pure
     */
    public static function getForTemplateVariable(string $label): DataFlowNode
    {
        return DataFlowNode::getForPropertyFetch($label);
    }

    private function addVariableTaintNode(NameExpression $variableNode): DataFlowNode
    {
        /** @var string $variableName */
        $variableName = $variableNode->getAttribute('name');
        $taintNode = DataFlowNode::getForAssignment($variableName, $this->getNodeLocation($variableNode));

        $this->taint->addNode($taintNode);

        return $taintNode;
    }

    /**
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    private function addVariableUsage(string $variableName, DataFlowNode $variableTaint): DataFlowNode
    {
        if (!isset($this->localVariables[$variableName])) {
            // every use of a variable of the template goes through the node of its first use
            return $this->unassignedVariables[$variableName] ??= $variableTaint;
        }

        return $this->localVariables[$variableName];
    }

    /**
     * The variables a template reads from the context it is rendered with.
     *
     * @return list<string>
     */
    private function getContextVariables(string $templateName): array
    {
        if (isset(self::$contextVariables[$templateName])) {
            return self::$contextVariables[$templateName];
        }

        $tree = $this->twig->parse($this->twig->tokenize($this->twig->getLoader()->getSourceContext($templateName)));

        $assigned = [];
        $read = [];
        $collect = static function (Node $node) use (&$collect, &$assigned, &$read): void {
            if ($node instanceof AssignNameExpression) {
                $assigned[(string) $node->getAttribute('name')] = true;
            } elseif ($node instanceof NameExpression) {
                $read[(string) $node->getAttribute('name')] = true;
            }

            foreach ($node as $child) {
                $collect($child);
            }
        };
        $collect($tree);

        return self::$contextVariables[$templateName] = array_keys(array_diff_key($read, $assigned));
    }

    private function getNodeLocation(Node $node): CodeLocation
    {
        /** @psalm-var string $fileName */
        $fileName = $this->sourceContext->getName();
        $filePath = $this->sourceContext->getPath();
        $snippet = $this->sourceContext->getCode(); // warning : the getCode method returns the whole template, not only the statement
        $fileCode = file_get_contents($filePath);
        /** @psalm-var int $lineNumber */
        $lineNumber = $node->getTemplateLine();
        $lines = explode("\n", $fileCode);

        $file_start = 0;

        for ($i = 0; $i < $lineNumber - 1; ++$i) {
            $file_start += strlen($lines[$i]) + 1;
        }

        $file_start += (int) strpos($lines[$lineNumber - 1], $snippet);
        $file_end = $file_start + strlen($snippet);

        return new CodeLocation\Raw(
            $fileCode,
            $filePath,
            $fileName,
            $file_start,
            max($file_end, strlen($fileCode))
        );
    }
}
