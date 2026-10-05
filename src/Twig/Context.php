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
use Twig\Node\EmbedNode;
use Twig\Node\Expression\AssignNameExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @psalm-type IncludedVariables = array{
 *     keyed: array<string, list<DataFlowNode>>,
 *     unkeyed: list<DataFlowNode>,
 *     whole: list<DataFlowNode>,
 * } the variables an include gives: the values of the keys of `with` by name, those of its keys the analysis can't
 *   tell, and the arrays it can't tell the keys of
 */
final class Context
{
    /** @var array<string, DataFlowNode> the node of the first read of each variable of the context of the template */
    private $unassignedVariables = [];

    /** @var array<string, non-empty-list<DataFlowNode>> the nodes of the values a variable set by the template can have */
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

    /** @var int how many `if` and `for` the analysis is in, whose body may not run */
    private $conditionalDepth = 0;

    /** @var list<array<string, non-empty-list<DataFlowNode>|null>> the values the targets of the loops the analysis is in had before them */
    private $loopTargets = [];

    /** @var array<string, DataFlowNode> the nodes of the template, by Twig node and label */
    private $nodes = [];

    /** @var array<string, int> how many nodes of the template have a label, by line */
    private $labelCounts = [];

    /** @var list<int>|null the offset at which each line of the template starts */
    private $lineOffsets;

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
        $sinkName = 'twig_unknown';
        if ($node instanceof PrintNode) {
            $sinkName = 'twig_print';
        }

        $sink = $this->getNode($node, $sinkName);

