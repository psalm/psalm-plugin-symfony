<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use Psalm\Codebase;
use Psalm\FileSource;
use Psalm\Plugin\Hook\AfterClassLikeVisitInterface;
use Psalm\Storage\ClassLikeStorage;

class AnnotationHandler implements AfterClassLikeVisitInterface
{
    /**
     * {@inheritdoc}
     */
    public static function afterClassLikeVisit(ClassLike $stmt, ClassLikeStorage $storage, FileSource $statements_source, Codebase $codebase, array &$file_replacements = [])
    {
        if (!$stmt instanceof Class_) {
            return;
        }

        $docComment = $stmt->getDocComment();
        if ($docComment instanceof Doc && false !== strpos($docComment->getText(), '@Annotation')) {
            $storage->suppressed_issues[] = 'PropertyNotSetInConstructor';
        }
    }
}
