<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
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

class ConsoleHandler implements AfterMethodCallAnalysisInterface
{
    /**
     * @var Union[]
     */
    private static $arguments = [];
    /**
     * @var Union[]
     */
    private static $options = [];

    /**
     * {@inheritdoc}
     */
    public static function afterMethodCallAnalysis(
        Expr $expr,
        string $method_id,
        string $appearing_method_id,
        string $declaring_method_id,
        Context $context,
        StatementsSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = [],
        Union &$return_type_candidate = null
    ) {
        switch ($declaring_method_id) {
            case 'Symfony\Component\Console\Command\Command::addargument':
                /** @psalm-suppress PossiblyInvalidArgument */
                self::analyseArgument($expr->args, $statements_source);
                break;
            case 'Symfony\Component\Console\Input\InputInterface::getargument':
                $identifier = self::getNodeIdentifier($expr->args[0]->value);
                if (!$identifier) {
                    break;
                }

                if (isset(self::$arguments[$identifier])) {
                    $return_type_candidate = self::$arguments[$identifier];
                }
                break;
            case 'Symfony\Component\Console\Command\Command::addoption':
                /** @psalm-suppress PossiblyInvalidArgument */
                self::analyseOption($expr->args, $statements_source);
                break;
            case 'Symfony\Component\Console\Input\InputInterface::getoption':
                $identifier = self::getNodeIdentifier($expr->args[0]->value);
                if (!$identifier) {
                    break;
                }

                if (isset(self::$options[$identifier])) {
                    $return_type_candidate = self::$options[$identifier];
                }
                break;
            case 'Symfony\Component\Console\Command\Command::setdefinition':
                $inputItems = [];
                $definition = $expr->args[0]->value;
                if ($definition instanceof Expr\Array_) {
                    $inputItems = $definition->items;
                } elseif ($definition instanceof Expr\New_) {
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
                        switch ($inputItem->value->class->getAttribute('resolvedName')) {
                            case InputArgument::class:
                                self::analyseArgument($inputItem->value->args, $statements_source);
                                break;
                            case InputOption::class:
                                self::analyseOption($inputItem->value->args, $statements_source);
                                break;
                        }
                    }
                }

                break;
        }
    }

    /**
     * @param Arg[] $args
     * @param StatementsSource $statements_source
     */
    private static function analyseArgument(array $args, StatementsSource $statements_source): void
    {
        if (count($args) > 1) {
            try {
                $mode = self::getModeValue($args[1]->value);
            } catch (InvalidConsoleModeException $e) {
                IssueBuffer::accepts(
                    new InvalidConsoleArgumentValue(new CodeLocation($statements_source, $args[1]->value)),
                    $statements_source->getSuppressedIssues()
                );

                return;
            }
        } else {
            $mode = InputArgument::OPTIONAL;
        }

        $returnTypes = new Union([new TString(), new TNull()]);

        if ($mode & InputArgument::REQUIRED) {
            $returnTypes->removeType('null');
        }

        if ($mode & InputArgument::IS_ARRAY) {
            $returnTypes->removeType('string');
            $returnTypes->addType(new TArray([new Union([new TInt()]), new Union([new TString()])]));
        }

        $identifier = self::getNodeIdentifier($args[0]->value);
        if (!$identifier) {
            return;
        }

        self::$arguments[$identifier] = $returnTypes;
    }

    /**
     * @param Arg[] $args
     * @param StatementsSource $statements_source
     */
    private static function analyseOption(array $args, StatementsSource $statements_source): void
    {
        if (isset($args[2])) {
            try {
                $mode = self::getModeValue($args[2]->value);
            } catch (InvalidConsoleModeException $e) {
                IssueBuffer::accepts(
                    new InvalidConsoleOptionValue(new CodeLocation($statements_source, $args[2]->value)),
                    $statements_source->getSuppressedIssues()
                );

                return;
            }
        } else {
            $mode = InputOption::VALUE_OPTIONAL;
        }

        $returnTypes = new Union([new TString(), new TNull()]);

        if ($mode & InputOption::VALUE_NONE) {
            $returnTypes = new Union([new TBool()]);
        }

        if ($mode & InputOption::VALUE_REQUIRED) {
            $returnTypes->removeType('null');
        }

        if ($mode & InputOption::VALUE_IS_ARRAY) {
            $returnTypes->removeType('string');
            $returnTypes->addType(new TArray([new Union([new TInt()]), new Union([new TString()])]));
        }

        $identifier = self::getNodeIdentifier($args[0]->value);
        if (!$identifier) {
            return;
        }

        self::$options[$identifier] = $returnTypes;
    }

    /**
     * @param mixed $mode
     */
    private static function getModeValue($mode): int
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
