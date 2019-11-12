<?php

namespace Seferov\SymfonyPsalmPlugin\Handler;

use PhpParser\Node;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\FileManipulation;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterClassLikeAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Seferov\SymfonyPsalmPlugin\Issue\ContainerDependency;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ClassHandler implements AfterClassLikeAnalysisInterface
{
    /**
     * Called after a statement has been checked
     *
     * @param FileManipulation[] $file_replacements
     *
     * @return null|false
     */
    public static function afterStatementAnalysis(Node\Stmt\ClassLike $stmt, ClassLikeStorage $classlike_storage, StatementsSource $statements_source, Codebase $codebase, array &$file_replacements = [])
    {
        foreach ($stmt->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && '__construct' === $stmt->name->name) {
                foreach ($stmt->params as $param) {
                    if ($param->type instanceof Node\Name && ContainerInterface::class === $param->type->getAttributes()['resolvedName']) {
                        IssueBuffer::accepts(
                            new ContainerDependency(new CodeLocation($statements_source, $stmt)),
                            $statements_source->getSuppressedIssues()
                        );
                    }
                }
            }
        }

        return null;
    }
}
