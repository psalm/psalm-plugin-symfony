<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Internal\Type\TypeVariableTracker;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Node\EmbedNode;
use Twig\Node\Expression\AssignNameExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
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
    /** the most methods, a method and its overrides, an attribute of an object is linked to (see getMethodReturns()) */
    private const MAX_CALLED_METHODS = 8;

    /** @var array<string, DataFlowNode> the node of the first read of each variable of the context of the template */
    private $unassignedVariables = [];

    /** @var array<string, non-empty-list<DataFlowNode>> the nodes of the values a variable set by the template can have */
    private $localVariables = [];

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

    /** @var list<array<string, array{non-empty-list<DataFlowNode>|null, list<string>}>> the values, and the attributes of their objects, the targets of the loops the analysis is in had before them */
    private $loopTargets = [];

    /** @var array<string, DataFlowNode> the nodes of the template, by Twig node and label */
    private $nodes = [];

    /** @var array<string, int> how many nodes of the template have a label, by line */
    private $labelCounts = [];

    /** @var list<int>|null the offset at which each line of the template starts */
    private $lineOffsets;

    /** @var array<string, array{variables: list<string>, attributes: list<string>}> template name => the variables it reads from its context, and the attributes it reads (see getTemplateReads()) */
    private static $templateReads = [];

    /** @var array<string, DataFlowNode|null> class and attribute => what Twig gives for the attribute of an object of the class (see getObjectAttribute()) */
    private static $objectAttributes = [];

    /** @var array<lowercase-string, list<ClassLikeStorage>>|null class or interface => the classes extending or implementing it */
    private static $descendants;

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

    /**
     * The nodes of what `variable.name`, or `variable.name(...)` if $isMethodCall, gives from the properties and the
     * methods of the objects $variable holds: those of the classes of the values the variable is given (see
     * taintTemplateVariableAttributes()).
     *
     * @return list<DataFlowNode>
     */
    public function getObjectAttributeSources(NameExpression $variable, string $name, bool $isMethodCall): array
    {
        $nodes = [];
        foreach ($this->getAttributeBases((string) $variable->getAttribute('name')) as $base) {
            $nodes[] = $node = self::getAttributeNode($base, $isMethodCall ? $name.'()' : $name);
            $this->taint->addNode($node);
        }

        return $nodes;
    }

    /**
     * The bases (see getAttributeBase()) of the attributes of the objects the variable $variableName can hold: its own
     * as a variable of the context of the template, or those of the variable it is set to, or of the items of the
     * variable it loops over.
     *
     * @return list<string>
     *
     * @psalm-capabilities read-props
     */
    private function getAttributeBases(string $variableName): array
    {
        return isset($this->localVariables[$variableName])
            ? $this->localAttributes[$variableName] ?? []
            : [self::getAttributeBase($this->sourceContext->getName(), $variableName)];
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
     * The objects of the value are those of the variable $objectsOf, or of its items if $isItem: the variable it is
     * set to, or loops over.
     *
     * @param list<DataFlowNode> $sources
     */
    public function taintAssignmentFromSources(NameExpression $destinationVariable, array $sources, string $pathType = 'arg', bool $always = false, ?NameExpression $objectsOf = null, bool $isItem = false): void
    {
        /** @var string $destinationName */
        $destinationName = $destinationVariable->getAttribute('name');
        $taintDestination = $this->getNode($destinationVariable, $destinationName);

        foreach ($sources as $source) {
            $this->taint->addPath($source, $taintDestination, $pathType);
        }

        $attributes = null === $objectsOf ? [] : array_map(
            static fn (string $base): string => $isItem ? $base.'[]' : $base,
            $this->getAttributeBases((string) $objectsOf->getAttribute('name')),
        );

        $isConditional = 0 < $this->conditionalDepth && !$always;
        if ($isConditional) {
            $attributes = array_values(array_unique([...$this->getAttributeBases($destinationName), ...$attributes]));
        }

        $this->localVariables[$destinationName] = $isConditional
            ? [...$this->taintVariable($destinationVariable), $taintDestination]
            : [$taintDestination];
        $this->localAttributes[$destinationName] = $attributes;
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
            $values[$targetName] = [$this->localVariables[$targetName] ?? null, $this->localAttributes[$targetName] ?? []];
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

        foreach (array_pop($this->loopTargets) ?? [] as $targetName => [$value, $attributes]) {
            if (null === $value) {
                unset($this->localVariables[$targetName], $this->localAttributes[$targetName]);
            } else {
                $this->localVariables[$targetName] = $value;
                $this->localAttributes[$targetName] = $attributes;
            }
        }
    }

    /**
     * Includes the template $includedTemplateName, giving each variable it reads from its context (see
     * getTemplateReads()) what $variables gives it, and if $withContext the variable of its name of this template.
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

                // the objects of the variable have the attributes the included template reads
                $includedBase = self::getAttributeBase($includedTemplateName, $variableName);
                foreach ($this->getAttributeBases($variableName) as $base) {
                    foreach ([$base, $base.'[]'] as $i => $objects) {
                        foreach ($includedReads['attributes'] as $attribute) {
                            $source = self::getAttributeNode($objects, $attribute);
                            $attributeDestination = self::getAttributeNode(0 === $i ? $includedBase : $includedBase.'[]', $attribute);
                            $this->taint->addNode($source);
                            $this->taint->addNode($attributeDestination);
                            $this->taint->addPath($source, $attributeDestination, 'arg');
                        }
                    }
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
     * Gives the attributes of the objects of the variable $variableName of the template what Twig gives for them from
     * the objects of $type, the type of a value of the variable, and from the objects of its items (of an array or of
     * a traversable object), which a loop over it gives. For an integration giving a variable to a template: the
     * template doesn't tell the classes of its variables.
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
        $resolve = static fn (Union $type): array => array_values(TypeVariableTracker::resolveTypeVariables($type, $codebase)->getAtomicTypes());
        $objects = [];
        foreach ($resolve($type) as $atomic) {
            if ($atomic instanceof TArray || $atomic instanceof TKeyedArray) {
                $items = $atomic instanceof TArray ? $atomic->type_params[1] : $atomic->getGenericValueType();
                $objects = [...$objects, ...array_map(static fn ($item): array => [$item, $base.'[]'], $resolve($items))];
            } else {
                $objects[] = [$atomic, $base];
            }

            if ($atomic instanceof TGenericObject && $codebase->classOrInterfaceExists($atomic->value)
                && ($codebase->classExtendsOrImplements($atomic->value, \Traversable::class) || $codebase->interfaceExtends($atomic->value, \Traversable::class))) {
                // the items of a traversable object: the last of its type parameters, as for an Iterator<TKey, TValue>
                foreach ($resolve(end($atomic->type_params)) as $item) {
                    $objects[] = [$item, $base.'[]'];
                }
            }
        }

        foreach ($objects as [$object, $objectsBase]) {
            if (!$object instanceof TNamedObject) {
                continue;
            }

            foreach ($attributes as $attribute) {
                $source = self::getObjectAttribute($codebase, $graph, $object->value, $attribute);
                if (null !== $source) {
                    $destination = self::getAttributeNode($objectsBase, $attribute);
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
     * The node of the attribute $attribute (`name`, or `name()` for a method call) of the objects of $base (see
     * getAttributeBase()). Each attribute has its own node: Psalm follows a single flow of given taints through a node,
     * so the flows of several keys of a node of all of them would leave by one of them.
     *
     * @psalm-pure
     */
    private static function getAttributeNode(string $base, string $attribute): DataFlowNode
    {
        return DataFlowNode::getForPropertyFetch($base.'.'.$attribute);
    }

    /**
     * The node of what Twig gives for the attribute $attribute (`name`, or `name()` for a method call) of an object of
     * $class (see Twig's CoreExtension::getAttribute()): a public property `name`, or else what its public method
     * `name`, or else `getName`, `isName` and `hasName`, return, as for a PHP call (see getMethodReturns()). A magic
     * property may be missing, so the methods are also called for it. Null if Twig gives none of them, or if the class
     * is specialized by instance and has the property: what an object of it holds is in the object, which the
     * variable holds.
     */
    private static function getObjectAttribute(Codebase $codebase, TaintFlowGraph $graph, string $class, string $attribute): ?DataFlowNode
    {
        $key = strtolower($class).' '.$attribute;
        if (\array_key_exists($key, self::$objectAttributes)) {
            return self::$objectAttributes[$key];
        }

        try {
            $storage = $codebase->classlike_storage_provider->get($class);
        } catch (\InvalidArgumentException) {
            return self::$objectAttributes[$key] = null;
        }

        $isMethodCall = str_ends_with($attribute, '()');
        $name = $isMethodCall ? substr($attribute, 0, -2) : $attribute;

        $sources = [];
        if (!$isMethodCall) {
            $declaringClass = $storage->declaring_property_ids[$name] ?? null;
            $property = null === $declaringClass ? null : $codebase->classlike_storage_provider->get($declaringClass)->properties[$name] ?? null;
            $isProperty = null !== $property && ClassLikeAnalyzer::VISIBILITY_PUBLIC === $property->visibility && !$property->is_static;
            if (($isProperty || isset($storage->pseudo_property_get_types['$'.$name])) && $storage->specialize_instance) {
                return self::$objectAttributes[$key] = null;
            }

            if ($isProperty || isset($storage->pseudo_property_get_types['$'.$name])) {
                // the node of the property a PHP fetch on an object of the class reads
                $propertyNode = DataFlowNode::getForPropertyFetch($storage->name.'::$'.$name);
                $graph->addNode($propertyNode);
                if ($isProperty) {
                    return self::$objectAttributes[$key] = $propertyNode;
                }

                $sources[] = $propertyNode;
            }
        }

        $methodName = strtolower($name);
        $methodNames = isset($storage->declaring_method_ids[$methodName]) ? [$methodName] : ['get'.$methodName, 'is'.$methodName, 'has'.$methodName];
        foreach ($methodNames as $methodName) {
            $declaringMethodId = $storage->declaring_method_ids[$methodName] ?? null;
            if (null === $declaringMethodId) {
                continue;
            }

            $method = $codebase->methods->getStorage($declaringMethodId);
            if (ClassLikeAnalyzer::VISIBILITY_PUBLIC === $method->visibility) {
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
     * The nodes of what a PHP call of the method $methodName on an object of the class of $storage returns: those of
     * the method and, if it has few of them (at most MAX_CALLED_METHODS methods in all), of its overrides. Linking a
     * call to the bodies of many classes makes the resolution of the taint graph explode.
     *
     * @return non-empty-list<DataFlowNode>
     */
    private static function getMethodReturns(Codebase $codebase, TaintFlowGraph $graph, ClassLikeStorage $storage, string $methodName, MethodIdentifier $declaringMethodId, MethodStorage $method): array
    {
        $casedName = $method->cased_name ?? $methodName;
        $return = DataFlowNode::getForMethodReturn($storage->name.'::'.$casedName, $method);
        $graph->addNode($return);
        if (strtolower($storage->name) !== strtolower($declaringMethodId->fq_class_name)) {
            // an inherited method, linked like Psalm links it for a PHP call
            $declaringReturn = DataFlowNode::getForMethodReturn($codebase->methods->getCasedMethodId($declaringMethodId), $method);
            $graph->addNode($declaringReturn);
            $graph->addPath($declaringReturn, $return, 'parent');
        }

        $returns = [$return];
        foreach ($storage->final ? [] : self::getDescendants($storage->name) as $descendant) {
            $override = $descendant->methods[$methodName] ?? null;
            if (null !== $override && !$override->abstract) {
                $returns[] = DataFlowNode::getForMethodReturn($descendant->name.'::'.($override->cased_name ?? $methodName), $override);
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
     * them, as it may read it before. Also the attributes of variables these templates read: `name` for
     * `variable.name`, `name()` for `variable.name(...)`.
     *
     * @return array{variables: list<string>, attributes: list<string>}
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
        $collect = static function (Node $node) use (&$collect, &$read, &$attributes, $twig): void {
            if ($node instanceof NameExpression && !$node instanceof AssignNameExpression) {
                $read[(string) $node->getAttribute('name')] = true;
            }

            if ($node instanceof GetAttrExpression && 'array' !== $node->getAttribute('type')
                && $node->getNode('node') instanceof NameExpression
                && ($attribute = $node->getNode('attribute')) instanceof ConstantExpression) {
                $attributes[$attribute->getAttribute('value').('method' === $node->getAttribute('type') ? '()' : '')] = true;
            }

            foreach (self::getTemplatesGivenContext($node) as $includedTemplateName) {
                $includedReads = self::getTemplateReads($twig, $includedTemplateName);
                $read += array_fill_keys($includedReads['variables'], true);
                $attributes += array_fill_keys($includedReads['attributes'], true);
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

        return self::$templateReads[$templateName] = [
            'variables' => array_map('strval', array_keys($read)),
            'attributes' => array_map('strval', array_keys($attributes)),
        ];
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
