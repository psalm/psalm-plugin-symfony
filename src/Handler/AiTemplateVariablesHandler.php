<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Taints the message templates of Symfony AI with the variables given in the `template_vars` option of the calls
 * rendering them, so the sinks the templates reach (such as a system prompt) report the variables.
 *
 * The templates are rendered when the call runs, from messages built anywhere: a template holds what every call gives
 * to the variables it has a placeholder for.
 */
final class AiTemplateVariablesHandler implements AfterMethodCallAnalysisInterface, AfterExpressionAnalysisInterface
{
    private const TEMPLATE_CLASS = 'Symfony\AI\Platform\Message\Template';

    private const TEMPLATE_VARS = 'template_vars';

    /**
     * The methods given the options rendering the templates: those of the class, of the classes extending it and of
     * the classes implementing it, with the argument giving the options, counted from 0, and its name.
     *
     * @var list<array{class: string, method: lowercase-string, argument: int<0, max>, name: string}>
     */
    private const OPTIONS_METHODS = [
        ['class' => 'Symfony\AI\Platform\PlatformInterface', 'method' => 'invoke', 'argument' => 2, 'name' => 'options'],
        ['class' => 'Symfony\AI\Agent\AgentInterface', 'method' => 'call', 'argument' => 1, 'name' => 'options'],
        ['class' => 'Symfony\AI\Agent\Input', 'method' => 'setoptions', 'argument' => 0, 'name' => 'options'],
        ['class' => 'Symfony\AI\Platform\Event\InvocationEvent', 'method' => 'setoptions', 'argument' => 0, 'name' => 'options'],
    ];

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $codebase = $event->getCodebase();
        $statementsSource = $event->getStatementsSource();
        if (!$codebase->tracksTaintFlow($statementsSource)) {
            return;
        }

        [$calledClass, $calledMethod] = explode('::', $event->getMethodId());
        $optionsMethod = self::findOptionsMethod($codebase, $calledClass, strtolower($calledMethod));
        if (null === $optionsMethod) {
            return;
        }

        $expr = $event->getExpr();
        if ($expr->isFirstClassCallable()) {
            return;
        }

        $optionsArgument = self::findArgument($expr->getArgs(), $optionsMethod['argument'], $optionsMethod['name']);
        if (null === $optionsArgument) {
            return;
        }

        $options = $statementsSource->getNodeTypeProvider()->getType($optionsArgument->value);
        if (null === $options || [] === $options->parent_nodes) {
            return;
        }

        $location = new CodeLocation($statementsSource, $optionsArgument->value);
        $templateVars = $codebase->getTaintNode($statementsSource, self::TEMPLATE_VARS, $location);
        foreach ($options->parent_nodes as $optionsNode) {
            $codebase->addTaintPath($statementsSource, $optionsNode, $templateVars, "arrayvalue-fetch-'".self::TEMPLATE_VARS."'");
        }

        $variableNames = self::getVariableNames($options);
        if (null === $variableNames) {
            $codebase->addTaintPath($statementsSource, $templateVars, self::getForUnknownVariables(), 'arrayvalue-fetch');
        } else {
            foreach ($variableNames as $variableName) {
                $codebase->addTaintPath($statementsSource, $templateVars, self::getForVariable($variableName), "arrayvalue-fetch-'".$variableName."'");
            }
        }

