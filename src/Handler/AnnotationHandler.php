<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;

class AnnotationHandler implements AfterClassLikeVisitInterface
{
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
    {
        $stmt = $event->getStmt();
        $storage = $event->getStorage();

        if (!$stmt instanceof Class_) {
            return;
        }

        $docComment = $stmt->getDocComment();
        if ($docComment instanceof Doc && false !== strpos($docComment->getText(), '@Annotation')) {
            $storage->suppressed_issues[] = 'PropertyNotSetInConstructor';
        }
    }
}
