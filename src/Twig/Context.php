<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\CodeLocation;
use Psalm\Internal\Codebase\Taint;
use Psalm\Internal\Taint\Sink;
use Psalm\Internal\Taint\Taintable;
use Psalm\Internal\Taint\TaintNode;
use Psalm\Type\TaintKind;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\Source;

class Context
{
    /** @var array<string, Taintable> */
    private $unassignedVariables = [];

    /** @var array<string, Taintable> */
    private $localVariables = [];

    /** @var Source */
    private $sourceContext;

    /** @var Taint */
    private $taint;

    public function __construct(Source $sourceContext, Taint $taint)
    {
        $this->sourceContext = $sourceContext;
        $this->taint = $taint;
    }

    public function addSink(Node $node, Taintable $source): void
    {
        $codeLocation = $this->getNodeLocation($node);

        $sinkName = 'twig_unknown';
        if ($node instanceof PrintNode) {
            $sinkName = 'twig_print';
        }

        $sink = Sink::getForMethodArgument(
            $sinkName, $sinkName, 0, null, $codeLocation
        );

        $sink->taints = [
            TaintKind::INPUT_HTML,
            TaintKind::USER_SECRET,
            TaintKind::SYSTEM_SECRET,
        ];

        $this->taint->addSink($sink);
        $this->taint->addPath($source, $sink, 'arg');
    }

    public function taintVariable(NameExpression $expression): Taintable
    {
        /** @var string $variableName */
        $variableName = $expression->getAttribute('name');

        $sinkNode = TaintNode::getForAssignment($variableName, $this->getNodeLocation($expression));

        $this->taint->addTaintNode($sinkNode);
        $sinkNode = $this->addVariableTaintNode($expression);

        return $this->addVariableUsage($variableName, $sinkNode);
    }

    public function getTaintDestination(Taintable $taintSource, FilterExpression $expression): TaintNode
    {
        /** @var string $filterName */
        $filterName = $expression->getNode('filter')->getAttribute('value');

        $returnLocation = $this->getNodeLocation($expression);
        $taintDestination = TaintNode::getForMethodReturn('filter_'.$filterName, 'filter_'.$filterName, $returnLocation, $returnLocation);

        $this->taint->addTaintNode($taintDestination);
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
            $taintSource = new TaintNode($label, $label, null, null);

            $this->taint->addTaintNode($taintSource);
            $this->taint->addPath($taintSource, $taintable, 'arg');
        }
    }

    private function addVariableTaintNode(NameExpression $variableNode): TaintNode
    {
        /** @var string $variableName */
        $variableName = $variableNode->getAttribute('name');
        $taintNode = TaintNode::getForAssignment($variableName, $this->getNodeLocation($variableNode));

        $this->taint->addTaintNode($taintNode);

        return $taintNode;
    }

    private function addVariableUsage(string $variableName, Taintable $variableTaint): Taintable
    {
        if (!isset($this->localVariables[$variableName])) {
            return $this->unassignedVariables[$variableName] = $variableTaint;
        }

        return $this->localVariables[$variableName];
    }

    private function getNodeLocation(Node $node): CodeLocation
    {
        $fileName = $this->sourceContext->getName();
        $filePath = $this->sourceContext->getPath();
        $snippet = $this->sourceContext->getCode(); // warning : the getCode method returns the whole template, not only the statement
        $fileCode = file_get_contents($filePath);
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
