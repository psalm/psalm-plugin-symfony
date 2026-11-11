<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use Psalm\StatementsSource;
use Psalm\Type;

/**
 * @internal
 */
final class ClassNamePrinter
{
    /**
     * Prints a class name the way it can be written at the given source location.
     */
    public static function print(string $fqcn, StatementsSource $source): string
    {
        $printed = Type::getStringFromFQCLN(
            $fqcn,
            $source->getNamespace(),
            $source->getAliasedClassesFlipped(),
            $source->getFQCLN(),
        );

        // A `use` import can shadow the namespace-relative name; fall back to the absolute name then.
        if (Type::getFQCLNFromString($printed, $source->getAliases()) !== $fqcn) {
            return '\\'.$fqcn;
        }

        return $printed;
    }
}
