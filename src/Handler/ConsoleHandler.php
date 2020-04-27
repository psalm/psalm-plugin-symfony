<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr;
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
                self::analyseArgument($expr, $statements_source);
                break;
            case 'Symfony\Component\Console\Input\InputInterface::getargument':
                $argumentName = $expr->args[0]->value;
                if (!$argumentName instanceof String_) {
                    break;
                }
                $argumentNameValue = $argumentName->value;
                if (isset(self::$arguments[$argumentNameValue])) {
                    $return_type_candidate = self::$arguments[$argumentNameValue];
                }
                break;
            case 'Symfony\Component\Console\Command\Command::addoption':
                /** @psalm-suppress PossiblyInvalidArgument */
                self::analyseOption($expr, $statements_source);
                break;
            case 'Symfony\Component\Console\Input\InputInterface::getoption':
                $optionName = $expr->args[0]->value;
                if (!$optionName instanceof String_) {
                    break;
                }
                $optionNameValue = $optionName->value;
                if (isset(self::$options[$optionNameValue])) {
                    $return_type_candidate = self::$options[$optionNameValue];
                }
                break;
        }
    }

    private static function analyseArgument(Expr\MethodCall $expr, StatementsSource $statements_source): void
    {
        if (count($expr->args) > 1) {
            try {
                $mode = self::getModeValue($expr->args[1]->value);
            } catch (InvalidConsoleModeException $e) {
                IssueBuffer::accepts(
                    new InvalidConsoleArgumentValue(new CodeLocation($statements_source, $expr->args[1]->value)),
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

        /** @var String_ $argumentName */
        $argumentName = $expr->args[0]->value;

        self::$arguments[$argumentName->value] = $returnTypes;
    }

    private static function analyseOption(Expr\MethodCall $expr, StatementsSource $statements_source): void
    {
        if (isset($expr->args[2])) {
            try {
                $mode = self::getModeValue($expr->args[2]->value);
            } catch (InvalidConsoleModeException $e) {
                IssueBuffer::accepts(
                    new InvalidConsoleOptionValue(new CodeLocation($statements_source, $expr->args[2]->value)),
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

        /** @var String_ $optionName */
        $optionName = $expr->args[0]->value;

        self::$options[$optionName->value] = $returnTypes;
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
}
