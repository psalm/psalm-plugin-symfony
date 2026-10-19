<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Internal\DataFlow\DataFlowNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConditionalExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\MacroReferenceExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\ReturnBoolInterface;
use Twig\Node\Expression\ReturnNumberInterface;
use Twig\Node\Expression\Unary\SpreadUnary;
use Twig\Node\Expression\Variable\LocalVariable;
use Twig\Node\Node;
use Twig\Node\PrintNode;

/**
 * @psalm-import-type IncludedVariables from Context
 */
final class PrintNodeAnalyzer
{
    /** @var Context */
    private $context;

    /**
     * @psalm-capabilities read-props
     */
    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function analyzePrintNode(PrintNode $node): void
    {
        $expression = $node->getNode('expr');

        if (!$expression instanceof AbstractExpression) {
            throw new \RuntimeException('The expr node has an expected type.');
        }

        foreach ($this->getPrintedTaintSources($expression) as $source) {
            $this->context->addSink($node, $source);
        }
    }

    /**
     * The nodes the taints of what printing $expression outputs come from: a variable printed as is, or with `raw`,
     * outputs what converting it to a string gives (see Context::taintPrintedVariable()).
     *
     * @return list<DataFlowNode>
     */
    private function getPrintedTaintSources(Node $expression): array
    {
        if ($expression instanceof NameExpression) {
            return $this->context->taintPrintedVariable($expression);
        }

        if ($expression instanceof FilterExpression && 'raw' === Context::getFilterName($expression)) {
            return array_map(
                fn (DataFlowNode $source): DataFlowNode => $this->context->getTaintDestination($source, $expression),
                $this->getPrintedTaintSources($expression->getNode('node')),
            );
        }

        return $this->getTaintSources($expression);
    }

    /**
     * The nodes the taints of the value of $expression come from: those of the variables it reads, through the
     * filters it applies. An escaped value has none.
     *
     * @return list<DataFlowNode>
     */
    public function getTaintSources(Node $expression): array
    {
        if ($expression instanceof FilterExpression) {
            if (self::isEscapingFilter($expression)) {
                // autoescaping outputs the markup of a macro as is
                return self::isAutoescaping($expression) ? $this->getMarkupSources($expression->getNode('node')) : [];
            }

            // what a filter returns comes from what it filters and from its arguments (`format`, `replace`, ...)
            $sources = $this->getTaintSources($expression->getNode('node'));
            if ($expression->hasNode('arguments')) {
                $sources = [...$sources, ...$this->getTaintSources($expression->getNode('arguments'))];
            }

            return array_map(
                fn (DataFlowNode $source): DataFlowNode => $this->context->getTaintDestination($source, $expression),
                $sources,
            );
        }

        if ($expression instanceof FunctionExpression && 'include' === $expression->getAttribute('name')
            && null !== $includedOutput = $this->getIncludedOutput($expression)) {
            return [$includedOutput];
        }

        if ($expression instanceof MacroReferenceExpression && null !== $macroOutput = $this->getMacroOutput($expression)) {
            return [$macroOutput];
        }

        if ($expression instanceof FunctionExpression) {
            // what a function returns comes from its arguments, through what its PHP callable does with them
            $sources = [];
            foreach ($expression->getNode('arguments') as $argument) {
                $sources = [...$sources, ...$this->getTaintSources($argument)];
            }

            return array_map(
                fn (DataFlowNode $source): DataFlowNode => $this->context->getFunctionTaintDestination($source, $expression),
                $sources,
            );
        }

        if ($expression instanceof GetAttrExpression) {
            return $this->getAttributeTaintSources($expression);
        }

        if ($expression instanceof NameExpression) {
            return $this->context->taintVariable($expression);
        }

        if ($expression instanceof ConditionalExpression) {
            // the condition chooses the branch that is output, but isn't output itself
            return [
                ...$this->getTaintSources($expression->getNode('expr2')),
                ...$this->getTaintSources($expression->getNode('expr3')),
            ];
        }

        if ($expression instanceof ReturnBoolInterface || $expression instanceof ReturnNumberInterface) {
            // a comparison, a test or an arithmetic operation gives a boolean or a number, which holds no markup
            return [];
        }

        // anything else (an attribute, a call, an operator, ...) takes the taints of what it is made of
        $sources = [];
        foreach ($expression as $child) {
            $sources = [...$sources, ...$this->getTaintSources($child)];
        }

        return $sources;
    }

