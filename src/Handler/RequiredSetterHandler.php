<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use Psalm\Internal\PhpVisitor\AssignmentMapVisitor;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Storage\ClassLikeStorage;

class RequiredSetterHandler implements AfterClassLikeVisitInterface
{
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
    {
        $stmt = $event->getStmt();
        $storage = $event->getStorage();

        $stmt_source = $event->getStatementsSource();
        $aliases = $stmt_source->getAliases();

        if (!$stmt instanceof Class_) {
            return;
        }

        foreach ($stmt->getMethods() as $method) {
            // Check for PhpDoc annotation
            $docComment = $method->getDocComment();
            if ($docComment instanceof Doc && false !== strpos($docComment->getText(), '@required')) {
                self::markAsInitializedProperties($storage, $method->getStmts() ?? []);
            }

            // Check for attribute annotation
            foreach ($method->getAttrGroups() as $attrGroup) {
                foreach ($attrGroup->attrs as $attribute) {
                    /** @var lowercase-string $lcName */
                    $lcName = $attribute->name->toLowerString();
                    if (array_key_exists($lcName, $aliases->uses)) {
                        $name = $aliases->uses[$lcName];
                    } else {
                        $name = $attribute->name->toString();
                    }
                    if ('Symfony\Contracts\Service\Attribute\Required' === $name) {
                        self::markAsInitializedProperties($storage, $method->getStmts() ?? []);
                    }
                }
            }
        }
    }

    private static function markAsInitializedProperties(ClassLikeStorage $storage, array $stmts): void
    {
        $traverser = new NodeTraverser();
        $visitor = new AssignmentMapVisitor(null);
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

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
