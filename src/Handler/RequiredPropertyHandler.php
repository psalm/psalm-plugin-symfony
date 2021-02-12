<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use Psalm\Codebase;
use Psalm\FileSource;
use Psalm\Plugin\Hook\AfterClassLikeVisitInterface;
use Psalm\Storage\ClassLikeStorage;

class RequiredPropertyHandler implements AfterClassLikeVisitInterface
{
    public static function afterClassLikeVisit(
        ClassLike $stmt,
        ClassLikeStorage $storage,
        FileSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = []
    ) {
        if (!$stmt instanceof Class_) {
            return;
        }
        $reflection = null;
        foreach ($storage->properties as $name => $property) {
            if (!empty($storage->initialized_properties[$name])) {
                continue;
            }
            foreach ($property->attributes as $attribute) {
                if ('Symfony\Contracts\Service\Attribute\Required' === $attribute->fq_class_name) {
                    $storage->initialized_properties[$name] = true;
                    continue 2;
                }
            }
            $class = $storage->name;
            if (!class_exists($class)) {
                /** @psalm-suppress UnresolvableInclude */
                require_once $statements_source->getRootFilePath();
            }
            /** @psalm-suppress ArgumentTypeCoercion */
            $reflection = $reflection ?? new \ReflectionClass($class);
            if ($reflection->hasProperty($name)) {
                $reflectionProperty = $reflection->getProperty($name);
                $docCommend = $reflectionProperty->getDocComment();
                if ($docCommend && false !== strpos(strtoupper($docCommend), '@REQUIRED')) {
                    $storage->initialized_properties[$name] = true;
                }
            }
        }
    }
}
