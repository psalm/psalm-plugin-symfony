<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
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
use Psalm\Type\Union;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Webmozart\Assert\Assert;

class ConsoleHandler implements AfterMethodCallAnalysisInterface
{
    /**
     * @var Union[]
     */
    private static array $arguments = [];
    /**
     * @var Union[]
     */
    private static array $options = [];

    /**
     * {@inheritdoc}
     */
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $statements_source = $event->getStatementsSource();
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
                self::analyseArgument($args, $statements_source);
                break;
            case 'Symfony\Component\Console\Input\InputInterface::getargument':
                $identifier = self::getNodeIdentifier($args[0]->value);
                if (!$identifier) {
                    break;
                }

                if (isset(self::$arguments[$identifier])) {
                    $event->setReturnTypeCandidate(self::$arguments[$identifier]);
                }
                break;
            case 'Symfony\Component\Console\Command\Command::addoption':
                self::analyseOption($args, $statements_source);
                break;
            case 'Symfony\Component\Console\Input\InputInterface::getoption':
                $identifier = self::getNodeIdentifier($args[0]->value);
                if (!$identifier) {
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
                    if ($inputItem instanceof Expr\ArrayItem && $inputItem->value instanceof Expr\New_) {
                        $args = [];
                        foreach ($inputItem->value->args as $arg) {
                            if ($arg instanceof Arg) {
                                $args[] = $arg;
                            }
                        }

                        switch ($inputItem->value->class->getAttribute('resolvedName')) {
                            case InputArgument::class:
                                self::analyseArgument($args, $statements_source);
                                break;
                            case InputOption::class:
                                self::analyseOption($args, $statements_source);
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
    private static function analyseArgument(array $args, StatementsSource $statements_source): void
    {
        $normalizedParams = self::normalizeArgumentParams($args);

        $identifier = self::getNodeIdentifier($normalizedParams['name']->value);
        if (!$identifier) {
            return;
        }

        $modeParam = $normalizedParams['mode'];
        if ($modeParam) {
            try {
                $mode = self::getModeValue($modeParam->value);
            } catch (InvalidConsoleModeException $e) {
                IssueBuffer::accepts(
                    new InvalidConsoleArgumentValue(new CodeLocation($statements_source, $modeParam->value)),
                    $statements_source->getSuppressedIssues()
                );

                return;
            }
        } else {
            $mode = InputArgument::OPTIONAL;
        }

        $add_null = false;
        if ($mode & InputArgument::IS_ARRAY) {
            $returnTypes = [new TArray([new Union([new TInt()]), new Union([new TString()])])];
        } elseif ($mode & InputArgument::REQUIRED) {
            $returnTypes = [new TString()];
        } else {
            $add_null = true;
            $returnTypes = [new TString()];
        }

        $defaultParam = $normalizedParams['default'];
        if ($defaultParam) {
            $add_null = false;
            if ($defaultParam->value instanceof Expr\ConstFetch && 'null' === $defaultParam->value->name->parts[0]) {
                $add_null = true;
            }
        }

        if ($add_null) {
            $returnTypes[] = new TNull();
        }

        self::$arguments[$identifier] = new Union($returnTypes);
    }

    /**
     * @param Arg[] $args
     */
    private static function analyseOption(array $args, StatementsSource $statements_source): void
    {
        $normalizedParams = self::normalizeOptionParams($args);

        $identifier = self::getNodeIdentifier($normalizedParams['name']->value);
        if (!$identifier) {
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
                IssueBuffer::accepts(
                    new InvalidConsoleOptionValue(new CodeLocation($statements_source, $modeOption->value)),
                    $statements_source->getSuppressedIssues()
                );

                return;
            }
        } else {
            $mode = InputOption::VALUE_OPTIONAL;
        }

        $add_null = true;
        $returnTypes = [new TString()];

        $defaultParam = $normalizedParams['default'];
        if ($defaultParam) {
            if (0 === ($mode & InputOption::VALUE_OPTIONAL)) {
                $add_null = false;
            }

            if ($defaultParam->value instanceof Expr\ConstFetch) {
                switch ($defaultParam->value->name->parts[0]) {
                    case 'null':
                        $add_null = true;
                        break;
                    case 'false':
                    case 'true':
                        $returnTypes[] = new TBool();
                        break;
                }
            }
        }

        if ($mode & InputOption::VALUE_REQUIRED && $mode & InputOption::VALUE_IS_ARRAY) {
            $add_null = false;
        }

        if ($add_null) {
            $returnTypes[] = new TNull();
        }

        if ($mode & InputOption::VALUE_NONE) {
            $returnTypes = [new TBool()];
        }

        if ($mode & InputOption::VALUE_IS_ARRAY) {
            $returnTypes = [new TArray([new Union([new TInt()]), new Union($returnTypes)])];
        }

        self::$options[$identifier] = new Union($returnTypes);
    }

    /**
     * @param array<Arg> $args
     *
     * @psalm-return array{name: Arg, shortcut: ?Arg, mode: ?Arg, description: ?Arg, default: ?Arg}
     */
    private static function normalizeOptionParams(array $args): array
    {
        return self::normalizeParams(['name', 'shortcut', 'mode', 'description', 'default'], $args);
    }

    /**
     * @param array<Arg> $args
     *
     * @psalm-return array{name: Arg, mode: ?Arg, description: ?Arg, default: ?Arg}
     */
    private static function normalizeArgumentParams(array $args): array
    {
        return self::normalizeParams(['name', 'mode', 'description', 'default'], $args);
    }

    private static function normalizeParams(array $params, array $args): array
    {
        $result = array_fill_keys($params, null);
        foreach ($args as $arg) {
            if ($arg->name) {
                $name = $arg->name->name;

                $key = array_search($name, $params);
                Assert::integer($key);
                $params = array_slice($params, $key + 1);
            } else {
                $name = array_shift($params);
            }

            $result[$name] = $arg;
        }

        return $result;
    }

    /**
     * @param mixed $mode
     */
    private static function getModeValue($mode): ?int
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