    /**
     * `item.name` and `item['name']` give the value of the key `name` of `item`, whose other keys can hold other taints:
     * like an array fetch in PHP. A method call gives what the methods it may call return (see
     * Context::getMethodCallTaintDestination()). An attribute the analysis can't tell gives the taints of the whole of
     * `item` (for a method call on a variable, only what the methods return of their receiver, see
     * Context::getReceiverTaintSources()), and of its arguments.
     *
     * `item.name` and `item.name(...)` of an object give its property or what its method returns: those of the
     * classes of the values given to the template for `item` (see Context::getObjectAttributeSources()). So do the
     * chains of them, `item.name.next`, from the classes `item.name` is declared to give.
     *
     * @return list<DataFlowNode>
     */
    private function getAttributeTaintSources(GetAttrExpression $expression): array
    {
        $node = $expression->getNode('node');
        // the attributes of a variable are those of what it holds, not of what it gives as a string
        $sources = $node instanceof NameExpression ? $this->context->taintObjectVariable($node) : $this->getTaintSources($node);
        $attribute = $expression->getNode('attribute');
        // the values of the internal Twig\Template::ANY_CALL, ARRAY_CALL and METHOD_CALL
        $type = $expression->getAttribute('type');

        $path = Context::getAttributePath($expression);
        $objectSources = null === $path ? [] : $this->context->getObjectAttributeSources($path[0], $path[1]);

        if ('method' !== $type && $attribute instanceof ConstantExpression) {
            $key = (string) $attribute->getAttribute('value');

            return [
                ...array_map(
                    fn (DataFlowNode $source): DataFlowNode => $this->context->getFetchDestination(
                        $source,
                        $expression,
                        $key,
                        "arrayvalue-fetch-'".$key."'",
                    ),
                    $sources,
                ),
                ...$objectSources,
            ];
        }

        $arguments = $expression->hasNode('arguments') ? $expression->getNode('arguments') : null;
        $argumentSources = 'method' === $type && $attribute instanceof ConstantExpression
            ? $this->getArgumentTaintSources($arguments)
            : null;
        if (null !== $argumentSources) {
            $name = (string) $attribute->getAttribute('value');
            $call = $this->context->getMethodCallTaintDestination($expression, $name, $argumentSources, $sources);
            if (null !== $call) {
                return [...$objectSources, $call];
            }

            if ($node instanceof NameExpression) {
                // what the methods return of what their objects hold comes with the attributes of the objects, but for
                // the methods returning it by what their PHP calls do
                $sources = $this->context->getReceiverTaintSources($expression, $name, $sources);
            }
        }

        if (null !== $arguments) {
            $sources = [...$sources, ...$this->getTaintSources($arguments)];
        }

        return [...$sources, ...$objectSources];
    }

    /**
     * The nodes of each argument of a method call, by position. Null if the analysis can't tell their positions.
     *
     * @return list<list<DataFlowNode>>|null
     */
    private function getArgumentTaintSources(?Node $arguments): ?array
    {
        if (null === $arguments) {
            return [];
        }

        if (!$arguments instanceof ArrayExpression) {
            return null;
        }

        $argumentSources = [];
        foreach ($arguments->getKeyValuePairs() as $position => ['key' => $key, 'value' => $value]) {
            // Twig >= 3.15 gives positions as local variables
            $keyValue = match (true) {
                $key instanceof LocalVariable => $key->getAttribute('name'),
                $key instanceof ConstantExpression => $key->getAttribute('value'),
                default => null,
            };
            if ($keyValue !== $position || $value instanceof SpreadUnary) {
                // a named argument, or arguments spread from an array
                return null;
            }

            $argumentSources[] = $this->getTaintSources($value);
        }

        return $argumentSources;
    }

    /**
     * The variables an include gives the included template from $variables, the expression of its `with`.
     *
     * @return IncludedVariables
     */
    public function getIncludedVariables(?Node $variables): array
    {
        $includedVariables = ['keyed' => [], 'unkeyed' => [], 'whole' => [], 'objects' => [], 'printed' => []];

        if (null === $variables) {
            return $includedVariables;
        }

        if (!$variables instanceof ArrayExpression) {
            // an array the analysis can't tell the keys of: its own keyed paths tell what is in which key
            $includedVariables['whole'] = $this->getTaintSources($variables);

            return $includedVariables;
        }

        foreach ($variables->getKeyValuePairs() as ['key' => $key, 'value' => $value]) {
            $sources = $this->getTaintSources($value);
            if ($key instanceof ConstantExpression) {
                $name = (string) $key->getAttribute('value');
                $includedVariables['objects'][$name] = $this->context->getObjectsAttributeBases($value);
                if ($value instanceof NameExpression) {
                    // what the variable holds, apart from what it prints
                    $sources = $this->context->taintObjectVariable($value);
                    $includedVariables['printed'][$name] = $this->context->taintPrintedVariable($value);
                }

                $includedVariables['keyed'][$name] = $sources;
            } else {
                $includedVariables['unkeyed'] = [...$includedVariables['unkeyed'], ...$sources];
            }
        }

        return $includedVariables;
    }