        $codebase->addTaintPath($statementsSource, $templateVars, self::getForAnyVariable(), 'arrayvalue-fetch');
    }

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        if ((!$expr instanceof StaticCall && !$expr instanceof New_) || $expr->isFirstClassCallable()) {
            return null;
        }

        $codebase = $event->getCodebase();
        $statementsSource = $event->getStatementsSource();
        $nodeTypeProvider = $statementsSource->getNodeTypeProvider();
        $template = $nodeTypeProvider->getType($expr);
        if (null === $template || !self::isTemplate($template) || !$codebase->tracksTaintFlow($statementsSource)) {
            return null;
        }

        $placeholders = self::getPlaceholders($statementsSource, $expr);
        if ([] === $placeholders) {
            return null;
        }

        $location = new CodeLocation($statementsSource, $expr);
        $node = $codebase->getTaintNode($statementsSource, 'template', $location, $template);
        if (null === $placeholders) {
            $codebase->addTaintPath($statementsSource, self::getForAnyVariable(), $node);
        } else {
            foreach ($placeholders as $placeholder) {
                $codebase->addTaintPath($statementsSource, self::getForVariable($placeholder), $node);
            }

            $codebase->addTaintPath($statementsSource, self::getForUnknownVariables(), $node);
        }

        $nodeTypeProvider->setType($expr, $template->setParentNodes([$node->id => $node]));

        return null;
    }

    /**
     * What the variable $name holds in the calls naming it.
     */
    private static function getForVariable(string $name): DataFlowNode
    {
        return DataFlowNode::getForPropertyFetch(self::TEMPLATE_CLASS.' '.self::TEMPLATE_VARS."['".$name."']");
    }

    /**
     * What the variables hold in the calls whose variable names aren't known.
     */
    private static function getForUnknownVariables(): DataFlowNode
    {
        return DataFlowNode::getForPropertyFetch(self::TEMPLATE_CLASS.' '.self::TEMPLATE_VARS.'[]');
    }

    /**
     * What any variable holds in any call, for the templates whose placeholders aren't known.
     */
    private static function getForAnyVariable(): DataFlowNode
    {
        return DataFlowNode::getForPropertyFetch(self::TEMPLATE_CLASS.' '.self::TEMPLATE_VARS);
    }

    /**
     * @param lowercase-string $calledMethod
     *
     * @return array{class: string, method: lowercase-string, argument: int<0, max>, name: string}|null
     */
    private static function findOptionsMethod(Codebase $codebase, string $calledClass, string $calledMethod): ?array
    {
        foreach (self::OPTIONS_METHODS as $optionsMethod) {
            if ($optionsMethod['method'] === $calledMethod
                && (
                    0 === strcasecmp($optionsMethod['class'], $calledClass)
                    || $codebase->classExtendsOrImplements($calledClass, $optionsMethod['class'])
                    || $codebase->interfaceExtends($calledClass, $optionsMethod['class'])
                )
            ) {
                return $optionsMethod;
            }
        }

        return null;
    }

    /**
     * @param array<Arg>  $args
     * @param int<0, max> $position
     */
    private static function findArgument(array $args, int $position, string $name): ?Arg
    {
        foreach (array_values($args) as $offset => $arg) {
            if ($arg->unpack) {
                return null;
            }

            if (null === $arg->name ? $offset === $position : $arg->name->name === $name) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * The names of the variables the options give, or null if they aren't all known.
     *
     * @return list<string>|null
     */
    private static function getVariableNames(Union $options): ?array
    {
        $names = [];
        foreach ($options->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TKeyedArray) {
                return null;
            }

            $templateVars = $atomic->properties[self::TEMPLATE_VARS] ?? null;
            if (null === $templateVars) {
                if (null !== $atomic->fallback_params) {
                    return null;
                }

                continue;
            }

            foreach ($templateVars->getAtomicTypes() as $templateVarsAtomic) {
                if (!$templateVarsAtomic instanceof TKeyedArray || null !== $templateVarsAtomic->fallback_params) {
                    return null;
                }

                foreach (array_keys($templateVarsAtomic->properties) as $name) {
                    $names[] = (string) $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private static function isTemplate(Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && 0 === strcasecmp($atomic->value, self::TEMPLATE_CLASS)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The variables a template built by $expr reads, or null if they aren't known: those of the placeholders of a
     * string template, a dotted placeholder (`{a.b}`) reading the variable of each of its prefixes.
     *
     * @return list<string>|null
     */
    private static function getPlaceholders(StatementsSource $statementsSource, StaticCall|New_ $expr): ?array
    {
        $args = $expr->getArgs();
        if ($expr instanceof StaticCall) {
            if (!$expr->name instanceof Identifier || 'string' !== $expr->name->toLowerString()) {
                return null;
            }
        } else {
            $type = self::findArgument($args, 1, 'type');
            $typeLiterals = null === $type ? null : self::getLiteralStrings($statementsSource, $type);
            if (['string'] !== $typeLiterals) {
                return null;
            }
        }

        $content = self::findArgument($args, 0, 'template');
        $contents = null === $content ? null : self::getLiteralStrings($statementsSource, $content);
        if (null === $contents) {
            return null;
        }

        $variables = [];
        foreach ($contents as $literal) {
            preg_match_all('/\{([^{}]+)\}/', $literal, $matches);
            foreach ($matches[1] as $placeholder) {
                $prefix = '';
                foreach (explode('.', $placeholder) as $part) {
                    $prefix = '' === $prefix ? $part : $prefix.'.'.$part;
                    $variables[] = $prefix;
                }
            }
        }

        return array_values(array_unique($variables));
    }

    /**
     * @return list<string>|null
     */
    private static function getLiteralStrings(StatementsSource $statementsSource, Arg $arg): ?array
    {
        $type = $statementsSource->getNodeTypeProvider()->getType($arg->value);
        if (null === $type) {
            return null;
        }

        $literals = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TLiteralString) {
                return null;
            }

            $literals[] = $atomic->value;
        }

        return array_values(array_unique($literals));
    }
}
