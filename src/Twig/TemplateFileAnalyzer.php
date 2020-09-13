<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Context;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Codebase\Taint;
use Psalm\Internal\Taint\TaintNode;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TemplateFileAnalyzer extends FileAnalyzer
{
    /**
     * @var Taint|null
     */
    private $taint;

    public function analyze(
        Context $file_context = null,
        $preserve_analyzers = false,
        Context $global_context = null
    ) {
        $codebase = $this->project_analyzer->getCodebase();

        if (null === $codebase->taint) {
            return;
        }

        $this->taint = $codebase->taint;

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

        $context = new Context();

        $moduleAnalyzer = new TwigModuleAnalyzer($context, $twig_source, $codebase->taint);
        $moduleAnalyzer->analyzeModule($tree);

        foreach ($context->vars_in_scope as $var_name => $var_type) {
            $taint_source = self::getTaintNodeForTwigNamedVariable($local_file_name, $var_name);
            $this->taint->addTaintNode($taint_source);

            if (null === $var_type->parent_nodes) {
                continue;
            }

            foreach ($var_type->parent_nodes as $taint_sink) {
                $this->taint->addPath($taint_source, $taint_sink, 'arg');
            }
        }
    }

    public static function getTaintNodeForTwigNamedVariable(
        string $template_id,
        string $variable_name
    ): TaintNode {
        $label = $arg_id = strtolower($template_id).'#'.strtolower($variable_name);

        return new TaintNode($arg_id, $label, null, null);
    }
}
