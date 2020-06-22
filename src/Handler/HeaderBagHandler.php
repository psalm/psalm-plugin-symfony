<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

class HeaderBagHandler implements AfterMethodCallAnalysisInterface
{
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
        if ('Symfony\Component\HttpFoundation\HeaderBag::get' === $declaring_method_id) {
            if ($return_type_candidate) {
                /** @psalm-suppress MixedArrayAccess */
                if (isset($expr->args[2]->value->name->parts[0]) && 'false' === $expr->args[2]->value->name->parts[0]) {
                    $return_type_candidate = new Union([new TArray([new Union([new TInt()]), new Union([new TString()])])]);
                } else {
                    $return_type_candidate = new Union([new TString(), new TNull()]);
                }
            }
        }
    }
}
