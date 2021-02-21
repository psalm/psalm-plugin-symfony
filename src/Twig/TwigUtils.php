<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use Psalm\StatementsSource;
use Psalm\SymfonyPsalmPlugin\Exception\TemplateNameUnresolvedException;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

class TwigUtils
{
    public static function extractTemplateNameFromExpression(Expr $templateName, StatementsSource $source): string
    {
        return self::resolveStringFromExpression($templateName, $source);
    }

    private static function resolveStringFromExpression(Expr $templateName, StatementsSource $source): string
    {
        if ($templateName instanceof Expr\BinaryOp\Concat) {
            $right = self::resolveStringFromExpression($templateName->right, $source);
            $left = self::resolveStringFromExpression($templateName->left, $source);

            return $left.$right;
        }

        if ($templateName instanceof Variable) {
            $type = $source->getNodeTypeProvider()->getType($templateName) ?? new Union([new TNull()]);
            $templateName = array_values($type->getAtomicTypes())[0];
        }

        if (!$templateName instanceof String_ && !$templateName instanceof TLiteralString) {
            throw new TemplateNameUnresolvedException(sprintf('Can not retrieve template name from given expression (%s)', get_class($templateName)));
        }

        return $templateName->value;
    }
}
