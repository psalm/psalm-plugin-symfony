<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\Mapping\Entity as EntityAnnotation;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\DocComment;
use Psalm\Exception\DocblockParseException;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\SymfonyPsalmPlugin\Issue\RepositoryStringShortcut;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

final class DoctrineRepositoryHandler implements AfterMethodCallAnalysisInterface, AfterClassLikeVisitInterface
{
    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $declaring_method_id = $event->getDeclaringMethodId();
        if (!in_array($declaring_method_id, ['Doctrine\ORM\EntityManagerInterface::getrepository', 'Doctrine\Persistence\ObjectManager::getrepository'])) {
            return;
        }

        $expr = $event->getExpr();
        if (!isset($expr->args[0]->value)) {
            return;
        }

        $entityName = $expr->args[0]->value;
        if (!$entityName instanceof Expr\ClassConstFetch) {
            if ($entityName instanceof String_) {
                $statements_source = $event->getStatementsSource();
                IssueBuffer::accepts(
                    new RepositoryStringShortcut(new CodeLocation($statements_source, $entityName)),
                    $statements_source->getSuppressedIssues()
                );
            }

            return;
        }

        /** @psalm-var class-string|null $className */
        $className = $entityName->class->getAttribute('resolvedName');
        if (null === $className) {
            return;
        }

        try {
            $reflectionClass = new \ReflectionClass($className);
        } catch (\ReflectionException) {
            return;
        }

        $entityAttributes = $reflectionClass->getAttributes(EntityAnnotation::class);
        foreach ($entityAttributes as $entityAttribute) {
            $arguments = $entityAttribute->getArguments();

            if (isset($arguments['repositoryClass']) && is_string($arguments['repositoryClass'])) {
                $event->setReturnTypeCandidate(new Union([new TNamedObject($arguments['repositoryClass'])]));

                return;
            }
        }

        if (class_exists(AnnotationReader::class)) {
            $reader = new AnnotationReader();
            $entityAnnotation = $reader->getClassAnnotation(
                $reflectionClass,
                EntityAnnotation::class
            );

            if ($entityAnnotation instanceof EntityAnnotation && $entityAnnotation->repositoryClass) {
                $event->setReturnTypeCandidate(new Union([new TNamedObject($entityAnnotation->repositoryClass)]));
            }
        }
    }

    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $stmt = $event->getStmt();
        $statements_source = $event->getStatementsSource();
        $codebase = $event->getCodebase();

        $docblock = $stmt->getDocComment();
        if ($docblock && str_contains((string) $docblock, 'repositoryClass')) {
            try {
                $parsedComment = DocComment::parsePreservingLength($docblock);
                if (isset($parsedComment->tags['Entity'])) {
                    $repositoryClassName = str_replace(['"', '(', ')', 'repositoryClass', '\'', '='], '', array_values($parsedComment->tags['Entity'])[0]);

                    $file_path = $statements_source->getFilePath();
                    $file_storage = $codebase->file_storage_provider->get($file_path);

                    $codebase->queueClassLikeForScanning($repositoryClassName);
                    $file_storage->referenced_classlikes[strtolower($repositoryClassName)] = $repositoryClassName;
                }
            } catch (DocblockParseException) {
            }
        }
    }
}