    /**
     * `include('part.html.twig', variables, with_context)` displays what the included template outputs. Null if which
     * template is included is only known when rendering.
     */
    private function getIncludedOutput(FunctionExpression $expression): ?DataFlowNode
    {
        $template = Context::getArgument($expression, 0, 'template');
        if (!$template instanceof ConstantExpression || !\is_string($template->getAttribute('value'))) {
            return null;
        }

        $withContext = Context::getArgument($expression, 2, 'with_context');

        return $this->context->includeTemplate(
            $expression,
            $template->getAttribute('value'),
            $this->getIncludedVariables(Context::getArgument($expression, 1, 'variables')),
            !$withContext instanceof ConstantExpression || false !== $withContext->getAttribute('value'),
        );
    }

    /**
     * A call of a macro outputs what the macro prints, given the arguments of the call and the objects they hold: what
     * it prints escaped has no taints. Null if the analysis can't tell which macro is called.
     */
    private function getMacroOutput(MacroReferenceExpression $expression): ?DataFlowNode
    {
        $callArguments = $expression->getNode('arguments');
        if (!$callArguments instanceof ArrayExpression) {
            return null;
        }

        $arguments = [];
        foreach ($callArguments->getKeyValuePairs() as ['key' => $key, 'value' => $value]) {
            if ($value instanceof SpreadUnary) {
                // the items of an unpacked array can give any parameter, and hold the objects of the array's items
                $unpacked = $value->getNode('node');
                $bases = [];
                foreach ($unpacked instanceof ArrayExpression ? $unpacked->getKeyValuePairs() : [] as ['value' => $item]) {
                    $bases = [...$bases, ...$this->context->getObjectsAttributeBases($item)];
                }

                $sources = $this->getTaintSources($unpacked);
                $arguments[] = [
                    null,
                    $sources,
                    $unpacked instanceof ArrayExpression ? $bases : $this->context->getObjectsAttributeBases($unpacked, true),
                    $sources,
                ];
                continue;
            }

            // a position, or a name; a variable gives apart what it holds and what it prints
            $position = $key instanceof ConstantExpression ? $key->getAttribute('value') : $key->getAttribute('name');
            $sources = $value instanceof NameExpression ? $this->context->taintObjectVariable($value) : $this->getTaintSources($value);
            $arguments[] = [
                \is_int($position) || ctype_digit((string) $position) ? (int) $position : (string) $position,
                $sources,
                $this->context->getObjectsAttributeBases($value),
                $value instanceof NameExpression ? $this->context->taintPrintedVariable($value) : $sources,
            ];
        }

        return $this->context->callMacro($expression, $arguments);
    }

    private static function isEscapingFilter(FilterExpression $expression): bool
    {
        $filterName = Context::getFilterName($expression);

        return 'escape' === $filterName || 'e' === $filterName;
    }

    /**
     * The nodes of the markup $expression gives, which autoescaping outputs as is: what a macro gives, a variable set
     * to it (see Context::isMarkup()), or a branch of a condition, or the value or the default of `default`, giving
     * it. Anything else, like a concatenation, gives a string, which autoescaping escapes.
     *
     * @return list<DataFlowNode>
     */
    private function getMarkupSources(Node $expression): array
    {
        if ($expression instanceof MacroReferenceExpression || $expression instanceof NameExpression) {
            return array_values(array_filter($this->getTaintSources($expression), $this->context->isMarkup(...)));
        }

        if ($expression->hasNode('test')) {
            // a condition (`a ? b : c`, `a ?? b`, or what `default` gives), whose branches Twig >= 3.17 names left and right
            $sources = [];
            foreach ($expression->hasNode('expr2') ? ['expr2', 'expr3'] : ['left', 'right'] as $branch) {
                $sources = [...$sources, ...($expression->hasNode($branch) ? $this->getMarkupSources($expression->getNode($branch)) : [])];
            }

            return $sources;
        }

        if ($expression instanceof FilterExpression && 'default' === Context::getFilterName($expression)) {
            $arguments = $expression->hasNode('arguments') ? $expression->getNode('arguments') : null;

            return [
                ...$this->getMarkupSources($expression->getNode('node')),
                ...(null !== $arguments && $arguments->hasNode('0') ? $this->getMarkupSources($arguments->getNode('0')) : []),
            ];
        }

        return [];
    }

    /**
     * Whether $expression is the escaping Twig's autoescaping adds, whose third argument is true: it outputs markup,
     * like what a macro gives, as is.
     */
    private static function isAutoescaping(FilterExpression $expression): bool
    {
        $arguments = $expression->hasNode('arguments') ? $expression->getNode('arguments') : null;
        $autoescape = null !== $arguments && $arguments->hasNode('2') ? $arguments->getNode('2') : null;

        return $autoescape instanceof ConstantExpression && true === $autoescape->getAttribute('value');
    }
}
