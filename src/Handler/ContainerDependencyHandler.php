<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use Psalm\SymfonyPsalmPlugin\Issue\ContainerDependency;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ContainerDependencyHandler implements AfterFunctionLikeAnalysisInterface
{
    public static function afterStatementAnalysis(AfterFunctionLikeAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();
        $statements_source = $event->getStatementsSource();

        if ($stmt instanceof Node\Stmt\ClassMethod && '__construct' === $stmt->name->name) {
            foreach ($stmt->params as $param) {
                if ($param->type instanceof Node\Name && ContainerInterface::class === $param->type->getAttribute('resolvedName')) {
                    IssueBuffer::accepts(
                        new ContainerDependency(new CodeLocation($statements_source, $param)),
                        $statements_source->getSuppressedIssues()
                    );
                }
            }
        }

        return null;
    }
}
