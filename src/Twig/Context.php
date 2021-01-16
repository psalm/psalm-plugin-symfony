<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\CodeLocation;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Type\TaintKind;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\Source;

class Context
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

    public function __construct(Source $sourceContext, TaintFlowGraph $taint)
    {
        $this->sourceContext = $sourceContext;
        $this->taint = $taint;
    }

    public function addSink(Node $node, DataFlowNode $source): void
    {
        $codeLocation = $this->getNodeLocation($node);

        $sinkName = 'twig_unknown';
        if ($node instanceof PrintNode) {
            $sinkName = 'twig_print';
        }

        $sink = DataFlowNode::getForMethodArgument(
            $sinkName, $sinkName, 0, null, $codeLocation
        );

        $sink->taints = [
            TaintKind::INPUT_HTML,
            TaintKind::USER_SECRET,
            TaintKind::SYSTEM_SECRET,
        ];

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
        $taintDestination = DataFlowNode::getForMethodReturn('filter_'.$filterName, 'filter_'.$filterName, $returnLocation, $returnLocation);

        $this->taint->addNode($taintDestination);
        $this->taint->addPath($taintSource, $taintDestination, 'arg');

        return $taintDestination;
    }

    public function taintAssignment(NameExpression $destinationVariable, NameExpression $sourceVariable): void
    {
        /** @var string $destinationName */
        $destinationName = $destinationVariable->getAttribute('name');
        $taintDestination = $this->addVariableTaintNode($destinationVariable);

        /** @var string $sourceName */
        $sourceName = $sourceVariable->getAttribute('name');
        $taintSource = $this->addVariableTaintNode($sourceVariable);

        $this->localVariables[$destinationName] = $taintDestination;
        $previousTaint = $this->addVariableUsage($sourceName, $taintSource);

        $this->taint->addPath($taintSource, $taintDestination, 'arg');

        if ($previousTaint !== $taintSource) {
            $this->taint->addPath($previousTaint, $taintSource, 'arg');
        }
    }

    public function taintUnassignedVariables(string $templateName): void
    {
        foreach ($this->unassignedVariables as $variableName => $taintable) {
            $label = strtolower($templateName).'#'.strtolower($variableName);
            $taintSource = new DataFlowNode($label, $label, null);

            $this->taint->addNode($taintSource);
            $this->taint->addPath($taintSource, $taintable, 'arg');
        }
    }

    public function taintSinks(string $templateName): void
    {
        $sink = new DataFlowNode($templateName, $templateName, null);
        $this->taint->addNode($sink);
        foreach ($this->parentNodes as $source) {
            $this->taint->addPath($source, $sink, 'return');
        }
    }

    private function addVariableTaintNode(NameExpression $variableNode): DataFlowNode
    {
        /** @var string $variableName */
        $variableName = $variableNode->getAttribute('name');
        $taintNode = DataFlowNode::getForAssignment($variableName, $this->getNodeLocation($variableNode));

        $this->taint->addNode($taintNode);

        return $taintNode;
    }

    private function addVariableUsage(string $variableName, DataFlowNode $variableTaint): DataFlowNode
    {
        if (!isset($this->localVariables[$variableName])) {
            return $this->unassignedVariables[$variableName] = $variableTaint;
        }

        return $this->localVariables[$variableName];
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
