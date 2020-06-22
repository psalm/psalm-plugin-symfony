<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterFunctionLikeAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\SymfonyPsalmPlugin\Issue\ContainerDependency;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ContainerDependencyHandler implements AfterFunctionLikeAnalysisInterface
{
    /**
     * {@inheritdoc}
     */
    public static function afterStatementAnalysis(
        Node\FunctionLike $stmt,
        FunctionLikeStorage $classlike_storage,
        StatementsSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = []
    ) {
        if ($stmt instanceof Node\Stmt\ClassMethod && '__construct' === $stmt->name->name) {
            foreach ($stmt->params as $param) {
                if ($param->type instanceof Node\Name && ContainerInterface::class === $param->type->getAttributes()['resolvedName']) {
                    IssueBuffer::accepts(
                        new ContainerDependency(new CodeLocation($statements_source, $param)),
                        $statements_source->getSuppressedIssues()
                    );
                }
            }
        }
    }
}
