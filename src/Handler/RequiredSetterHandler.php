<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use Psalm\Internal\PhpVisitor\AssignmentMapVisitor;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;

class RequiredSetterHandler implements AfterClassLikeVisitInterface
{
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
    {
        $stmt = $event->getStmt();
        $storage = $event->getStorage();

        if (!$stmt instanceof Class_) {
            return;
        }

        foreach ($stmt->getMethods() as $method) {
            $docComment = $method->getDocComment();

            if ($docComment instanceof Doc && false !== strpos($docComment->getText(), '@required')) {
                $traverser = new NodeTraverser();
                $visitor = new AssignmentMapVisitor(null);
                $traverser->addVisitor($visitor);
                $traverser->traverse($method->getStmts() ?? []);

                foreach (array_keys($visitor->getAssignmentMap()) as $assignment) {
                    if (0 !== strpos($assignment, '$this->')) {
                        continue;
                    }

                    $property = substr($assignment, strlen('$this->'));
                    if (!array_key_exists($property, $storage->properties)) {
                        continue;
                    }

                    $storage->initialized_properties[$property] = true;
                }
            }
        }
    }
}