        $this->taint->addPath($source, $sink, 'arg');
        $this->parentNodes[] = $sink;
    }

    /**
     * Makes $output, a node whose taints are displayed, part of what the template outputs.
     *
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    public function addOutput(DataFlowNode $output): void
    {
        $this->parentNodes[] = $output;
    }

    /**
     * The nodes of the values the variable read by $expression can have.
     *
     * @return non-empty-list<DataFlowNode>
     */
    public function taintVariable(NameExpression $expression): array
    {
        /** @var string $variableName */
        $variableName = $expression->getAttribute('name');

        return $this->localVariables[$variableName] ?? [$this->getContextVariable($variableName, $expression)];
    }

    public function getTaintDestination(DataFlowNode $taintSource, FilterExpression $expression): DataFlowNode
    {
        $filter = $this->getTwigCallable($expression);
        $taintDestination = $this->getNode($expression, 'filter_'.self::getFilterName($expression));

        $this->taint->addPath($taintSource, $taintDestination, 'arg', 0, $this->getCallableRemovedTaints($filter?->getCallable()));

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
        $function = $this->getTwigCallable($expression);
        $taintDestination = $this->getNode($expression, 'function_'.$functionName);

        $this->taint->addPath($taintSource, $taintDestination, 'arg', 0, $this->getCallableRemovedTaints($function?->getCallable()));

        return $taintDestination;
    }

    /**
     * The node of what is fetched from $taintSource: a key of it, or what looping over it gives.
     */
    public function getFetchDestination(DataFlowNode $taintSource, Node $expression, string $label, string $pathType): DataFlowNode
    {
        $taintDestination = $this->getNode($expression, 'fetch_'.$label);

        $this->taint->addPath($taintSource, $taintDestination, $pathType);

        return $taintDestination;
    }

    /**
     * The name of the filter $expression applies.
     */
    public static function getFilterName(FilterExpression $expression): string
    {
        $filter = $expression->getAttribute('twig_callable');
        if ($filter instanceof TwigFilter) {
            return $filter->getName();
        }

        // Twig < 3.12 doesn't give the filter
        return (string) $expression->getNode('filter')->getAttribute('value');
    }

    /**
     * The Twig filter or function $expression calls, as Twig resolves it: also when its name matches a pattern.
     */
    private function getTwigCallable(FilterExpression|FunctionExpression $expression): TwigFilter|TwigFunction|null
    {
        if ($expression->hasAttribute('twig_callable')) {
            /** @var TwigFilter|TwigFunction */
            return $expression->getAttribute('twig_callable');
        }

        // Twig < 3.12 leaves it to the compiler
        if ($expression instanceof FilterExpression) {
            /** @psalm-suppress InternalMethod */
            return $this->twig->getFilter(self::getFilterName($expression));
        }

        /** @psalm-suppress InternalMethod */
        return $this->twig->getFunction((string) $expression->getAttribute('name'));
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
     * Assigns $destinationVariable a value whose taints come from $sources, through a path of type $pathType. In the
     * body of an `if` or of a `for`, which may not run, the variable can also keep the value it had, unless $always.
     *
     * @param list<DataFlowNode> $sources
     */
    public function taintAssignmentFromSources(NameExpression $destinationVariable, array $sources, string $pathType = 'arg', bool $always = false): void
    {
        /** @var string $destinationName */
        $destinationName = $destinationVariable->getAttribute('name');
        $taintDestination = $this->getNode($destinationVariable, $destinationName);

        foreach ($sources as $source) {
            $this->taint->addPath($source, $taintDestination, $pathType);
        }

        $this->localVariables[$destinationName] = 0 < $this->conditionalDepth && !$always
            ? [...$this->taintVariable($destinationVariable), $taintDestination]
            : [$taintDestination];
    }

    /**
     * Enters the body of an `if`, which may not run.
     *
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    public function enterConditional(): void
    {
        ++$this->conditionalDepth;
    }

    /**
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    public function leaveConditional(): void
    {
        --$this->conditionalDepth;
    }

    /**
     * Enters the body of a `for` whose targets are $targetNames: like Twig, the analysis gives them back their value
     * when leaving it (see leaveLoop()).
     *
     * @param list<string> $targetNames
     *
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    public function enterLoop(array $targetNames): void
    {
        $values = [];
        foreach ($targetNames as $targetName) {
            $values[$targetName] = $this->localVariables[$targetName] ?? null;
        }

        $this->loopTargets[] = $values;
        $this->enterConditional();
    }

    /**
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    public function leaveLoop(): void
    {
        $this->leaveConditional();

        foreach (array_pop($this->loopTargets) ?? [] as $targetName => $value) {
            if (null === $value) {
                unset($this->localVariables[$targetName]);
            } else {
                $this->localVariables[$targetName] = $value;
            }
        }
    }

    /**
     * Includes the template $includedTemplateName, giving each variable it reads from its context (see
     * getContextVariables()) what $variables gives it, and if $withContext the variable of its name of this template.
     *
     * @param IncludedVariables $variables
     *
     * @return DataFlowNode the node of what the included template outputs
     */
    public function includeTemplate(Node $includeNode, string $includedTemplateName, array $variables, bool $withContext): DataFlowNode
    {
        foreach ($this->getContextVariables($includedTemplateName) as $variableName) {
            $destination = self::getForTemplateVariable($includedTemplateName, $variableName);
            $this->taint->addNode($destination);

            foreach ([...$variables['keyed'][$variableName] ?? [], ...$variables['unkeyed']] as $source) {
                $this->taint->addPath($source, $destination, 'arg');
            }

            foreach ($variables['whole'] as $source) {
                $this->taint->addPath($source, $destination, "arrayvalue-fetch-'".$variableName."'");
            }

            if ($withContext && !isset($variables['keyed'][$variableName])) {
                $values = $this->localVariables[$variableName] ?? [$this->getContextVariable($variableName, $includeNode)];
                foreach ($values as $source) {
                    $this->taint->addPath($source, $destination, 'arg');
                }
            }
        }

        $includedOutput = self::getForTemplate($includedTemplateName);
        $this->taint->addNode($includedOutput);

        return $includedOutput;
    }

    /**
     * Makes the variables the template reads from its context take the taints of the variables of their name given to
     * it, and of the keys of their name of the contexts the analysis can't tell the keys of.
     */
    public function taintUnassignedVariables(string $templateName): void
    {
        $context = self::getForTemplateContext($templateName);
        $this->taint->addNode($context);

        foreach ($this->unassignedVariables as $variableName => $taintable) {
            $variable = self::getForTemplateVariable($templateName, $variableName);
            $this->taint->addNode($variable);
            $this->taint->addPath($context, $variable, "arrayvalue-fetch-'".$variableName."'");
            $this->taint->addPath($variable, $taintable, 'arg');
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
     * The node of a variable of the context of the template, which taints flow into the template through.
     *
     * Each variable has its own node, rather than taking the key of its name of a node of the whole context: Psalm
     * follows a single flow of given taints through a node, so the flows of several keys would leave by one of them.
     *
     * @psalm-pure
     */
    public static function getForTemplateVariable(string $templateName, string $variableName): DataFlowNode
    {
        return DataFlowNode::getForPropertyFetch(strtolower($templateName).'#'.$variableName);
    }

    /**
     * The node of a context the template is rendered with whose keys the analysis can't tell (Twig's `_context`),
     * whose key of the name of each variable of the template is that variable.
     *
     * @psalm-pure
     */
    public static function getForTemplateContext(string $templateName): DataFlowNode
    {
        return DataFlowNode::getForPropertyFetch(strtolower($templateName).'#_context');
    }

    /**
     * The variables a template reads from the context it is rendered with: those it reads, and those the templates it
     * gives its context to read (by `include`, `embed`, `extends` or `include()`). A variable it also sets is one of
     * them, as it may read it before.
     *
     * @return list<string>
     */
    private function getContextVariables(string $templateName): array
    {
        if (isset(self::$contextVariables[$templateName])) {
            return self::$contextVariables[$templateName];
        }

        // a template including itself reads no other variables
        self::$contextVariables[$templateName] = [];

        try {
            $tree = $this->twig->parse($this->twig->tokenize($this->twig->getLoader()->getSourceContext($templateName)));
        } catch (Error) {
            // a template the analysis cannot load or parse: its own analysis reports why
            return [];
        }

        $read = [];
        $collect = function (Node $node) use (&$collect, &$read): void {
            if ($node instanceof NameExpression && !$node instanceof AssignNameExpression) {
                $read[(string) $node->getAttribute('name')] = true;
            }

            foreach (self::getTemplatesGivenContext($node) as $includedTemplateName) {
                $read += array_fill_keys($this->getContextVariables($includedTemplateName), true);
            }

            if ($node instanceof ModuleNode) {
                foreach ($node->getAttribute('embedded_templates') ?? [] as $embeddedTemplate) {
                    $collect($embeddedTemplate);
                }
            }

            foreach ($node as $child) {
                $collect($child);
            }
        };
        $collect($tree);

        return self::$contextVariables[$templateName] = array_map('strval', array_keys($read));
    }

    /**
     * The templates $node gives the context of the template to. That of an `embed` is the parent of the template it
     * embeds.
     *
     * @return list<string>
     */
    private static function getTemplatesGivenContext(Node $node): array
    {
        if ($node instanceof FunctionExpression) {
            $withContext = self::getArgument($node, 2, 'with_context');
            $template = 'include' !== $node->getAttribute('name') || ($withContext instanceof ConstantExpression && false === $withContext->getAttribute('value'))
                ? null
                : self::getArgument($node, 0, 'template');
        } elseif ($node instanceof ModuleNode) {
            // also an embedded template, extending the template `embed` displays
            $template = $node->hasNode('parent') ? $node->getNode('parent') : null;
        } elseif ($node instanceof IncludeNode && !$node instanceof EmbedNode) {
            $template = $node->getAttribute('only') ? null : $node->getNode('expr');
        } else {
            $template = null;
        }

        return $template instanceof ConstantExpression && \is_string($template->getAttribute('value')) ? [$template->getAttribute('value')] : [];
    }

    /**
     * The argument of $expression at $position, or named $name.
     */
    public static function getArgument(FunctionExpression $expression, int $position, string $name): ?Node
    {
        $arguments = $expression->getNode('arguments');
        foreach ([(string) $position, $name] as $key) {
            if ($arguments->hasNode($key)) {
                return $arguments->getNode($key);
            }
        }

        return null;
    }

    /**
     * The node of the variable $variableName of the context of the template: every read of it goes through the node
     * of the first one, $expression.
     */
    private function getContextVariable(string $variableName, Node $expression): DataFlowNode
    {
        return $this->unassignedVariables[$variableName] ??= $this->getNode($expression, $variableName, 'read');
    }

    /**
     * The node labelled $label of $twigNode, for what $kind tells apart. Twig only gives the line of a node, so the
     * nodes of a line with the same label are told apart by a number.
     */
    private function getNode(Node $twigNode, string $label, string $kind = ''): DataFlowNode
    {
        $key = spl_object_id($twigNode).' '.$kind.' '.$label;
        if (isset($this->nodes[$key])) {
            return $this->nodes[$key];
        }

        $line = $twigNode->getTemplateLine();
        $count = $this->labelCounts[$line.' '.$label] = ($this->labelCounts[$line.' '.$label] ?? 0) + 1;

        $node = DataFlowNode::getForAssignment(1 === $count ? $label : $label.'#'.$count, $this->getLineLocation($line));
        $this->taint->addNode($node);

        return $this->nodes[$key] = $node;
    }

    /**
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    private function getLineLocation(int $line): CodeLocation
    {
        $code = $this->sourceContext->getCode();
        if (null === $this->lineOffsets) {
            $this->lineOffsets = [0];
            $offset = 0;
            while (false !== $offset = strpos($code, "\n", $offset)) {
                $this->lineOffsets[] = ++$offset;
            }
        }

        $start = $this->lineOffsets[$line - 1] ?? 0;
        $end = isset($this->lineOffsets[$line]) ? $this->lineOffsets[$line] - 1 : \strlen($code);

        return new CodeLocation\Raw($code, $this->sourceContext->getPath(), $this->sourceContext->getName(), $start, $end);
    }
}
