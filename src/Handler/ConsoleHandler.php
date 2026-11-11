<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\FileManipulation;
use Psalm\Issue\CodeIssue;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\SymfonyPsalmPlugin\Exception\InvalidConsoleModeException;
use Psalm\SymfonyPsalmPlugin\Issue\InvalidConsoleArgumentValue;
use Psalm\SymfonyPsalmPlugin\Issue\InvalidConsoleOptionValue;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\MutableUnion;
use Psalm\Type\Union;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Webmozart\Assert\Assert;

final class ConsoleHandler implements AfterMethodCallAnalysisInterface
{
    /**
     * @var Union[]
     */
    private static array $arguments = [];
    /**
     * @var Union[]
     */
    private static array $options = [];

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $expr = $event->getExpr();
        $declaring_method_id = $event->getDeclaringMethodId();

        $args = [];
        foreach ($expr->args as $arg) {
            if ($arg instanceof Arg) {
                $args[] = $arg;
            }
        }

        switch ($declaring_method_id) {
            case 'Symfony\Component\Console\Command\Command::addargument':
                self::analyseArgument($args, $event);
                break;
            case 'Symfony\Component\Console\Input\InputInterface::getargument':
                $identifier = self::getNodeIdentifier($args[0]->value);
                if (null === $identifier) {
                    break;
                }

                if (isset(self::$arguments[$identifier])) {
                    $event->setReturnTypeCandidate(self::$arguments[$identifier]);
                }
                break;
            case 'Symfony\Component\Console\Command\Command::addoption':
                self::analyseOption($args, $event);
                break;
            case 'Symfony\Component\Console\Input\InputInterface::getoption':
                $identifier = self::getNodeIdentifier($args[0]->value);
                if (null === $identifier) {
                    break;
                }

                if (isset(self::$options[$identifier])) {
                    $event->setReturnTypeCandidate(self::$options[$identifier]);
                }
                break;
            case 'Symfony\Component\Console\Command\Command::setdefinition':
                $inputItems = [];
                $definition = $args[0]->value;
                if ($definition instanceof Expr\Array_) {
                    $inputItems = $definition->items;
                } elseif ($definition instanceof Expr\New_ && isset($definition->args[0]->value)) {
                    $inputDefinition = $definition->args[0]->value;
                    if ($inputDefinition instanceof Expr\Array_) {
                        $inputItems = $inputDefinition->items;
                    }
                }

                if (empty($inputItems)) {
                    break;
                }

                foreach ($inputItems as $inputItem) {
                    if ($inputItem->value instanceof Expr\New_) {
                        $args = [];
                        foreach ($inputItem->value->args as $arg) {
                            if ($arg instanceof Arg) {
                                $args[] = $arg;
                            }
                        }

                        switch ($inputItem->value->class->getAttribute('resolvedName')) {
                            case InputArgument::class:
                                self::analyseArgument($args, $event);
                                break;
                            case InputOption::class:
                                self::analyseOption($args, $event);
                                break;
                        }
                    }
                }

                break;
        }
    }

    /**
     * @param Arg[] $args
     */
    private static function analyseArgument(array $args, AfterMethodCallAnalysisEvent $event): void
    {
        $statements_source = $event->getStatementsSource();
        $normalizedParams = self::normalizeArgumentParams($args);

        $identifier = self::getNodeIdentifier($normalizedParams['name']->value);
        if (null === $identifier) {
            return;
        }

        $modeParam = $normalizedParams['mode'];
        if ($modeParam) {
            try {
                $mode = self::getModeValue($modeParam->value);
            } catch (InvalidConsoleModeException $e) {
                self::reportInvalidMode(
                    $event,
                    $modeParam->value,
                    new InvalidConsoleArgumentValue(new CodeLocation($statements_source, $modeParam->value)),
                    InputArgument::class,
                    [1 => 'REQUIRED', 2 => 'OPTIONAL', 4 => 'IS_ARRAY'],
                );

                return;
            }
        } else {
            $mode = InputArgument::OPTIONAL;
        }

        if ($mode & InputArgument::IS_ARRAY) {
            $returnTypes = new MutableUnion([new TArray([new Union([new TInt()]), new Union([new TString()])])]);
        } elseif ($mode & InputArgument::REQUIRED) {
            $returnTypes = new MutableUnion([new TString()]);
        } else {
            $returnTypes = new MutableUnion([new TString(), new TNull()]);
        }

        $defaultParam = $normalizedParams['default'];
        if ($defaultParam && (!$defaultParam->value instanceof Expr\ConstFetch || 'null' !== $defaultParam->value->name->getFirst())) {
            $returnTypes->removeType('null');
        }

        self::$arguments[$identifier] = $returnTypes->freeze();
    }

    /**
     * @param Arg[] $args
     */
    private static function analyseOption(array $args, AfterMethodCallAnalysisEvent $event): void
    {
        $statements_source = $event->getStatementsSource();
        $normalizedParams = self::normalizeOptionParams($args);

        $identifier = self::getNodeIdentifier($normalizedParams['name']->value);
        if (null === $identifier) {
            return;
        }

        if (0 === strpos($identifier, '--')) {
            $identifier = substr($identifier, 2);
        }

        $modeOption = $normalizedParams['mode'];
        if ($modeOption) {
            try {
                $mode = self::getModeValue($modeOption->value);
            } catch (InvalidConsoleModeException $e) {
                self::reportInvalidMode(
                    $event,
                    $modeOption->value,
                    new InvalidConsoleOptionValue(new CodeLocation($statements_source, $modeOption->value)),
                    InputOption::class,
                    [1 => 'VALUE_NONE', 2 => 'VALUE_REQUIRED', 4 => 'VALUE_OPTIONAL', 8 => 'VALUE_IS_ARRAY', 16 => 'VALUE_NEGATABLE'],
                );

                return;
            }
        } else {
            $mode = InputOption::VALUE_OPTIONAL;
        }

        $returnTypes = new MutableUnion([new TString(), new TNull()]);

        $defaultParam = $normalizedParams['default'];
        if ($defaultParam) {
            if (0 === ($mode & InputOption::VALUE_OPTIONAL)) {
                $returnTypes->removeType('null');
            }

            if ($defaultParam->value instanceof Expr\ConstFetch) {
                switch ($defaultParam->value->name->getFirst()) {
                    case 'null':
                        $returnTypes->addType(new TNull());
                        break;
                    case 'false':
                    case 'true':
                        $returnTypes->addType(new TBool());
                        break;
                }
            }
        }

        if ($mode & InputOption::VALUE_NONE) {
            $returnTypes = new MutableUnion([new TBool()]);
        }

        if ($mode & InputOption::VALUE_REQUIRED && $mode & InputOption::VALUE_IS_ARRAY) {
            $returnTypes->removeType('null');
        }

        if ($mode & InputOption::VALUE_IS_ARRAY) {
            $returnTypes = new MutableUnion([new TArray([new Union([new TInt()]), $returnTypes->freeze()])]);
        }

        self::$options[$identifier] = $returnTypes->freeze();
    }

    /**
     * Reports the invalid mode; when it is a plain integer literal made only of known constant bits,
     * Psalter can rewrite it to the equivalent constant expression.
     *
     * @param class-string       $constantsClass
     * @param array<int, string> $constantNames  bit value => constant name
     */
    private static function reportInvalidMode(
        AfterMethodCallAnalysisEvent $event,
        Expr $modeValue,
        CodeIssue $issue,
        string $constantsClass,
        array $constantNames,
    ): void {
        $statements_source = $event->getStatementsSource();
        $suppressedIssues = $statements_source->getSuppressedIssues();

        $replacement = $modeValue instanceof Int_
            ? self::buildModeReplacement($modeValue->value, $constantsClass, $constantNames, $statements_source)
            : null;

        if (null !== $replacement && $event->getCodebase()->shouldFixIssue($issue, $suppressedIssues)) {
            $event->setFileReplacements([
                ...$event->getFileReplacements(),
                new FileManipulation($modeValue->getStartFilePos(), $modeValue->getEndFilePos() + 1, $replacement),
            ]);

            return;
        }

        IssueBuffer::accepts($issue, $suppressedIssues, null !== $replacement);
    }

    /**
     * @param class-string       $constantsClass
     * @param array<int, string> $constantNames  bit value => constant name, ascending
     */
    private static function buildModeReplacement(
        int $literal,
        string $constantsClass,
        array $constantNames,
        StatementsSource $statements_source,
    ): ?string {
        if ($literal <= 0) {
            return null;
        }

        $covered = 0;
        $parts = [];
        foreach ($constantNames as $value => $name) {
            // Some constants only exist in newer Symfony versions (e.g. VALUE_NEGATABLE since 5.3).
            if (0 === ($literal & $value) || !defined($constantsClass.'::'.$name)) {
                continue;
            }

            $covered |= $value;
            $parts[] = $name;
        }

        if ($covered !== $literal) {
            return null;
        }

        $class = ClassNamePrinter::print($constantsClass, $statements_source);

        return implode(' | ', array_map(static fn (string $name): string => $class.'::'.$name, $parts));
    }

    /**
     * @param array<Arg> $args
     *
     * @psalm-return array{name: Arg, shortcut: ?Arg, mode: ?Arg, description: ?Arg, default: ?Arg}
     *
     * @psalm-pure
     */
    private static function normalizeOptionParams(array $args): array
    {
        return self::normalizeParams(['name', 'shortcut', 'mode', 'description', 'default', 'suggestedValues'], $args);
    }

    /**
     * @param array<Arg> $args
     *
     * @psalm-return array{name: Arg, mode: ?Arg, description: ?Arg, default: ?Arg}
     *
     * @psalm-pure
     */
    private static function normalizeArgumentParams(array $args): array
    {
        return self::normalizeParams(['name', 'mode', 'description', 'default', 'suggestedValues'], $args);
    }

    /**
     * @psalm-pure
     */
    private static function normalizeParams(array $params, array $args): array
    {
        $result = array_fill_keys($params, null);
        foreach ($args as $arg) {
            if ($arg->name) {
                $name = $arg->name->name;

                $key = array_search($name, $params);
                Assert::integer($key);
                unset($params[$key]);
            } else {
                $name = array_shift($params);
            }

            $result[$name] = $arg;
        }

        return $result;
    }

    private static function getModeValue(Expr $mode): ?int
    {
        if ($mode instanceof Expr\BinaryOp\BitwiseOr) {
            return self::getModeValue($mode->left) | self::getModeValue($mode->right);
        }

        if ($mode instanceof Expr\ClassConstFetch) {
            /**
             * @psalm-suppress MixedAssignment
             * @psalm-suppress MixedOperand
             * @psalm-suppress UndefinedPropertyFetch
             */
            $value = constant($mode->class->getAttribute('resolvedName').'::'.$mode->name->name);
            if (!is_int($value)) {
                throw new InvalidConsoleModeException();
            }

            return $value;
        }

        if ($mode instanceof Expr\Ternary) {
            return null;
        }

        throw new InvalidConsoleModeException();
    }

    /**
     * @psalm-capabilities read-props
     */
    private static function getNodeIdentifier(Expr $expr): ?string
    {
        if ($expr instanceof String_) {
            return $expr->value;
        }

        if ($expr instanceof Expr\ClassConstFetch) {
            $name = $expr->name;
            if ($name instanceof Identifier) {
                return $name->name;
            }
        }

        return null;
    }
}
