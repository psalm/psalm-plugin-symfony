<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\Internal\Codebase\TaintStore;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Internal\Type\TypeVariableTracker;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Storage\PropertyStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TIterable;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Node\EmbedNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\AssignNameExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\MacroReferenceExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\Unary\SpreadUnary;
use Twig\Node\ForNode;
use Twig\Node\ImportNode;
use Twig\Node\IncludeNode;
use Twig\Node\MacroNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\Node\SetNode;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @psalm-type IncludedVariables = array{
 *     keyed: array<string, list<DataFlowNode>>,
 *     unkeyed: list<DataFlowNode>,
 *     whole: list<DataFlowNode>,
 *     objects: array<string, list<string>>,
 *     printed: array<string, list<DataFlowNode>>,
 * } the variables an include gives: the values of the keys of `with` by name, those of its keys the analysis can't
 *   tell, and the arrays it can't tell the keys of; and by name the bases of the attributes of the objects of the
 *   values of the keys of `with` (see getObjectsAttributeBases()), and what printing those set to a variable gives
 *   (see taintPrintedVariable()), whose keyed values are then what the variable holds
 */
final class Context
{
    /** The prefix Twig gives the names it reserves (Twig\Node\Expression\TempNameExpression::RESERVED_NAME_PREFIX) */
    private const RESERVED_NAME_PREFIX = "\u{035C}";
    /** the most methods, a method and its overrides, a method call or an attribute of an object is linked to (see getMethodCallTaintDestination() and getMethodReturns()) */
    private const MAX_CALLED_METHODS = 8;

    /** the most attributes of a chain, `variable.a.b.c`, whose objects the analysis resolves (see getAttributePath()), also through the variables set to a chain or looping over one (see getTemplateReads()) */
    private const MAX_ATTRIBUTE_DEPTH = 3;

    /** the filters returning an item of the array they are given */
    private const ITEM_FILTERS = ['first', 'last'];

    /** @var array<string, DataFlowNode> the node of the first read of each variable of the context of the template */
    private $unassignedVariables = [];

    /** @var array<string, DataFlowNode> the node of the first print of each variable of the context of the template (see getPrintedVariable()) */
    private $unassignedStrings = [];

    /** @var array<string, non-empty-list<DataFlowNode>> the nodes of the values a variable set by the template can have */
    private $localVariables = [];

    /** @var array<string, non-empty-list<DataFlowNode>> the nodes of what printing a variable set by the template can give, for one set to a variable (see getPrintedVariable()) */
    private $localStrings = [];

    /** @var array<string, list<string>> the attributes of the objects a variable set by the template can have (see getAttributeBases()) */
    private $localAttributes = [];

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

    /** @var list<array<string, array{non-empty-list<DataFlowNode>|null, list<string>, non-empty-list<DataFlowNode>|null}>> the values, the attributes of their objects and what printing them gives, the targets of the loops the analysis is in had before them */
    private $loopTargets = [];

    /** @var array<string, DataFlowNode> the nodes of the template, by Twig node and label */
    private $nodes = [];

    /** @var array<string, int> how many nodes of the template have a label, by line */
    private $labelCounts = [];

    /** @var list<int>|null the offset at which each line of the template starts */
    private $lineOffsets;

    /** @var array<string, string> the templates whose macros the template imports, by the variable it imports them as (see getMacroImports()) */
    private $macroImports = [];

    /** @var array<string, true> the nodes, by id, of what calls of macros give: markup, which autoescaping outputs as is */
    private $markups = [];

    /**
     * The variables, the attributes of their objects, what printing them gives, the output, the conditional depth and
     * the loop targets of the template, while in a macro.
     *
     * @var array{array<string, non-empty-list<DataFlowNode>>, array<string, list<string>>, array<string, non-empty-list<DataFlowNode>>, array<DataFlowNode>, int, list<array<string, array{non-empty-list<DataFlowNode>|null, list<string>, non-empty-list<DataFlowNode>|null}>>}|null
     */
    private $templateScope;

    /** @var array<string, array{variables: list<string>, attributes: list<non-empty-list<string>>}> template name => the variables it reads from its context, and the chains of attributes it reads (see getTemplateReads()) */
    private static $templateReads = [];

    /** @var array<string, array<string, MacroNode>> template name => its macros, by name (see getTemplateReads()) */
    private static $templateMacros = [];

    /** @var array<string, DataFlowNode|null> class and chain of attributes => what Twig gives for the chain from an object of the class (see getObjectAttribute()) */
    private static $objectAttributes = [];

    /** @var array<string, list<string>> class and attribute => the classes of the objects Twig gives for the attribute of an object of the class (see getAttributeClasses()) */
    private static $attributeClasses = [];

    /** @var array<lowercase-string, list<ClassLikeStorage>>|null class or interface => the classes extending or implementing it */
    private static $descendants;

    /** @var array<lowercase-string, non-empty-list<array{string, MethodStorage, string}>>|null method name => the methods of that name of every class, with their method id and class */
    private static $methodsByName;

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
     * The nodes of the values the variable read by $expression can have, read both as an object and as a string (see
     * taintObjectVariable() and taintPrintedVariable()): what a filter, a function or a comparison reads of it.
     *
     * @return non-empty-list<DataFlowNode>
     */
    public function taintVariable(NameExpression $expression): array
    {
        $variableName = (string) $expression->getAttribute('name');
        $nodes = [];
        foreach ([...$this->getObjectVariable($variableName, $expression), ...$this->getPrintedVariable($variableName, $expression)] as $node) {
            $nodes[$node->id] = $node;
        }

        return array_values($nodes);
    }

    /**
     * The nodes of what the variable read by $expression holds, which its attributes are read from. For a variable of
     * the context, an integration giving apart what it gives as a string gives it what the variable holds (see
     * getForTemplateVariableObject()). A variable set to a variable, or looping over one, holds what that one holds,
     * or its items.
     *
     * @return non-empty-list<DataFlowNode>
     */
    public function taintObjectVariable(NameExpression $expression): array
    {
        return $this->getObjectVariable((string) $expression->getAttribute('name'), $expression);
    }

    /**
     * The nodes of what printing the variable read by $expression, as is or with `raw`, can give: what converting its
     * value to a string gives. For a variable of the context, an integration knowing what that gives (e.g. what the
     * __toString of an object returns, escaping what the object holds) gives it apart (see
     * getForTemplateVariableString()). A variable set to a variable, or looping over one, prints what that one prints,
     * or its items.
     *
     * @return non-empty-list<DataFlowNode>
     */
    public function taintPrintedVariable(NameExpression $expression): array
    {
        return $this->getPrintedVariable((string) $expression->getAttribute('name'), $expression);
    }

    /**
     * @return non-empty-list<DataFlowNode>
     */
    private function getObjectVariable(string $variableName, Node $expression): array
    {
        if (isset($this->localVariables[$variableName])) {
            return $this->localVariables[$variableName];
        }

        // a macro only sees its arguments and the globals of Twig, which the analysis doesn't know the values of
        return null === $this->templateScope
            ? [$this->getContextVariable($variableName, $expression)]
            : [$this->getNode($expression, $variableName, 'global')];
    }

    /**
     * @return non-empty-list<DataFlowNode>
     */
    private function getPrintedVariable(string $variableName, Node $expression): array
    {
        if (isset($this->localVariables[$variableName])) {
            return $this->localStrings[$variableName] ?? $this->localVariables[$variableName];
        }

        // a macro doesn't see the variables of the template (see getObjectVariable())
        return null === $this->templateScope
            ? [$this->unassignedStrings[$variableName] ??= $this->getNode($expression, '(string) '.$variableName, 'read')]
            : $this->getObjectVariable($variableName, $expression);
    }

    /**
     * Whether $node is what a call of a macro gives, or the markup a variable is set to (see
     * taintAssignmentFromSources()): markup, which Twig's autoescaping outputs as is.
     *
     * @psalm-capabilities read-props
     */
    public function isMarkup(DataFlowNode $node): bool
    {
        return isset($this->markups[$node->id]);
    }

    /**
     * The nodes of what the chain of attributes $path (see getAttributePath()) gives from the properties and the
     * methods of the objects $variable holds: those of the classes of the values the variable is given, and of the
     * objects their attributes give (see taintTemplateVariableAttributes()).
     *
     * @param non-empty-list<string> $path
     *
     * @return list<DataFlowNode>
     */
    public function getObjectAttributeSources(NameExpression $variable, array $path): array
    {
        $nodes = [];
        foreach ($this->getAttributeBases((string) $variable->getAttribute('name')) as $base) {
            $nodes[] = $node = self::getAttributeNode($base, implode('.', $path));
            $this->taint->addNode($node);
        }

        return $nodes;
    }

    /**
     * The variable whose objects $expression, `variable.a.b(...)`, reads a chain of attributes of, and that chain:
     * `a`, then `b()` of what `a` gives (`()` for a method call). Null for an attribute of something else than a
     * variable, for a key read like `variable['a']`, which an object doesn't have, for an attribute whose name is only
     * known when rendering, or for a chain of more than MAX_ATTRIBUTE_DEPTH attributes.
     *
     * @return array{NameExpression, non-empty-list<string>}|null
     */
    public static function getAttributePath(GetAttrExpression $expression): ?array
    {
        $path = [];
        $node = $expression;
        while ($node instanceof GetAttrExpression) {
            $attribute = $node->getNode('attribute');
            if ('array' === $node->getAttribute('type') || !$attribute instanceof ConstantExpression) {
                return null;
            }

            // from the last attribute to the first
            $path[] = ((string) $attribute->getAttribute('value')).('method' === $node->getAttribute('type') ? '()' : '');
            $node = $node->getNode('node');
        }

        $path = array_reverse($path);

        return [] !== $path && \count($path) <= self::MAX_ATTRIBUTE_DEPTH && $node instanceof NameExpression && !$node instanceof AssignNameExpression
            ? [$node, $path]
            : null;
    }

