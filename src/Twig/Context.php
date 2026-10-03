<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Internal\MethodIdentifier;
use Psalm\Storage\FunctionLikeStorage;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Node\Expression\AssignNameExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
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

    /** @var Codebase|null */
    private $codebase;

    /** @var array<string, list<string>> template name => the variables it reads from its context */
    private static $contextVariables = [];

    /**
     * @psalm-capabilities read-props
     */
    public function __construct(Source $sourceContext, TaintFlowGraph $taint, Environment $twig, ?Codebase $codebase = null)
    {
        $this->sourceContext = $sourceContext;
        $this->taint = $taint;
        $this->twig = $twig;
        $this->codebase = $codebase;
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

        $removedTaints = 0;
        $filter = null;
        foreach ($this->twig->getExtensions() as $extension) {
            foreach ($extension->getFilters() as $extensionFilter) {
                // the last extension declaring a filter wins
                $filter = $extensionFilter->getName() === $filterName ? $extensionFilter : $filter;
            }
        }
        if (null !== $filter) {
            $removedTaints = $this->getCallableRemovedTaints($filter->getCallable());
        }

        $taintDestination = DataFlowNode::getForAssignment('filter_'.$filterName, $this->getNodeLocation($expression));

        $this->taint->addNode($taintDestination);
        $this->taint->addPath($taintSource, $taintDestination, 'arg', 0, $removedTaints);

        return $taintDestination;
    }

    /**
     * The node of what a function returns from $taintSource, one of its arguments: like a filter (see
     * getTaintDestination()), the function removes the taints its PHP callable removes.
     */
    public function getFunctionTaintDestination(DataFlowNode $taintSource, FunctionExpression $expression): DataFlowNode
    {
        /** @var string $functionName */
        $functionName = $expression->getAttribute('name');

        $removedTaints = 0;
        $function = null;
        foreach ($this->twig->getExtensions() as $extension) {
            foreach ($extension->getFunctions() as $extensionFunction) {
                // the last extension declaring a function wins
                $function = $extensionFunction->getName() === $functionName ? $extensionFunction : $function;
            }
        }
        if (null !== $function) {
            $removedTaints = $this->getCallableRemovedTaints($function->getCallable());
        }

        $taintDestination = DataFlowNode::getForAssignment('function_'.$functionName, $this->getNodeLocation($expression));

        $this->taint->addNode($taintDestination);
        $this->taint->addPath($taintSource, $taintDestination, 'arg', 0, $removedTaints);

        return $taintDestination;
    }

    /**
     * The node of what is fetched from $taintSource: a key of it, or what looping over it gives.
     */
    public function getFetchDestination(DataFlowNode $taintSource, Node $expression, string $label, string $pathType): DataFlowNode
    {
        $taintDestination = DataFlowNode::getForAssignment('fetch_'.$label, $this->getNodeLocation($expression));

        $this->taint->addNode($taintDestination);
        $this->taint->addPath($taintSource, $taintDestination, $pathType);

        return $taintDestination;
    }

    /**
     * The taints the PHP callable of a filter removes from what it is given: those its storage says
     * it escapes (`@psalm-taint-escape`), and those its native return type cannot hold.
     *
     * @param callable|array{0: class-string|object, 1: string}|string|null $callable
     */
    private function getCallableRemovedTaints($callable): int
    {
        $storage = null === $this->codebase ? null : self::getCallableStorage($this->codebase, $callable);
        if (null === $storage) {
            return 0;
        }

        return $storage->removed_taints | ($storage->signature_return_type?->getTaintsToRemove() ?? 0);
    }

    /**
     * @param callable|array{0: class-string|object, 1: string}|string|null $callable
     */
    private static function getCallableStorage(Codebase $codebase, $callable): ?FunctionLikeStorage
    {
        if ($callable instanceof \Closure) {
            // a first-class callable of a method or function, or else a closure the analysis can't find
            $reflection = new \ReflectionFunction($callable);
            $scope = $reflection->getClosureScopeClass();
            if (str_contains($reflection->getName(), '{closure')) {
                return null;
            }
            $callable = null === $scope ? $reflection->getName() : $scope->getName().'::'.$reflection->getName();
        } elseif (\is_array($callable)) {
            $class = \is_object($callable[0]) ? $callable[0]::class : $callable[0];
            $callable = $class.'::'.$callable[1];
        }

        if (!\is_string($callable) || '' === $callable) {
            return null;
        }

        if (str_contains($callable, '::')) {
            $declaringMethodId = $codebase->methodExists($callable) ? $codebase->getDeclaringMethodId($callable) : null;

            return null === $declaringMethodId ? null : $codebase->methods->getStorage(MethodIdentifier::wrap($declaringMethodId));
        }

        if (!\function_exists($callable)) {
            return null;
        }

        $file = (new \ReflectionFunction($callable))->getFileName();

        try {
            return false === $file
                ? $codebase->functions->getStorage(null, strtolower($callable))
                : $codebase->functions->getStorage(null, strtolower($callable), $file, $file);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Assigns $destinationVariable a value whose taints come from $sources, through a path of type $pathType.
     *
     * @param list<DataFlowNode> $sources
     */
    public function taintAssignmentFromSources(NameExpression $destinationVariable, array $sources, string $pathType = 'arg'): void
    {
        /** @var string $destinationName */
        $destinationName = $destinationVariable->getAttribute('name');
        $taintDestination = $this->addVariableTaintNode($destinationVariable);

        foreach ($sources as $source) {
            $this->taint->addPath($source, $taintDestination, $pathType);
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

        try {
            $tree = $this->twig->parse($this->twig->tokenize($this->twig->getLoader()->getSourceContext($templateName)));
        } catch (Error) {
            // a template the analysis cannot load or parse: its own analysis reports why
            return self::$contextVariables[$templateName] = [];
        }

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
