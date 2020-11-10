<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use Psalm\Codebase;
use Psalm\FileSource;
use Psalm\Internal\PhpVisitor\AssignmentMapVisitor;
use Psalm\Plugin\Hook\AfterClassLikeVisitInterface;
use Psalm\Storage\ClassLikeStorage;

class RequiredSetterHandler implements AfterClassLikeVisitInterface
{
    public static function afterClassLikeVisit(ClassLike $stmt, ClassLikeStorage $storage, FileSource $statements_source, Codebase $codebase, array &$file_replacements = [])
    {
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
