<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * `$validator->validate($value, [new Assert\Regex($pattern)])` checks `$value` against the pattern: once the list of
 * violations it returns is empty (`count($violations) === 0`), the variable it was given can't carry the taints the
 * pattern removes (see Codebase::addTaintValidation()).
 */
final class ValidatorRegexTaintHandler implements AfterExpressionAnalysisInterface
{
    /** The arguments of a Regex constraint that don't change what it accepts */
    private const PATTERN_ARGUMENTS = ['pattern', 'message', 'htmlPattern'];

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        $source = $event->getStatementsSource();

        if (!$expr instanceof MethodCall
            || !$expr->name instanceof Identifier
            || 'validate' !== $expr->name->toLowerString()
            || $expr->isFirstClassCallable()
            || !self::isOfClass($source, $expr, [ConstraintViolationListInterface::class, ConstraintViolationList::class])
        ) {
            return null;
        }

        $value = self::getArgument($expr->getArgs(), 0, 'value');
        $constraints = self::getArgument($expr->getArgs(), 1, 'constraints');
        $groups = self::getArgument($expr->getArgs(), 2, 'groups');

        // a constraint of the Default group isn't checked when other groups are validated
        if (null === $value || null === $constraints || (null !== $groups && !self::isNull($groups))) {
            return null;
        }

        $codebase = $event->getCodebase();
        $removed_taints = 0;
        foreach ($constraints instanceof Array_ ? $constraints->items : [$constraints] as $constraint) {
            $constraint_expr = $constraint instanceof Expr ? $constraint : $constraint->value;
            $pattern = self::getPattern($source, $constraint_expr);

            if (null !== $pattern) {
                $removed_taints |= $codebase->getTaintsRemovedByPattern($pattern);
            }
        }

        $codebase->addTaintValidation($source, $expr, $value, $removed_taints, 'violations');

        return null;
    }

    /**
     * The pattern a Regex constraint requires the value to match, if it checks the value as given in the Default group.
     */
    private static function getPattern(StatementsSource $source, Expr $constraint): ?string
    {
        if (!$constraint instanceof New_ || $constraint->isFirstClassCallable()) {
            return null;
        }

        if (!self::isOfClass($source, $constraint, [Regex::class])) {
            return null;
        }

        $pattern = null;
        foreach ($constraint->getArgs() as $offset => $arg) {
            $name = $arg->name?->name ?? (self::PATTERN_ARGUMENTS[$offset] ?? null);

            // `match: false`, a normalizer or other groups
            if ($arg->unpack || !\in_array($name, self::PATTERN_ARGUMENTS, true)) {
                return null;
            }

            if ('pattern' !== $name) {
                continue;
            }

            if ($arg->value instanceof Array_) {
                // the options of Symfony < 8
                foreach ($arg->value->items as $item) {
                    $option = null === $item->key ? null : self::getLiteral($source, $item->key);

                    if (null === $option || $item->unpack || !\in_array($option, self::PATTERN_ARGUMENTS, true)) {
                        return null;
                    }

                    if ('pattern' === $option) {
                        $pattern = self::getLiteral($source, $item->value);
                    }
                }
            } else {
                $pattern = self::getLiteral($source, $arg->value);
            }
        }

        return $pattern;
    }

    /**
     * @param array<Arg> $args
     */
    private static function getArgument(array $args, int $offset, string $name): ?Expr
    {
        foreach ($args as $arg_offset => $arg) {
            if ($arg->unpack) {
                return null;
            }

            if (null === $arg->name ? $arg_offset === $offset : $arg->name->name === $name) {
                return $arg->value;
            }
        }

        return null;
    }

    /**
     * @param non-empty-list<class-string> $classes
     */
    private static function isOfClass(StatementsSource $source, Expr $expr, array $classes): bool
    {
        $type = $source->getNodeTypeProvider()->getType($expr);
        $atomic = $type?->isSingle() ? $type->getSingleAtomic() : null;

        return $atomic instanceof TNamedObject && \in_array($atomic->value, $classes, true);
    }

    private static function isNull(Expr $expr): bool
    {
        return $expr instanceof ConstFetch && 'null' === $expr->name->toLowerString();
    }

    private static function getLiteral(StatementsSource $source, Expr $expr): ?string
    {
        $type = $source->getNodeTypeProvider()->getType($expr);

        return null !== $type && $type->isSingleStringLiteral() ? $type->getSingleStringLiteral()->value : null;
    }
}
