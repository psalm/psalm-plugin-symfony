<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\Mapping\Entity as EntityAnnotation;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\DocComment;
use Psalm\Exception\DocblockParseException;
use Psalm\FileSource;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterClassLikeVisitInterface;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\SymfonyPsalmPlugin\Issue\RepositoryStringShortcut;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

class DoctrineRepositoryHandler implements AfterMethodCallAnalysisInterface, AfterClassLikeVisitInterface
{
    /**
     * {@inheritdoc}
     */
    public static function afterMethodCallAnalysis(
        Expr $expr,
        string $method_id,
        string $appearing_method_id,
        string $declaring_method_id,
        Context $context,
        StatementsSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = [],
        Union &$return_type_candidate = null
    ): void {
        if (in_array($declaring_method_id, ['Doctrine\ORM\EntityManagerInterface::getrepository', 'Doctrine\Persistence\ObjectManager::getrepository'])) {
            $entityName = $expr->args[0]->value;
            if ($entityName instanceof String_) {
                IssueBuffer::accepts(
                    new RepositoryStringShortcut(new CodeLocation($statements_source, $entityName)),
                    $statements_source->getSuppressedIssues()
                );
            } elseif ($entityName instanceof Expr\ClassConstFetch) {
                /** @psalm-var class-string $className */
                $className = $entityName->class->getAttribute('resolvedName');

                $reader = new AnnotationReader();
                try {
                    $entityAnnotation = $reader->getClassAnnotation(
                        new \ReflectionClass($className),
                        EntityAnnotation::class
                    );
                    if ($entityAnnotation instanceof EntityAnnotation && $entityAnnotation->repositoryClass) {
                        $return_type_candidate = new Union([new TNamedObject($entityAnnotation->repositoryClass)]);
                    }
                } catch (\ReflectionException $e) {
                }
            }
        }
    }

    public static function afterClassLikeVisit(
        ClassLike $stmt,
        ClassLikeStorage $storage,
        FileSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = []
    ) {
        $docblock = $stmt->getDocComment();
        if ($docblock && false !== strpos((string) $docblock, 'repositoryClass')) {
            try {
                $parsedComment = DocComment::parsePreservingLength($docblock);
                if (isset($parsedComment->tags['Entity'])) {
                    $repositoryClassName = str_replace(['"', '(', ')', 'repositoryClass', '\'', '='], '', array_values($parsedComment->tags['Entity'])[0]);

                    $file_path = $statements_source->getFilePath();
                    $file_storage = $codebase->file_storage_provider->get($file_path);

                    $codebase->queueClassLikeForScanning($repositoryClassName);
                    $file_storage->referenced_classlikes[strtolower($repositoryClassName)] = $repositoryClassName;
                }
            } catch (DocblockParseException $e) {
            }
        }
    }
}
