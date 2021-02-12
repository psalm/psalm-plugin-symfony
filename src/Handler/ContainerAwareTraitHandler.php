<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Stmt\ClassLike;
use Psalm\Codebase;
use Psalm\FileSource;
use Psalm\Plugin\Hook\AfterClassLikeVisitInterface;
use Psalm\Storage\ClassLikeStorage;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class ContainerAwareTraitHandler implements AfterClassLikeVisitInterface
{
    public static function afterClassLikeVisit(
        ClassLike $stmt,
        ClassLikeStorage $storage,
        FileSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = []
    ) {
        if (ContainerAwareTrait::class === $storage->name) {
            $storage->initialized_properties['container'] = true;
        }
    }
}
