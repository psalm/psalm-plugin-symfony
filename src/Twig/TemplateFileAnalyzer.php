<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Context as PsalmContext;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\DataFlow\DataFlowNode;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\NodeTraverser;

/**
 * This class is to be used as a "checker" for the `.twig` files in the psalm configuration.
 *
 * @psalm-suppress UnusedClass
 */
class TemplateFileAnalyzer extends FileAnalyzer
{
    public function analyze(
        PsalmContext $file_context = null,
        bool $preserve_analyzers = false,
        PsalmContext $global_context = null
    ): void {
        $codebase = $this->project_analyzer->getCodebase();
        $taint = $codebase->taint_flow_graph;

        if (null === $taint) {
            return;
        }

        $loader = new FilesystemLoader('templates', $codebase->config->base_dir);
        $twig = new Environment($loader, [
            'cache' => false,
            'auto_reload' => true,
            'debug' => true,
            'optimizations' => 0,
            'strict_variables' => false,
        ]);

        $local_file_name = str_replace('templates/', '', $this->file_name);
        $twig_source = $loader->getSourceContext($local_file_name);
        $tree = $twig->parse($twig->tokenize($twig_source));

        $twigContext = new Context($twig_source, $taint);

        $traverser = new NodeTraverser($twig, [
            new TaintAnalysisVisitor($twigContext),
        ]);

        $traverser->traverse($tree);

        $twigContext->taintUnassignedVariables($local_file_name);
        $twigContext->taintSinks($local_file_name);
    }

    public static function getTaintNodeForTwigNamedVariable(
        string $template_id,
        string $variable_name
    ): DataFlowNode {
        $label = $arg_id = strtolower($template_id).'#'.strtolower($variable_name);

        return new DataFlowNode($arg_id, $label, null, null);
    }
}
