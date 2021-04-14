<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\Mapping\Column;
use PhpParser\Node\Stmt\ClassLike;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\FileSource;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterClassLikeVisitInterface;
use Psalm\Storage\ClassLikeStorage;
use Psalm\SymfonyPsalmPlugin\Issue\DoctrinePropertyInvalidType;

class DoctrineNullablePropertyHandler implements AfterClassLikeVisitInterface
{
    public static function afterClassLikeVisit(
        ClassLike $stmt,
        ClassLikeStorage $storage,
        FileSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = []
    ): void {
        $className = $storage->name;
        try {
            $reflectionClass = new \ReflectionClass($className);
        } catch (\ReflectionException $e) {
            return;
        }
        $annotationReader = new AnnotationReader();

        foreach ($stmt->getProperties() as $property) {
            try {
                $propertyReflection = $reflectionClass->getProperty($property->props[0]->name->name);
                $columnAnnotation = $annotationReader->getPropertyAnnotation($propertyReflection, Column::class);
            } catch (\ReflectionException $e) {
                continue;
            } catch (AnnotationException $e) {
                continue;
            }

            if (!$columnAnnotation) {
                continue;
            }

            if (!isset($storage->properties[$propertyReflection->name])) {
                continue;
            }

            $propertyInfo = $storage->properties[$propertyReflection->name];
            if (!$propertyInfo->type) {
                continue;
            }

            $nullableByDoctrine = $columnAnnotation->nullable;

            if ($propertyInfo->type->isNullable() && !$nullableByDoctrine) {
                IssueBuffer::accepts(
                    new DoctrinePropertyInvalidType('Property is not nullable according to Doctrine column definition.', new CodeLocation($statements_source, $property))
                );
            } elseif (!$propertyInfo->type->isNullable() && $nullableByDoctrine) {
                IssueBuffer::accepts(
                    new DoctrinePropertyInvalidType('Property must be nullable according to Doctrine column definition.', new CodeLocation($statements_source, $property))
                );
            }
        }
    }
}