    /**
     * The bases (see getAttributeBase()) of the attributes of the objects the variable $variableName can hold: its own
     * as a variable of the context of the template, or those of the variable or of the chain of attributes of a
     * variable it is set to, or of the items of the one it loops over (see taintAssignmentFromSources()).
     *
     * @return list<string>
     *
     * @psalm-capabilities read-props
     */
    private function getAttributeBases(string $variableName): array
    {
        if (isset($this->localVariables[$variableName])) {
            return $this->localAttributes[$variableName] ?? [];
        }

        // a macro doesn't see the variables of the template
        return null === $this->templateScope ? [self::getAttributeBase($this->sourceContext->getName(), $variableName)] : [];
    }

    public function getTaintDestination(DataFlowNode $taintSource, FilterExpression $expression): DataFlowNode
    {
        $filter = $this->getTwigCallable($expression);
        $filterName = self::getFilterName($expression);
        $taintDestination = $this->getNode($expression, 'filter_'.$filterName);
        // first and last return an item of the array they are given, not the array
        $pathType = \in_array($filterName, self::ITEM_FILTERS, true) ? 'arrayvalue-fetch' : 'arg';

        $this->taint->addPath($taintSource, $taintDestination, $pathType, 0, $this->getCallableRemovedTaints($filter?->getCallable()));

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
     * The node of what the method call $expression, `item.name(...)`, returns, given the nodes of each of its
     * arguments by position. Like a PHP call, the arguments flow into the parameters of every method Twig may call
     * and their returns into the call: the method `name`, `getName`, `isName` or `hasName` of any class. The template
     * doesn't tell the class of `item`, so these are the methods of every class. Like Psalm resolving a PHP call, the
     * call goes to `__call` only if no class has such a method: then to the `__call` of every class. As for a PHP
     * call, a method whose body isn't analyzed returns what its storage says (`@psalm-flow`, sources). Null if there
     * is no such method, if they are methods of unrelated classes (what one of them returns from what its object
     * holds would be taken by the calls on objects of all the others), or if there are more than MAX_CALLED_METHODS
     * (linking a call to the bodies of many classes makes the resolution of the taint graph explode): the call then
     * returns what it is given (of its receiver, see getReceiverTaintSources()). What the receiver holds, $receiver,
     * enters the body of a method of a class Psalm specializes by instance as the `$this` of the call (see
     * addReceiverToBody()), else comes into the call only for the methods whose PHP calls return what the object they
     * are called on holds (see getReceiverPath()); any other method returns what its object holds through its body.
     *
     * @param list<list<DataFlowNode>> $arguments
     * @param list<DataFlowNode>       $receiver
     */
    public function getMethodCallTaintDestination(GetAttrExpression $expression, string $name, array $arguments, array $receiver = []): ?DataFlowNode
    {
        $codebase = $this->codebase;
        if (null === $codebase) {
            return null;
        }

        $methods = $this->getCalledMethods($codebase, $name);
        if ([] === $methods || self::MAX_CALLED_METHODS < \count($methods) || !self::isOneHierarchy($codebase, $methods)) {
            return null;
        }

        $taintDestination = $this->getNode($expression, 'method_'.$name);
        $location = $this->getLineLocation($expression->getTemplateLine());

        foreach ($methods as [$methodId, $storage, $className]) {
            // like the PHP calls of a method specialized by call or by instance, the call only takes what its own
            // arguments give the body: what the object holds comes with the receiver
            $specialization = self::getCallSpecialization($codebase, $this->taint, $storage, $location);

            $receiverPath = [] === $receiver || (null !== $specialization && $this->addReceiverToBody($codebase, $className, $storage, $receiver, $specialization))
                ? null
                : self::getReceiverPath($codebase, $className, $storage, $expression);
            if (null !== $receiverPath) {
                $this->taint->addPath($this->addReceiverPath($codebase, $expression, $name, $methodId, $storage, $receiverPath, $receiver), $taintDestination, 'arg');
            }

            $removedTaints = $storage->removed_taints | ($storage->signature_return_type?->getTaintsToRemove($codebase->getInputTaints()) ?? 0);
            $isMagic = '__call' === strtolower((string) $storage->cased_name);

            foreach ($arguments as $offset => $sources) {
                // __call is given the arguments as the items of its second parameter
                $parameter = $isMagic ? 1 : $offset;
                if (!isset($storage->params[$parameter]) && (end($storage->params) ?: null)?->is_variadic) {
                    // one of the arguments a variadic parameter takes
                    $parameter = \count($storage->params) - 1;
                }

                $parameterStorage = $storage->params[$parameter] ?? null;
                if (null === $parameterStorage) {
                    // an argument no parameter takes
                    continue;
                }

                $parameterNode = DataFlowNode::getForMethodArgument($methodId, $parameter, $storage, $specialization);
                $this->taint->addNode($parameterNode);
                if (0 !== $parameterStorage->sinks) {
                    $this->taint->addSink($parameterNode);
                }

                foreach ($sources as $source) {
                    $this->taint->addPath($source, $parameterNode, $isMagic ? 'arrayvalue-assignment' : 'arg');
                }
            }

            foreach ($storage->return_source_params as $offset => $pathType) {
                foreach ($isMagic ? [] : self::getArgumentsOfParameter($storage, $arguments, $offset) as $source) {
                    $this->taint->addPath($source, $taintDestination, $pathType, $storage->added_taints, $removedTaints);
                }
            }

            $this->addStoreFlows($expression, $name, $methodId, $storage, $arguments, $taintDestination, $isMagic);

            $sourceTaints = (0 !== $storage->taint_source_types ? $storage->taint_source_types : $storage->added_taints) & ~$storage->removed_taints;
            if (0 !== $sourceTaints) {
                $source = $this->getNode($expression, 'method_'.$name, 'source '.$methodId)->setTaints($sourceTaints);
                $this->taint->addSource($source);
                $this->taint->addPath($source, $taintDestination, 'arg');
            }

            $returnNode = DataFlowNode::getForMethodReturn($methodId, $storage, $specialization);
            $this->taint->addNode($returnNode);
            $this->taint->addPath($returnNode, $taintDestination, 'arg', 0, $removedTaints);
        }

        return $taintDestination;
    }

    /**
     * Makes the method call $expression, `item.name(...)`, of $method store into and read from the keyed stores its
     * `@psalm-flow` annotations name, `($value) -> Store::$data[$key]` and `Store::$data[$key] -> return`, as Psalm
     * does for a PHP call (see TaintStore::taintCall()): under the key a literal argument gives, else under any key
     * (see getStoreNames()). `__call` is given the arguments as the items of an array: each of them is stored under
     * any key, and it reads any key.
     *
     * @param list<list<DataFlowNode>> $arguments
     */
    private function addStoreFlows(GetAttrExpression $expression, string $name, string $methodId, MethodStorage $method, array $arguments, DataFlowNode $taintDestination, bool $isMagic): void
    {
        foreach ($method->taint_store_writes as $write) {
            $sources = [];
            foreach ($isMagic ? array_keys($arguments) : $write['params'] as $offset) {
                $sources = [...$sources, ...($isMagic ? $arguments[$offset] : self::getArgumentsOfParameter($method, $arguments, $offset))];
            }

            if ([] === $sources) {
                continue;
            }

            $writeNode = $this->getNode($expression, 'method_'.$name, 'store write '.$methodId.' '.$write['store']);
            foreach ($sources as $source) {
                $this->taint->addPath($source, $writeNode, $write['path_type']);
            }

            $names = $isMagic ? [[false, '']] : self::getStoreNames($expression, $write['key'], $write['is_list']);
            TaintStore::addWrite($this->taint, $write['store'], $names, $writeNode, $write['path_type']);
        }

        foreach ($method->taint_store_reads as $read) {
            $readNode = $this->getNode($expression, 'method_'.$name, 'store read '.$methodId.' '.$read['store']);
            $names = $isMagic ? [[false, '']] : self::getStoreNames($expression, $read['key'], $read['is_list']);
            TaintStore::addRead($this->taint, $read['store'], $names, $readNode, $read['path_type']);
            $this->taint->addPath($readNode, $taintDestination, 'arg');
        }
    }

    /**
     * The names of a keyed store the argument $key of the method call $expression gives, as TaintStore::getNames()
     * tells them for a PHP call: exact for a literal string or integer, or for each item of a literal array of them if
     * the argument is a list of keys ($isList), else any name.
     *
     * @return list<array{bool, string}>
     */
    private static function getStoreNames(GetAttrExpression $expression, ?int $key, bool $isList): array
    {
        $arguments = $expression->hasNode('arguments') ? $expression->getNode('arguments') : null;
        $argument = null;
        if (null !== $key && $arguments instanceof ArrayExpression) {
            foreach ($arguments->getKeyValuePairs() as $offset => ['value' => $value]) {
                if ($offset === $key) {
                    $argument = $value;
                }
            }
        }

        if ($isList) {
            $values = $argument instanceof ArrayExpression
                ? array_column($argument->getKeyValuePairs(), 'value')
                : null;
        } else {
            $values = null === $argument ? null : [$argument];
        }

        if (null === $values) {
            return [[false, '']];
        }

        $names = [];
        foreach ($values as $value) {
            $literal = $value instanceof ConstantExpression ? $value->getAttribute('value') : null;
            if (!\is_string($literal) && !\is_int($literal)) {
                return [[false, '']];
            }

            $names[] = [true, (string) $literal];
        }

        return $names;
    }

    /**
     * $location, that of a call of $method, if Psalm specializes the calls of $method to their locations, as it does
     * its PHP calls (see TaintFlowGraph::isCallSpecialized()): those of a method annotated with
     * `@psalm-taint-specialize` or pure, and those of a method of the project that can't be overridden, as long as
     * it turns out to be pure. Else null. A method whose calls are specialized returns to each call only what that
     * call gives it, and what it returns of its own: its unspecialized return node must have no edge, which would
     * take what every call gives it, and stop it from returning to the calls.
     */
    private static function getCallSpecialization(Codebase $codebase, TaintFlowGraph $graph, MethodStorage $method, ?CodeLocation $location): ?CodeLocation
    {
        return null !== $location && TaintFlowGraph::isCallSpecialized($graph, $codebase, $method, $location) ? $location : null;
    }

    /**
     * Whether the classes of $methods are one class and classes extending or implementing it: the methods are then a
     * method and its overrides, which an object of the class or of one of its descendants calls.
     *
     * @param non-empty-list<array{string, MethodStorage, string}> $methods
     *
     * @psalm-pure
     */
    private static function isOneHierarchy(Codebase $codebase, array $methods): bool
    {
        foreach ($methods as [, , $root]) {
            foreach ($methods as [, , $class]) {
                if ($class !== $root && !$codebase->classExtendsOrImplements($class, $root)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * The nodes of the arguments a parameter of $storage takes: those at its position, and after it if it is variadic.
     *
     * @param list<list<DataFlowNode>> $arguments
     *
     * @return list<DataFlowNode>
     *
     * @psalm-capabilities read-props
     */
    private static function getArgumentsOfParameter(FunctionLikeStorage $storage, array $arguments, int $offset): array
    {
        $sources = [];
        foreach ($arguments as $argumentOffset => $argumentSources) {
            if ($argumentOffset === $offset || ($argumentOffset > $offset && ($storage->params[$offset] ?? null)?->is_variadic)) {
                $sources = [...$sources, ...$argumentSources];
            }
        }

        return $sources;
    }

    /**
     * The nodes of what the method call $expression, `item.name(...)`, returns of what its receiver holds, the nodes
     * $receiver, where the analysis can't tell the methods it calls (see getMethodCallTaintDestination()): what the
     * PHP calls of each of them return of what the object they are called on holds: through the body of a method
     * of a class Psalm specializes by instance, which takes it as the `$this` of the call (see addReceiverToBody()),
     * else through its path (see getReceiverPath()), or all of it if no class has such a method. Any other method
     * returns what its object holds through its body, which the attributes of the objects of a variable of the
     * template give (see getObjectAttributeSources()).
     *
     * @param list<DataFlowNode> $receiver
     *
     * @return list<DataFlowNode>
     */
    public function getReceiverTaintSources(GetAttrExpression $expression, string $name, array $receiver): array
    {
        $methods = null === $this->codebase ? [] : $this->getCalledMethods($this->codebase, $name);
        if (null === $this->codebase || [] === $methods || [] === $receiver) {
            return $receiver;
        }

        $sources = [];
        $location = $this->getLineLocation($expression->getTemplateLine());
        foreach ($methods as [$methodId, $method, $className]) {
            if ($this->addReceiverToBody($this->codebase, $className, $method, $receiver, $location)) {
                // what the body of the call specialized to its location returns of its own object
                $returnNode = DataFlowNode::getForMethodReturn($methodId, $method, $location);
                $this->taint->addNode($returnNode);
                $sources[] = $this->addReceiverPath($this->codebase, $expression, $name, $methodId, $method, 'arg', [$returnNode]);
                continue;
            }

            $path = self::getReceiverPath($this->codebase, $className, $method, $expression);
            if (null !== $path) {
                $sources[] = $this->addReceiverPath($this->codebase, $expression, $name, $methodId, $method, $path, $receiver);
            }
        }

        return $sources;
    }

    /**
     * Makes what the receiver of a call of $method of the class $className holds, the nodes $receiver, enter its body
     * as its PHP calls do when Psalm specializes the class by instance: into the `$this` its body takes, specialized
     * to the call at $location, so that the call returns only what the body returns of it (see
     * DataFlowNode::getForMethodReturn()), as the PHP calls of the method do. False if Psalm doesn't analyze its body
     * so: a method of a class not specialized by instance, of a trait, static, or whose body isn't analyzed. False
     * too for a method of a class that is extended: its body, analyzed for the class, calls the methods of the class
     * on `$this`, which an object of a class extending it may override.
     *
     * @param list<DataFlowNode> $receiver
     */
    private function addReceiverToBody(Codebase $codebase, string $className, MethodStorage $method, array $receiver, CodeLocation $location): bool
    {
        $class = $codebase->classlike_storage_provider->get($className);
        $methodLocation = $method->location;
        if (!$class->specialize_instance || $class->is_trait || $method->is_static || !$method->specialize_call
            || (!$class->final && [] !== self::getDescendants($className))
            || null === $methodLocation || !$codebase->config->isInProjectDirs($methodLocation->file_path)
        ) {
            return false;
        }

        // the node Psalm gives the `$this` of the body of a method whose calls it specializes, keyed by the class
        // analyzing it and the lowercase name of the method
        $thisNode = DataFlowNode::getForAssignment(
            '$this in '.$class->name.'::'.strtolower((string) $method->cased_name),
            $methodLocation,
            DataFlowNode::getSpecializationKey($location),
        );
        $this->taint->addNode($thisNode);
        foreach ($receiver as $source) {
            $this->taint->addPath($source, $thisNode, '=');
        }

        return true;
    }

    /**
     * Makes what $method, called by $methodId, returns of what its receiver holds, the nodes $receiver, flow through
     * $path into a node of its own for the method call $expression, `item.name(...)`, without the taints its return
     * type removes.
     *
     * @param list<DataFlowNode> $receiver
     */
    private function addReceiverPath(Codebase $codebase, GetAttrExpression $expression, string $name, string $methodId, MethodStorage $method, string $path, array $receiver): DataFlowNode
    {
        $node = $this->getNode($expression, 'method_'.$name, 'receiver '.$methodId);
        $removedTaints = $method->removed_taints | ($method->signature_return_type?->getTaintsToRemove($codebase->getInputTaints()) ?? 0);
        foreach ($receiver as $source) {
            $this->taint->addPath($source, $node, $path, 0, $removedTaints);
        }

        return $node;
    }

    /**
     * The path through which what the object a PHP call of $method of the class $className is called on holds flows
     * into what it returns, besides what its body returns of it. Null if it doesn't.
     *
     * For a class specialized by instance, or extended by one, all of it: the properties of each object hold its own
     * taints. For the methods of the builtin containers and of the classes extending them, like
     * ArrayObject::getArrayCopy() or ArrayObject::offsetGet(), and for those of a Generator, the path Psalm follows
     * (see Codebase::getReceiverTaintPath()): all of it, its items or keys, the item of the literal key the call of
     * $expression gives, or what a generator returns.
     */
    private static function getReceiverPath(Codebase $codebase, string $className, MethodStorage $method, ?GetAttrExpression $expression = null): ?string
    {
        $storage = $codebase->classlike_storage_provider->get($className);
        foreach ([$storage, ...self::getDescendants($className)] as $class) {
            if ($class->specialize_instance) {
                return 'arg';
            }
        }

        return $codebase->getReceiverTaintPath(
            $storage->name.'::'.($method->cased_name ?? ''),
            null === $expression ? [] : self::getLiteralArgumentTypes($expression),
        );
    }

    /**
     * The types of the arguments of the method call $expression that are literal strings or integers, by position.
     *
     * @return array<int, Union>
     */
    private static function getLiteralArgumentTypes(GetAttrExpression $expression): array
    {
        $arguments = $expression->hasNode('arguments') ? $expression->getNode('arguments') : null;
        if (!$arguments instanceof ArrayExpression) {
            return [];
        }

        $types = [];
        foreach ($arguments->getKeyValuePairs() as $offset => ['value' => $value]) {
            $literal = $value instanceof ConstantExpression ? $value->getAttribute('value') : null;
            if (\is_int($literal)) {
                $types[$offset] = Type::getInt(false, $literal);
            } elseif (\is_string($literal)) {
                $types[$offset] = Type::getString($literal);
            }
        }

        return $types;
    }

    /**
     * The methods the method call `item.name(...)` may call: the method `name`, `getName`, `isName` or `hasName` of
     * any class, or else the `__call` of any class (see getMethodCallTaintDestination()).
     *
     * @return list<array{string, MethodStorage, string}>
     */
    private function getCalledMethods(Codebase $codebase, string $name): array
    {
        $methods = [];
        $methodsByName = $this->getMethodsByName($codebase);
        foreach ([$name, 'get'.$name, 'is'.$name, 'has'.$name] as $methodName) {
            $methods = [...$methods, ...$methodsByName[strtolower($methodName)] ?? []];
        }

        return [] === $methods ? $methodsByName['__call'] ?? [] : $methods;
    }

    /**
     * The methods of every class, by lowercase name, with the method id their body uses.
     *
     * @return array<lowercase-string, non-empty-list<array{string, MethodStorage, string}>>
     */
    private function getMethodsByName(Codebase $codebase): array
    {
        if (null !== self::$methodsByName) {
            return self::$methodsByName;
        }

        $methodsByName = [];
        foreach ($codebase->classlike_storage_provider::getAll() as $classStorage) {
            foreach ($classStorage->methods as $methodName => $methodStorage) {
                if ($methodStorage->abstract) {
                    // its implementations are methods of other classes
                    continue;
                }

                $methodsByName[$methodName][] = [$classStorage->name.'::'.($methodStorage->cased_name ?? $methodName), $methodStorage, $classStorage->name];
            }
        }

        return self::$methodsByName = $methodsByName;
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
     * it escapes (`@psalm-taint-escape`), and those its return type cannot hold (native for a project callable).
     *
     * @param callable|array{0: class-string|object, 1: string}|string|null $callable
     */
    private function getCallableRemovedTaints($callable): int
    {
        $codebase = $this->codebase;
        $storage = null === $codebase ? null : self::getCallableStorage($codebase, $callable);
        if (null === $codebase || null === $storage) {
            return 0;
        }

        // the docblock of a project callable isn't checked against what it returns, unlike that of a library, which
        // Psalm types the calls of the library with
        $returnType = null !== $storage->location && $codebase->config->isInProjectDirs($storage->location->file_path)
            ? $storage->signature_return_type
            : $storage->return_type;

        return $storage->removed_taints | ($returnType?->getTaintsToRemove($codebase->getInputTaints()) ?? 0);
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
     * The objects of the value are those $objectsOf, the value it is set to or loops over, holds, or its items if
     * $isItem (see getObjectsAttributeBases()). A variable set to a variable takes apart what that one holds and what
     * it prints (see taintObjectVariable() and taintPrintedVariable()), rather than $sources; one looping over a
     * variable takes the items of what that one holds.
     *
     * The markup the value gives, $markupSources (see isMarkup()), also flows into a node of its own, which holds
     * markup: autoescaping outputs it as is, and escapes the rest of the value.
     *
     * @param list<DataFlowNode> $sources
     * @param list<DataFlowNode> $markupSources
     */
    public function taintAssignmentFromSources(NameExpression $destinationVariable, array $sources, string $pathType = 'arg', bool $always = false, ?Node $objectsOf = null, bool $isItem = false, array $markupSources = []): void
    {
        /** @var string $destinationName */
        $destinationName = $destinationVariable->getAttribute('name');
        $taintDestination = $this->getNode($destinationVariable, $destinationName);

        $printed = null;
        $value = null === $objectsOf ? null : self::getObjectsPath($objectsOf);
        if (null !== $value && [] === $value[1]) {
            $sources = $this->taintObjectVariable($value[0]);
            // the items of a variable are those of what it holds, which they print
            if (!$isItem) {
                $printed = $this->getNode($destinationVariable, '(string) '.$destinationName);
                foreach ($this->taintPrintedVariable($value[0]) as $source) {
                    $this->taint->addPath($source, $printed, $pathType);
                }
            }
        }

        foreach ($sources as $source) {
            $this->taint->addPath($source, $taintDestination, $pathType);
        }

        $attributes = null === $objectsOf ? [] : $this->getObjectsAttributeBases($objectsOf, $isItem);

        $isConditional = 0 < $this->conditionalDepth && !$always;
        if ($isConditional) {
            $attributes = array_values(array_unique([...$this->getAttributeBases($destinationName), ...$attributes]));
            $this->localStrings[$destinationName] = [...$this->taintPrintedVariable($destinationVariable), $printed ?? $taintDestination];
        } elseif (null !== $printed) {
            $this->localStrings[$destinationName] = [$printed];
        } else {
            unset($this->localStrings[$destinationName]);
        }

        $markup = [];
        if ([] !== $markupSources) {
            $markup = [$this->getNode($destinationVariable, $destinationName, 'markup')];
            $this->markups[$markup[0]->id] = true;
            foreach ($markupSources as $source) {
                $this->taint->addPath($source, $markup[0], $pathType);
            }
        }

        $this->localVariables[$destinationName] = $isConditional
            ? [...$this->taintObjectVariable($destinationVariable), $taintDestination, ...$markup]
            : [$taintDestination, ...$markup];
        $this->localAttributes[$destinationName] = $attributes;
    }

    /**
     * The bases of the attributes of the objects $value holds, or of its items if $isItem: if it is a variable or a
     * chain of attributes of one (see getObjectsPath()), those of the chain from the bases of the variable,
     * `tpl#variable.a`, or `tpl#variable.a[]` for its items. Those of a chain longer than MAX_ATTRIBUTE_DEPTH have no
     * sources (see getTemplateReads()).
     *
     * @return list<string>
     */
    public function getObjectsAttributeBases(Node $value, bool $isItem = false): array
    {
        $objects = self::getObjectsPath($value);

        return null === $objects ? [] : array_map(
            static fn (string $base): string => implode('.', [$base, ...$objects[1]]).($isItem ? '[]' : ''),
            $this->getAttributeBases((string) $objects[0]->getAttribute('name')),
        );
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
            $values[$targetName] = [
                $this->localVariables[$targetName] ?? null,
                $this->localAttributes[$targetName] ?? [],
                $this->localStrings[$targetName] ?? null,
            ];
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

        foreach (array_pop($this->loopTargets) ?? [] as $targetName => [$value, $attributes, $strings]) {
            unset($this->localVariables[$targetName], $this->localAttributes[$targetName], $this->localStrings[$targetName]);
            if (null !== $value) {
                $this->localVariables[$targetName] = $value;
                $this->localAttributes[$targetName] = $attributes;
            }

            if (null !== $strings) {
                $this->localStrings[$targetName] = $strings;
            }
        }
    }

    /**
     * Reads the templates whose macros the template, $module, imports (see getMacroImports()).
     */
    public function setMacroImports(ModuleNode $module): void
    {
        $this->macroImports = self::getMacroImports($module, $this->sourceContext->getName());
    }

    /**
     * Enters the body of $macro, which only sees the arguments its calls give it (see callMacro()), and the objects
     * they hold. What it prints is what calling it outputs, rather than what the template outputs.
     */
    public function enterMacro(MacroNode $macro): void
    {
        $this->templateScope = [$this->localVariables, $this->localAttributes, $this->localStrings, $this->parentNodes, $this->conditionalDepth, $this->loopTargets];

        $templateName = $this->sourceContext->getName();
        [$parameters, $varargs] = $this->getMacroParameters($templateName, $macro);
        $this->localVariables = array_map(static fn (DataFlowNode $parameter): array => [$parameter], $parameters);
        $this->localAttributes = [];
        $this->localStrings = [];
        foreach ($parameters as $name => $_) {
            $this->localAttributes[$name] = [self::getMacroParameterBase($templateName, $macro, $name)];
            $this->localStrings[$name] = [$this->getMacroParameterString($templateName, $macro, $name)];
        }
        // the arguments the macro has no parameter for are in `varargs`, or in the variable it declares for them
        $variadicName = $macro->hasAttribute('variadic_name') ? $macro->getAttribute('variadic_name') : null;
        foreach (['varargs', ...(\is_string($variadicName) ? [$variadicName] : [])] as $name) {
            $this->localVariables[$name] = [$varargs];
            $this->localAttributes[$name] = [self::getMacroParameterBase($templateName, $macro, 'varargs')];
        }
        $this->parentNodes = [];
        $this->conditionalDepth = 0;
        $this->loopTargets = [];
    }

    public function leaveMacro(MacroNode $macro): void
    {
        $output = self::getMacroOutput($this->taint, $this->sourceContext->getName(), $macro);
        foreach ($this->parentNodes as $source) {
            $this->taint->addPath($source, $output, 'arg');
        }

        if (null !== $this->templateScope) {
            [$this->localVariables, $this->localAttributes, $this->localStrings, $this->parentNodes, $this->conditionalDepth, $this->loopTargets] = $this->templateScope;
            $this->templateScope = null;
        }
    }

    /**
     * Calls the macro $call calls, giving its parameters the taints of $arguments, the sources of each argument by
     * position or name (null for arguments unpacked into all of them), and the bases of the attributes of the objects
     * the argument holds (see getObjectsAttributeBases()): the parameter holds them, and `varargs` holds those of the
     * arguments the macro has no parameter for as its items. The parameter prints what the argument prints (see
     * taintPrintedVariable()), the nodes of the last element of the argument.
     *
     * @param list<array{int|string|null, list<DataFlowNode>, list<string>, list<DataFlowNode>}> $arguments
     *
     * @return DataFlowNode|null the node of what the macro outputs, null if the analysis can't tell which macro it is
     */
    public function callMacro(MacroReferenceExpression $call, array $arguments): ?DataFlowNode
    {
        $called = self::getCalledMacro($this->twig, $call, $this->macroImports, $this->sourceContext->getName());
        if (null === $called) {
            return null;
        }

        [$templateName, $macro] = $called;
        [$parameters, $varargs] = $this->getMacroParameters($templateName, $macro);
        $names = array_keys($parameters);
        $varargsBase = self::getMacroParameterBase($templateName, $macro, 'varargs');

        $paths = self::getTemplateReads($this->twig, $templateName)['attributes'];
        foreach ($arguments as [$key, $sources, $bases, $printed]) {
            // the parameters the argument gives, which hold its objects
            if (null === $key) {
                $given = $names;
            } elseif (\is_int($key)) {
                $given = isset($names[$key]) ? [$names[$key]] : [];
            } else {
                $given = isset($parameters[$key]) ? [$key] : [];
            }

            $destinations = array_map(static fn (string $name): DataFlowNode => $parameters[$name], $given);
            if (null === $key || [] === $given) {
                $destinations[] = $varargs;
                $this->linkAttributes($bases, $varargsBase, $paths, true);
            }

            foreach ($sources as $source) {
                foreach ($destinations as $destination) {
                    $this->taint->addPath($source, $destination, 'arg');
                }
            }

            foreach ($given as $name) {
                $this->linkAttributes($bases, self::getMacroParameterBase($templateName, $macro, $name), $paths);
                $string = $this->getMacroParameterString($templateName, $macro, $name);
                foreach ($printed as $source) {
                    $this->taint->addPath($source, $string, 'arg');
                }
            }
        }

        $output = self::getMacroOutput($this->taint, $templateName, $macro);
        $this->markups[$output->id] = true;

        return $output;
    }

    /**
     * The templates whose macros the template $tree, $templateName, imports, by the variable it imports them as:
     * `m` for `{% import 'macros.html.twig' as m %}`, and for `{% from ... import show %}` the variable Twig gives
     * the calls of `show` (see getMacroImportKey()). `_self` is the template itself.
     *
     * @return array<string, string>
     */
    private static function getMacroImports(Node $tree, string $templateName): array
    {
        $imports = [];
        if ($tree instanceof ImportNode) {
            $template = $tree->getNode('expr');
            $variable = $tree->getNode('var');
            // Twig >= 3.15 assigns the variable of the template as a node of its own
            $variable = $variable->hasNode('var') ? $variable->getNode('var') : $variable;
            if ($template instanceof ConstantExpression && \is_string($template->getAttribute('value'))) {
                $imports[self::getMacroImportKey($variable)] = $template->getAttribute('value');
            } elseif ($template instanceof NameExpression && '_self' === $template->getAttribute('name')) {
                $imports[self::getMacroImportKey($variable)] = $templateName;
            }
        }

        foreach ($tree as $child) {
            $imports = [...$imports, ...self::getMacroImports($child, $templateName)];
        }

        return $imports;
    }

    /**
     * The key of the variable $variable a template imports macros as: its name, or for one Twig names when compiling
     * (that of a `from`), the node itself, which the calls of the macros share.
     */
    private static function getMacroImportKey(Node $variable): string
    {
        $name = $variable->hasAttribute('name') ? $variable->getAttribute('name') : null;

        return \is_string($name) ? 'name '.$name : 'node '.spl_object_id($variable);
    }

    /**
     * The template, and the macro of it, $call, made in the template $templateName importing $imports (see
     * getMacroImports()), calls by its name: one of the template itself (`_self`), or of a template it imports. Null
     * if the analysis can't tell which.
     *
     * @param array<string, string> $imports
     *
     * @return array{string, MacroNode}|null
     */
    private static function getCalledMacro(Environment $twig, MacroReferenceExpression $call, array $imports, string $templateName): ?array
    {
        $name = $call->getAttribute('name');
        $template = $call->getNode('template');
        $template = $template->hasAttribute('name') && '_self' === $template->getAttribute('name')
            ? $templateName
            : $imports[self::getMacroImportKey($template)] ?? null;
        if (null === $template || !\is_string($name)) {
            return null;
        }

        self::getTemplateReads($twig, $template);
        $macros = self::$templateMacros[$template] ?? [];
        // older Twig versions name the macro called after the method it compiles to (`macro_name`)
        if (!isset($macros[$name]) && str_starts_with($name, 'macro_')) {
            $name = substr($name, \strlen('macro_'));
        }

        return isset($macros[$name]) ? [$template, $macros[$name]] : null;
    }

    /**
     * Where the attributes of the objects of the parameter $name of $macro, of the template $templateName, come from
     * (see getAttributeBase()): the objects of the arguments its calls give it (see callMacro()).
     */
    private static function getMacroParameterBase(string $templateName, MacroNode $macro, string $name): string
    {
        return self::getAttributeBase($templateName, '_self.'.(string) $macro->getAttribute('name').'('.$name.')');
    }

    /**
     * The node of what $macro, of the template $templateName, outputs: what its calls, in any template, give.
     */
    private static function getMacroOutput(TaintFlowGraph $graph, string $templateName, MacroNode $macro): DataFlowNode
    {
        $output = DataFlowNode::getForPropertyFetch(self::getAttributeBase($templateName, '_self.'.(string) $macro->getAttribute('name').'()'));
        $graph->addNode($output);

        return $output;
    }

    /**
     * The nodes of the parameters of $macro, of the template $templateName, by name, and of the arguments it has no
     * parameter for: the same for its calls in any template.
     *
     * @return array{array<string, DataFlowNode>, DataFlowNode}
     */
    private function getMacroParameters(string $templateName, MacroNode $macro): array
    {
        $arguments = $macro->getNode('arguments');
        if ($arguments instanceof ArrayExpression) {
            $names = array_map(
                static function (array $pair): string {
                    $name = (string) $pair['key']->getAttribute('name');

                    // Twig prefixes the names it reserves, such as `context`
                    return str_starts_with($name, self::RESERVED_NAME_PREFIX) ? substr($name, \strlen(self::RESERVED_NAME_PREFIX)) : $name;
                },
                $arguments->getKeyValuePairs(),
            );
        } else {
            // Twig < 3.15 gives the default values by name
            $names = array_map('strval', array_keys(iterator_to_array($arguments)));
        }

        $parameters = [];
        foreach ([...$names, 'varargs'] as $name) {
            $parameters[$name] = DataFlowNode::getForPropertyFetch(self::getMacroParameterBase($templateName, $macro, $name));
            $this->taint->addNode($parameters[$name]);
        }

        $varargs = $parameters['varargs'];
        unset($parameters['varargs']);

        return [$parameters, $varargs];
    }

    /**
     * The node of what printing the parameter $name of $macro, of the template $templateName, gives: what printing
     * the arguments its calls give it gives (see callMacro()).
     */
    private function getMacroParameterString(string $templateName, MacroNode $macro, string $name): DataFlowNode
    {
        $string = DataFlowNode::getForPropertyFetch(self::getMacroParameterBase($templateName, $macro, $name).'#string');
        $this->taint->addNode($string);

        return $string;
    }

    /**
     * Includes the template $includedTemplateName, giving each variable it reads from its context (see
     * getTemplateReads()) what $variables gives it, and if $withContext the variable of its name of this template. The
     * objects of the variable have the attributes the included template reads: those of the objects of the value of
     * the key of `with` it is given, or of the variable of this template, whose chains getTemplateReads() reads.
     *
     * @param IncludedVariables $variables
     *
     * @return DataFlowNode the node of what the included template outputs
     */
    public function includeTemplate(Node $includeNode, string $includedTemplateName, array $variables, bool $withContext): DataFlowNode
    {
        $includedReads = self::getTemplateReads($this->twig, $includedTemplateName);
        foreach ($includedReads['variables'] as $variableName) {
            $destination = self::getForTemplateVariable($includedTemplateName, $variableName);
            $this->taint->addNode($destination);

            // a key of `with` set to a variable gives apart what it holds and what it prints, as does the context
            $keyed = $variables['keyed'][$variableName] ?? [];
            $channels = isset($variables['printed'][$variableName]) ? [$keyed, $variables['printed'][$variableName]] : null;
            foreach ([...null === $channels ? $keyed : [], ...$variables['unkeyed']] as $source) {
                $this->taint->addPath($source, $destination, 'arg');
            }

            foreach ($variables['whole'] as $source) {
                $this->taint->addPath($source, $destination, "arrayvalue-fetch-'".$variableName."'");
            }

            $bases = $variables['objects'][$variableName] ?? [];
            if ($withContext && !isset($variables['keyed'][$variableName])) {
                $channels = [$this->getObjectVariable($variableName, $includeNode), $this->getPrintedVariable($variableName, $includeNode)];
                $bases = $this->getAttributeBases($variableName);
            }

            if (null !== $channels) {
                // the included template reads what the variable holds and prints what it prints, as this one does
                $objectDestination = self::getForTemplateVariableObject($includedTemplateName, $variableName);
                $stringDestination = self::getForTemplateVariableString($includedTemplateName, $variableName);
                $this->taint->addNode($objectDestination);
                $this->taint->addNode($stringDestination);
                foreach ($channels[0] as $source) {
                    $this->taint->addPath($source, $objectDestination, 'arg');
                }

                foreach ($channels[1] as $source) {
                    $this->taint->addPath($source, $stringDestination, 'arg');
                }
            }

            $this->linkAttributes($bases, self::getAttributeBase($includedTemplateName, $variableName), $includedReads['attributes']);
        }

        $includedOutput = self::getForTemplate($includedTemplateName);
        $this->taint->addNode($includedOutput);

        return $includedOutput;
    }

    /**
     * Gives the chains of attributes $paths of the objects of $destinationBase, and of its items, those of the objects
     * of each of $bases, and of their items. If $asItems, the objects of $bases are rather the items of
     * $destinationBase.
     *
     * @param list<string>                 $bases
     * @param list<non-empty-list<string>> $paths
     */
    private function linkAttributes(array $bases, string $destinationBase, array $paths, bool $asItems = false): void
    {
        foreach ($bases as $base) {
            foreach ($asItems ? ['' => '[]'] : ['' => '', '[]' => '[]'] as $items => $destinationItems) {
                foreach ($paths as $path) {
                    $attribute = implode('.', $path);
                    $source = self::getAttributeNode($base.$items, $attribute);
                    $destination = self::getAttributeNode($destinationBase.$destinationItems, $attribute);
                    $this->taint->addNode($source);
                    $this->taint->addNode($destination);
                    $this->taint->addPath($source, $destination, 'arg');
                }
            }
        }
    }

    /**
     * Makes the variables the template reads from its context take the taints of the variables of their name given to
     * it, and of the keys of their name of the contexts the analysis can't tell the keys of. What it reads of what
     * they hold and what it prints of them also take apart the taints an integration gives what they hold and what
     * they give as strings (see getForTemplateVariableObject() and getForTemplateVariableString()).
     */
    public function taintUnassignedVariables(string $templateName): void
    {
        $context = self::getForTemplateContext($templateName);
        $this->taint->addNode($context);

        $reads = [];
        foreach ($this->unassignedVariables as $variableName => $read) {
            $reads[$variableName][] = [$read, self::getForTemplateVariableObject($templateName, $variableName)];
        }

        foreach ($this->unassignedStrings as $variableName => $read) {
            $reads[$variableName][] = [$read, self::getForTemplateVariableString($templateName, $variableName)];
        }

        foreach ($reads as $variableName => $variableReads) {
            $variable = self::getForTemplateVariable($templateName, $variableName);
            $this->taint->addNode($variable);
            $this->taint->addPath($context, $variable, "arrayvalue-fetch-'".$variableName."'");

            foreach ($variableReads as [$read, $channel]) {
                $this->taint->addNode($channel);
                $this->taint->addPath($variable, $read, 'arg');
                $this->taint->addPath($channel, $read, 'arg');
            }
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
     * The node of what a variable of the context of the template holds, for an integration that gives apart what the
     * variable gives when printed (see getForTemplateVariableString()): what the template reads its attributes from,
     * and what it reads of the variable everywhere but when printing it, as is or with `raw`. Taints given to
     * getForTemplateVariable() reach both.
     *
     * @psalm-pure
     */
    public static function getForTemplateVariableObject(string $templateName, string $variableName): DataFlowNode
    {
        return DataFlowNode::getForPropertyFetch(strtolower($templateName).'#'.$variableName.'#object');
    }

    /**
     * The node of what a variable of the context of the template gives when converted to a string, for an integration
     * that knows it (e.g. what the __toString of an object returns, escaping what the object holds): what printing the
     * variable, as is or with `raw`, outputs. The template also reads it, with what the variable holds (see
     * getForTemplateVariableObject()), where it reads the variable as a value: given to a filter, a function or a
     * comparison.
     *
     * @psalm-pure
     */
    public static function getForTemplateVariableString(string $templateName, string $variableName): DataFlowNode
    {
        return DataFlowNode::getForPropertyFetch(strtolower($templateName).'#'.$variableName.'#string');
    }

    /**
     * Gives the attributes of the objects of the variable $variableName of the template what Twig gives for them from
     * the objects of $type, the type of a value of the variable, and from the objects of its items (of an array or of
     * a traversable object), which a loop over it gives. Also the chains of attributes, `a.b`, which give what Twig
     * gives for `b` from the objects of the type `a` is declared with (see getObjectAttribute()). For an integration
     * giving a variable to a template: the template doesn't tell the classes of its variables.
     */
    public static function taintTemplateVariableAttributes(Codebase $codebase, string $templateName, string $variableName, Union $type): void
    {
        $graph = $codebase->taint_flow_graph;
        $attributes = null === $graph ? [] : self::getTemplateReads(TemplateFileAnalyzer::getEnvironment($codebase), $templateName)['attributes'];
        if (null === $graph || [] === $attributes) {
            return;
        }

        $base = self::getAttributeBase($templateName, $variableName);
        // the types the type variables of what Psalm infers stand for
        $resolve = static fn (Union $type): Union => TypeVariableTracker::resolveTypeVariables($type, $codebase);
        $type = $resolve($type);
        $objects = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TArray && !$atomic instanceof TKeyedArray) {
                $objects[] = [$atomic, $base];
            }
        }

        foreach (self::getItemTypes($codebase, $type) as $items) {
            foreach ($resolve($items)->getAtomicTypes() as $item) {
                $objects[] = [$item, $base.'[]'];
            }
        }

        foreach ($objects as [$object, $objectsBase]) {
            if (!$object instanceof TNamedObject) {
                continue;
            }

            foreach ($attributes as $path) {
                $source = self::getObjectAttribute($codebase, $graph, $object->value, $path);
                if (null !== $source) {
                    $destination = self::getAttributeNode($objectsBase, implode('.', $path));
                    $graph->addNode($destination);
                    $graph->addPath($source, $destination, 'arg');
                }
            }
        }
    }

    /**
     * Where the attributes of the objects of the variable $variableName of the context of the template come from: a
     * prefix of the nodes of its attributes (see getAttributeNode()), followed by `[]` for those of its items.
     *
     * @psalm-pure
     */
    private static function getAttributeBase(string $templateName, string $variableName): string
    {
        return strtolower($templateName).'#'.$variableName;
    }

    /**
     * The node of the attribute $attribute (`name`, or `name()` for a method call, or a chain of them like
     * `name().next`) of the objects of $base (see getAttributeBase()). Each attribute has its own node: Psalm follows
     * a single flow of given taints through a node, so the flows of several keys of a node of all of them would leave
     * by one of them.
     *
     * @psalm-pure
     */
    private static function getAttributeNode(string $base, string $attribute): DataFlowNode
    {
        return DataFlowNode::getForPropertyFetch($base.'.'.$attribute);
    }

    /**
     * The node of what Twig gives for the chain of attributes $path (see getAttributePath()) from an object of $class.
     *
     * For an attribute (`name`, or `name()` for a method call), see Twig's CoreExtension::getAttribute(): a public
     * property `name`, or else what its public method `name`, or else `getName`, `isName` and `hasName`, return, as
     * for a PHP call (see getMethodReturns()). A magic property may be missing, so the methods are also called for it.
     *
     * For a chain, `name.next`, what Twig gives for `next` from the objects of the classes `name` is declared to give
     * (see getAttributeClasses()), as for a PHP fetch on what a property or a call gives. In a chain, `name[]` stands
     * for the items of what `name` gives, which a loop over it gives (see getTemplateReads()). An attribute of an
     * unknown type gives none: the template then only fetches `next` from what `name` gives (see PrintNodeAnalyzer).
     *
     * Null if Twig gives none of them, or if the class is specialized by instance and has the property: what an
     * object of it holds is in the object, which the variable holds.
     *
     * @param non-empty-list<string> $path
     */
    private static function getObjectAttribute(Codebase $codebase, TaintFlowGraph $graph, string $class, array $path): ?DataFlowNode
    {
        $attribute = implode('.', $path);
        $key = strtolower($class).' '.$attribute;
        if (\array_key_exists($key, self::$objectAttributes)) {
            return self::$objectAttributes[$key];
        }

        try {
            $storage = $codebase->classlike_storage_provider->get($class);
        } catch (\InvalidArgumentException) {
            return self::$objectAttributes[$key] = null;
        }

        $sources = [];
        $rest = \array_slice($path, 1);
        if ([] !== $rest) {
            foreach (self::getAttributeClasses($codebase, $storage, $path[0]) as $attributeClass) {
                $source = self::getObjectAttribute($codebase, $graph, $attributeClass, $rest);
                if (null !== $source) {
                    $sources[] = $source;
                }
            }
        } else {
            $isMethodCall = str_ends_with($attribute, '()');
            $name = $isMethodCall ? substr($attribute, 0, -2) : $attribute;

            $isProperty = !$isMethodCall && null !== self::getPublicProperty($codebase, $storage, $name);
            if (!$isMethodCall && ($isProperty || isset($storage->pseudo_property_get_types['$'.$name]))) {
                if ($storage->specialize_instance) {
                    return self::$objectAttributes[$key] = null;
                }

                // the node of the property a PHP fetch on an object of the class reads
                $propertyNode = DataFlowNode::getForPropertyFetch($storage->name.'::$'.$name);
                $graph->addNode($propertyNode);
                if ($isProperty) {
                    return self::$objectAttributes[$key] = $propertyNode;
                }

                $sources[] = $propertyNode;
            }

            foreach (self::getAttributeMethods($codebase, $storage, $name) as [$methodName, $declaringMethodId, $method]) {
                $sources = [...$sources, ...self::getMethodReturns($codebase, $graph, $storage, $methodName, $declaringMethodId, $method)];
            }
        }

        if ([] === $sources) {
            return self::$objectAttributes[$key] = null;
        }

        $node = DataFlowNode::getForPropertyFetch($storage->name.'.'.$attribute);
        $graph->addNode($node);
        foreach ($sources as $source) {
            $graph->addPath($source, $node, 'arg');
        }

        return self::$objectAttributes[$key] = $node;
    }

    /**
     * The public, non-static property $name of the class of $storage, which Twig reads for the attribute `name`.
     *
     * @psalm-capabilities read-props
     */
    private static function getPublicProperty(Codebase $codebase, ClassLikeStorage $storage, string $name): ?PropertyStorage
    {
        $declaringClass = $storage->declaring_property_ids[$name] ?? null;
        $property = null === $declaringClass ? null : $codebase->classlike_storage_provider->get($declaringClass)->properties[$name] ?? null;

        return null !== $property && ClassLikeAnalyzer::VISIBILITY_PUBLIC === $property->visibility && !$property->is_static ? $property : null;
    }

    /**
     * The public methods Twig may call for the attribute `name` of an object of the class of $storage: `name`, or
     * else `getName`, `isName` and `hasName`. With their lowercase name and the id of the method declaring them.
     *
     * @return list<array{lowercase-string, MethodIdentifier, MethodStorage}>
     *
     * @psalm-capabilities read-props
     */
    private static function getAttributeMethods(Codebase $codebase, ClassLikeStorage $storage, string $name): array
    {
        $methodName = strtolower($name);
        $methodNames = isset($storage->declaring_method_ids[$methodName]) ? [$methodName] : ['get'.$methodName, 'is'.$methodName, 'has'.$methodName];

        $methods = [];
        foreach ($methodNames as $methodName) {
            $declaringMethodId = $storage->declaring_method_ids[$methodName] ?? null;
            if (null === $declaringMethodId) {
                continue;
            }

            $method = $codebase->methods->getStorage($declaringMethodId);
            if (ClassLikeAnalyzer::VISIBILITY_PUBLIC === $method->visibility) {
                $methods[] = [$methodName, $declaringMethodId, $method];
            }
        }

        return $methods;
    }

    /**
     * The classes of the objects Twig gives for the attribute $attribute (`name`, or `name()` for a method call) of an
     * object of the class of $storage (see getObjectAttribute()): those of the declared type of the public property,
     * or else of the magic property and of the declared return types of the methods. A template parameter stands for
     * the classes of its bound. For `name[]`, the classes of the items of these types (see getItemTypes()). None if
     * there are more than MAX_CALLED_METHODS of them: the attributes of what the attribute gives are then only what is
     * fetched from it.
     *
     * @return list<string>
     */
    private static function getAttributeClasses(Codebase $codebase, ClassLikeStorage $storage, string $attribute): array
    {
        $key = strtolower($storage->name).' '.$attribute;
        if (isset(self::$attributeClasses[$key])) {
            return self::$attributeClasses[$key];
        }

        $isItem = str_ends_with($attribute, '[]');
        $attribute = $isItem ? substr($attribute, 0, -2) : $attribute;
        $isMethodCall = str_ends_with($attribute, '()');
        $name = $isMethodCall ? substr($attribute, 0, -2) : $attribute;

        $property = $isMethodCall ? null : self::getPublicProperty($codebase, $storage, $name);
        if (null !== $property) {
            $types = [$property->type];
        } else {
            $types = [$isMethodCall ? null : ($storage->pseudo_property_get_types['$'.$name] ?? null)];
            foreach (self::getAttributeMethods($codebase, $storage, $name) as [, , $method]) {
                $types[] = $method->return_type;
            }
        }

        $classes = [];
        foreach ($types as $type) {
            foreach (null === $type ? [] : ($isItem ? self::getItemTypes($codebase, $type) : [$type]) as $objectsType) {
                $classes = [...$classes, ...self::getObjectClasses($objectsType)];
            }
        }
        $classes = array_values(array_unique($classes));

        return self::$attributeClasses[$key] = self::MAX_CALLED_METHODS < \count($classes) ? [] : $classes;
    }

    /**
     * The types of the items of the arrays, the iterables and the traversable objects of $type, which a loop over it
     * gives: for a traversable object, the type parameter its class gives the TValue of Traversable (see
     * getTraversableValueType()).
     *
     * @return list<Union>
     */
    private static function getItemTypes(Codebase $codebase, Union $type): array
    {
        $items = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TArray || $atomic instanceof TIterable) {
                $items[] = $atomic->type_params[1];
            } elseif ($atomic instanceof TKeyedArray) {
                $items[] = $atomic->getGenericValueType();
            } elseif ($atomic instanceof TGenericObject && $codebase->classOrInterfaceExists($atomic->value)
                && ($codebase->classExtendsOrImplements($atomic->value, \Traversable::class) || $codebase->interfaceExtends($atomic->value, \Traversable::class))) {
                $value = self::getTraversableValueType($codebase, $atomic);
                if (null !== $value) {
                    $items[] = $value;
                }
            }
        }

        return $items;
    }

    /**
     * The type $object, of a class implementing Traversable, gives the TValue of Traversable: the type parameter of
     * the class it stands for, or the type its class gives it. Not merely its last type parameter, which can be a
     * purity template (`ArrayIterator<TKey, TValue, TStorage>`).
     *
     * @psalm-capabilities read-props
     */
    private static function getTraversableValueType(Codebase $codebase, TGenericObject $object): ?Union
    {
        $storage = $codebase->classlike_storage_provider->get($object->value);
        $class = $storage->name;
        $name = 'TValue';
        if ('traversable' !== strtolower($class)) {
            $value = $storage->template_extended_params['Traversable']['TValue'] ?? null;
            // what the classes it extends give it, down to a type parameter of its own
            while (null !== $value) {
                $atomics = $value->getAtomicTypes();
                $parameter = 1 === \count($atomics) ? reset($atomics) : null;
                if (!$parameter instanceof TTemplateParam) {
                    return $value;
                }

                if (strtolower($parameter->defining_class) === strtolower($class)) {
                    $name = $parameter->param_name;
                    break;
                }

                $extended = $storage->template_extended_params[$parameter->defining_class][$parameter->param_name] ?? null;
                if (null === $extended || $extended === $value) {
                    return $value;
                }
                $value = $extended;
            }

            if (null === $value) {
                return null;
            }
        }

        $offset = array_search($name, array_keys($storage->template_types ?? []), true);

        return false === $offset ? null : $object->type_params[$offset] ?? null;
    }

    /**
     * The classes of the objects of $type, and of the bounds of its template parameters.
     *
     * @return list<string>
     *
     * @psalm-pure
     */
    private static function getObjectClasses(Union $type): array
    {
        $classes = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TTemplateParam) {
                $classes = [...$classes, ...self::getObjectClasses($atomic->as)];
            } elseif ($atomic instanceof TNamedObject) {
                $classes[] = $atomic->value;
            }
        }

        return $classes;
    }

    /**
     * The nodes of what a PHP call of the method $methodName on an object of the class of $storage returns: those of
     * the method and, if it has few of them (at most MAX_CALLED_METHODS methods in all), of its overrides. Linking a
     * call to the bodies of many classes makes the resolution of the taint graph explode.
     *
     * A method whose calls Psalm specializes returns to each PHP call what that call gives it (see
     * getCallSpecialization()), so the attribute takes its return specialized to a call of its own: at the
     * declaration of the method, where there is no PHP call, and which no argument is given to. It returns what the
     * method returns of its own, without what the PHP calls give the method. The call of a template gives its
     * arguments to the method through a call specialized to it (see getMethodCallTaintDestination()).
     *
     * @return non-empty-list<DataFlowNode>
     */
    private static function getMethodReturns(Codebase $codebase, TaintFlowGraph $graph, ClassLikeStorage $storage, string $methodName, MethodIdentifier $declaringMethodId, MethodStorage $method): array
    {
        $casedName = $method->cased_name ?? $methodName;
        $specialization = self::getCallSpecialization($codebase, $graph, $method, $method->location);
        $return = DataFlowNode::getForMethodReturn($storage->name.'::'.$casedName, $method, $specialization);
        $graph->addNode($return);
        if (strtolower($storage->name) !== strtolower($declaringMethodId->fq_class_name)) {
            // an inherited method, linked like Psalm links it for a PHP call
            $declaringReturn = DataFlowNode::getForMethodReturn($codebase->methods->getCasedMethodId($declaringMethodId), $method, $specialization);
            $graph->addNode($declaringReturn);
            $graph->addPath($declaringReturn, $return, 'parent');
        }

        $returns = [$return];
        foreach ($storage->final ? [] : self::getDescendants($storage->name) as $descendant) {
            $override = $descendant->methods[$methodName] ?? null;
            if (null !== $override && !$override->abstract) {
                $returns[] = DataFlowNode::getForMethodReturn($descendant->name.'::'.($override->cased_name ?? $methodName), $override, self::getCallSpecialization($codebase, $graph, $override, $override->location));
            }
        }

        if (self::MAX_CALLED_METHODS < \count($returns)) {
            return [$return];
        }

        foreach ($returns as $override) {
            $graph->addNode($override);
        }

        return $returns;
    }

    /**
     * The classes extending or implementing $class.
     *
     * @return list<ClassLikeStorage>
     */
    private static function getDescendants(string $class): array
    {
        if (null === self::$descendants) {
            self::$descendants = [];
            foreach (ClassLikeStorageProvider::getAll() as $storage) {
                foreach ([...$storage->parent_classes, ...$storage->class_implements] as $ancestor => $_) {
                    self::$descendants[$ancestor][] = $storage;
                }
            }
        }

        return self::$descendants[strtolower($class)] ?? [];
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
     * them, as it may read it before. Also the chains of attributes of variables these templates read (see
     * getAttributePath()): `[name]` for `variable.name`, `[name()]` for `variable.name(...)`, `[name(), next]` for
     * `variable.name(...).next`. Through a variable set to a chain, or looping over one, they are chains of the
     * variable of the chain: `[manager, name]` for `{% set m = variable.manager %}{{ m.name }}`, and `[items[], name]`
     * for `{% for i in variable.items %}{{ i.name }}{% endfor %}`, `items[]` standing for the items of what `items`
     * gives (see taintAssignmentFromSources()). So are the chains a template it gives such a variable to reads, by its
     * context or by a key of `with`: `[items[], name]` for `{% for i in variable.items %}{% include 'part' %}{% endfor %}`
     * if `part` reads `i.name`, and also those of the items of the chain, as the included template may loop over the
     * variable: `[users, name]` and `[users[], name]` for `{% include 'part' with {users: variable.users} %}` if `part`
     * reads `u.name` in `{% for u in users %}`. So are the chains a macro of the template reads of the objects of
     * the chains its calls give it: `[users, name]` and `[users[], name]` for `{{ _self.list(variable.users) }}` if
     * `list` reads `u.name` in `{% for u in users %}`. The template is read as a whole: a variable stands for any
     * chain it is set to, wherever it is read.
     *
     * @return array{variables: list<string>, attributes: list<non-empty-list<string>>}
     */
    private static function getTemplateReads(Environment $twig, string $templateName): array
    {
        if (isset(self::$templateReads[$templateName])) {
            return self::$templateReads[$templateName];
        }

        // a template including itself reads nothing else
        self::$templateReads[$templateName] = ['variables' => [], 'attributes' => []];

        try {
            $tree = $twig->parse($twig->tokenize($twig->getLoader()->getSourceContext($templateName)));
        } catch (Error) {
            // a template the analysis cannot load or parse: its own analysis reports why
            return self::$templateReads[$templateName];
        }

        $read = [];
        $attributes = [];
        /** @var array<string, array<string, non-empty-list<string>>> $chains variable set by the template => the chains of attributes of other variables it is set to or loops over */
        $chains = [];
        // the templates embedded in the templates read, by index: each one is read where its `embed` displays it
        $embeddedTemplates = [];
        /** @var list<Node> $macroArguments the arguments of the calls of the macros of the template */
        $macroArguments = [];
        $imports = self::getMacroImports($tree, $templateName);
        $macros = [];
        foreach ($tree->hasNode('macros') ? $tree->getNode('macros') : [] as $macro) {
            if ($macro instanceof MacroNode) {
                $macros[(string) $macro->getAttribute('name')] = $macro;
            }
        }
        self::$templateMacros[$templateName] = $macros;
        /**
         * The chains of attributes of variables of the context giving the objects of $value, a variable or a chain of
         * attributes of one: those giving the objects of the variable, followed by the chain.
         *
         * @return list<list<string>>
         */
        $getChains = static function (Node $value) use (&$chains): array {
            $objects = self::getObjectsPath($value);

            return null === $objects ? [] : array_map(
                static fn (array $prefix): array => [...$prefix, ...$objects[1]],
                [[], ...array_values($chains[(string) $objects[0]->getAttribute('name')] ?? [])],
            );
        };
        /**
         * Reads the chains $paths of the objects the chains $prefixes give.
         *
         * @param iterable<list<string>>       $prefixes
         * @param list<non-empty-list<string>> $paths
         */
        $addAttributes = static function (iterable $prefixes, array $paths) use (&$attributes): void {
            foreach ($prefixes as $prefix) {
                foreach ($paths as $path) {
                    $chain = [...$prefix, ...$path];
                    if (\count($chain) <= self::MAX_ATTRIBUTE_DEPTH) {
                        $attributes[implode('.', $chain)] = $chain;
                    }
                }
            }
        };
        /**
         * Reads the chains $paths an included template reads of the objects the chains $prefixes give, and of their
         * items: the template may loop over a variable it is given (see includeTemplate()).
         *
         * @param iterable<list<string>>       $prefixes
         * @param list<non-empty-list<string>> $paths
         */
        $addIncludedAttributes = static function (iterable $prefixes, array $paths) use ($addAttributes): void {
            foreach ($prefixes as $prefix) {
                $addAttributes([$prefix], $paths);
                if ([] !== $prefix) {
                    $prefix[] = array_pop($prefix).'[]';
                    $addAttributes([$prefix], $paths);
                }
            }
        };
        $assign = static function (Node $variable, Node $value, bool $isItem) use (&$chains, $getChains): void {
            if (!$variable instanceof NameExpression) {
                return;
            }

            foreach ($getChains($value) as $chain) {
                // a variable set to a variable, or looping over one, has its attributes: only a chain is a prefix, if
                // it leaves room for one more attribute
                if ([] === $chain || self::MAX_ATTRIBUTE_DEPTH <= \count($chain)) {
                    continue;
                }

                if ($isItem) {
                    $chain[] = array_pop($chain).'[]';
                }

                $chains[(string) $variable->getAttribute('name')][implode('.', $chain)] = $chain;
            }
        };
        $collect = static function (Node $node) use (&$collect, &$read, &$chains, &$embeddedTemplates, &$macroArguments, $getChains, $addAttributes, $addIncludedAttributes, $assign, $twig, $imports, $templateName): void {
            if ($node instanceof NameExpression && !$node instanceof AssignNameExpression) {
                $read[(string) $node->getAttribute('name')] = true;
            }

            if ($node instanceof SetNode && !$node->getAttribute('capture')) {
                // like TaintAnalysisVisitor, several names are given one value each, a single name the whole value
                $names = $node->getNode('names');
                $values = 1 < \count($names) ? iterator_to_array($node->getNode('values')) : [$node->getNode('values')];
                foreach ($names as $i => $name) {
                    if (isset($values[$i])) {
                        $assign($name, $values[$i], false);
                    }
                }
            }

            if ($node instanceof ForNode) {
                $assign($node->getNode('value_target'), $node->getNode('seq'), true);
            }

            $called = $node instanceof MacroReferenceExpression ? self::getCalledMacro($twig, $node, $imports, $templateName) : null;
            if (null !== $called) {
                $arguments = $node->getNode('arguments');
                foreach ($arguments instanceof ArrayExpression ? $arguments->getKeyValuePairs() : [] as ['value' => $value]) {
                    // the items of an array unpacked into the arguments are arguments
                    $unpacked = $value instanceof SpreadUnary ? $value->getNode('node') : null;
                    $values = $unpacked instanceof ArrayExpression
                        ? array_column($unpacked->getKeyValuePairs(), 'value')
                        : [$unpacked ?? $value];
                    foreach ($values as $argument) {
                        if ($called[0] === $templateName) {
                            $macroArguments[] = $argument;
                        } else {
                            // a macro of another template reads what that template reads
                            $addIncludedAttributes($getChains($argument), self::getTemplateReads($twig, $called[0])['attributes']);
                        }
                    }
                }
            }

            $path = $node instanceof GetAttrExpression ? self::getAttributePath($node) : null;
            if (null !== $path) {
                $addAttributes($getChains($path[0]), [$path[1]]);
            }

            if ($node instanceof ModuleNode) {
                foreach ($node->getAttribute('embedded_templates') ?? [] as $embeddedTemplate) {
                    $embeddedTemplates[$embeddedTemplate->getAttribute('index')] = $embeddedTemplate;
                }
            }

            $embeddedTemplate = $node instanceof EmbedNode ? $embeddedTemplates[$node->getAttribute('index')] ?? null : null;
            if (null !== $embeddedTemplate) {
                // the blocks it overrides are displayed here, with the variables of the template
                $collect($embeddedTemplate);
            }

            $include = self::getInclude($node, $embeddedTemplate);
            if (null !== $include) {
                [$includedTemplateName, $variables, $withContext] = $include;
                $includedReads = self::getTemplateReads($twig, $includedTemplateName);

                // a variable given by a key of `with` holds the objects of its value
                $keyed = [];
                foreach ($variables instanceof ArrayExpression ? $variables->getKeyValuePairs() : [] as ['key' => $key, 'value' => $value]) {
                    if ($key instanceof ConstantExpression) {
                        $keyed[(string) $key->getAttribute('value')] = true;
                        $addIncludedAttributes($getChains($value), $includedReads['attributes']);
                    }
                }

                if ($withContext) {
                    $read += array_fill_keys($includedReads['variables'], true);
                    $addAttributes([[]], $includedReads['attributes']);
                    // a variable of this template set to a chain, or looping over one, holds the objects of the chain
                    foreach ($includedReads['variables'] as $variableName) {
                        if (!isset($keyed[$variableName])) {
                            $addIncludedAttributes($chains[$variableName] ?? [], $includedReads['attributes']);
                        }
                    }
                }
            }

            foreach ($node as $child) {
                $collect($child);
            }
        };
        $collect($tree);

        // a parameter of a macro holds the objects of the arguments its calls give it: the chains the template reads
        // are also read from the objects of the chains of the arguments, until there are no new ones of at most
        // MAX_ATTRIBUTE_DEPTH attributes
        do {
            $count = \count($attributes);
            foreach ($macroArguments as $value) {
                $addIncludedAttributes($getChains($value), array_values($attributes));
            }
        } while (\count($attributes) !== $count);

        return self::$templateReads[$templateName] = [
            'variables' => array_map('strval', array_keys($read)),
            'attributes' => array_values($attributes),
        ];
    }

    /**
     * The variable whose objects $value holds, and the chain of attributes of it (see getAttributePath()) giving them:
     * none for the variable itself. Null if $value is something else. Twig >= 3.15 gives the value of a single `set`
     * as a list of it.
     *
     * @return array{NameExpression, list<string>}|null
     */
    private static function getObjectsPath(Node $value): ?array
    {
        if (!$value instanceof AbstractExpression && 1 === \count($value)) {
            $value = $value->getNode('0');
        }

        if ($value instanceof GetAttrExpression) {
            return self::getAttributePath($value);
        }

        return $value instanceof NameExpression && !$value instanceof AssignNameExpression ? [$value, []] : null;
    }

    /**
     * The template $node displays, the expression of the variables of `with` it gives it, and whether it gives it the
     * context of the template: one `include`, `include()` or `embed` include, or that the template extends. That of an
     * `embed` is the parent of $embeddedTemplate, the template it embeds. Null if $node displays none, or one only
     * known when rendering.
     *
     * @return array{string, Node|null, bool}|null
     */
    private static function getInclude(Node $node, ?ModuleNode $embeddedTemplate): ?array
    {
        $variables = null;
        $withContext = true;
        if ($node instanceof FunctionExpression) {
            $withContextArgument = self::getArgument($node, 2, 'with_context');
            $template = 'include' === $node->getAttribute('name') ? self::getArgument($node, 0, 'template') : null;
            $variables = self::getArgument($node, 1, 'variables');
            $withContext = !$withContextArgument instanceof ConstantExpression || false !== $withContextArgument->getAttribute('value');
        } elseif ($node instanceof ModuleNode) {
            // an embedded template displays its parent where its `embed` is
            $template = null === $node->getAttribute('index') && $node->hasNode('parent') ? $node->getNode('parent') : null;
        } elseif ($node instanceof IncludeNode) {
            $template = $node instanceof EmbedNode
                ? ($embeddedTemplate?->hasNode('parent') ? $embeddedTemplate->getNode('parent') : null)
                : $node->getNode('expr');
            $variables = $node->hasNode('variables') ? $node->getNode('variables') : null;
            $withContext = !$node->getAttribute('only');
        } else {
            $template = null;
        }

        return $template instanceof ConstantExpression && \is_string($template->getAttribute('value'))
            ? [$template->getAttribute('value'), $variables, $withContext]
            : null;
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
