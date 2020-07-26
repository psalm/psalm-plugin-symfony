<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Taint;


use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Codebase\Taint;
use Psalm\Internal\Taint\Sink;
use Psalm\Internal\Taint\Taintable;
use Psalm\Internal\Taint\TaintNode;
use Psalm\Type;
use Psalm\Type\TaintKind;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Node\BodyNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\ModuleNode;
use Twig\Node\PrintNode;

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

        $this->taint = $codebase->taint;

        $loader = new FilesystemLoader('templates', $codebase->config->base_dir);
        $twig = new Environment($loader, [
            'cache' => false,
            'auto_reload' => true,
            'debug' => false,
            'optimizations' => 0,
            'strict_variables' => false,
        ]);

        $local_file_name = str_replace('templates/', '', $this->file_name);
        $source = $loader->getSourceContext($local_file_name);
        $tree = $twig->parse($twig->tokenize($source));

        $this->taint = $codebase->taint;
        $this->context = new Context();

        $this->analyzeModule($tree);
        foreach($this->context->vars_in_scope as $var_name => $var_type) {
            $taint_source = self::getTaintNodeForTwigNamedVariable($local_file_name, $var_name, null);
            $this->taint->addTaintNode($taint_source);
            foreach($var_type->parent_nodes as $taint_sink) {
                $this->taint->addPath($taint_source, $taint_sink, 'arg');
            }
        }
    }

    private function analyzeModule(ModuleNode $node)
    {
        $this->analyzeBody($node->getNode('body'));
    }

    private function analyzeBody(BodyNode $node)
    {
        $children = $node->getIterator()[0]->getIterator();
        while($sub_node = $children->current()) {
            if ($sub_node instanceof PrintNode) {
                $this->analyzePrintNode($sub_node);
            }
            $children->next();
        }
    }

    private function analyzePrintNode(PrintNode $node): Taintable
    {
        $expression = $node->getNode('expr');
        $expression_taint = $this->analyzeExpression($expression);

        $codeLocation = self::getLocation($node->getSourceContext(), $node->getTemplateLine());

        $sink = Sink::getForMethodArgument(
            'twig_print', 'twig_print', 0, null, $codeLocation
        );

        $sink->taints = [
            TaintKind::INPUT_HTML,
            TaintKind::USER_SECRET,
            TaintKind::SYSTEM_SECRET
        ];

        $this->taint->addSink($sink);

        $this->taint->addPath($expression_taint, $sink, 'arg');

        return $sink;
    }

    private function analyzeExpression(AbstractExpression $expression): ?Taintable
    {
        if ($expression instanceof FilterExpression) {
            return $this->analyzeFilter($expression);
        } else {
            return null;
        }
    }

    private function analyzeFilter(FilterExpression $expression): ?Taintable
    {
        if ($expression->getNode('filter')->getAttribute('value') !== 'raw') {
            return null;
        }

        $function_id = 'filter_raw';
        $return_location = self::getLocation($expression->getSourceContext(), $expression->getTemplateLine());
        $return_taint = TaintNode::getForMethodReturn($function_id, $function_id, $return_location, $return_location);

        $this->taint->addTaintNode($return_taint);

        $argument_taint = TaintNode::getForMethodArgument(
            $function_id, $function_id, 0, $return_location, $return_location // should be $argument_location instead of $return_location
        );

        $this->taint->addTaintNode($argument_taint);
        $this->taint->addPath($argument_taint, $return_taint, 'arg');

        $sub_node = $expression->getNode('node');
        if ($sub_node instanceof NameExpression) {
            $sub_node_taint = $this->analyzeName($sub_node);
        }

        $this->taint->addPath($sub_node_taint, $argument_taint, 'arg');

        return $return_taint;
    }

    private function analyzeName(NameExpression $expression): Taintable
    {
        $var_id = $expression->getAttribute('name');
        $var_type = Type::getMixed();
        $this->context->vars_in_scope[$var_id] = $var_type;

        $var_location = self::getLocation($expression->getSourceContext(), $expression->getTemplateLine());
        $variable_taint = TaintNode::getForAssignment($var_id, $var_location);
        $this->taint->addTaintNode($variable_taint);

        $var_type->parent_nodes = [$variable_taint];

        return $variable_taint;
    }

    private static function getLocation(\Twig\Source $sourceContext, int $lineNumber) : CodeLocation
    {
        $fileName = $sourceContext->getName();
        $filePath = $sourceContext->getPath();
        $snippet = (string) $sourceContext->getCode(); // warning : the getCode method returns the whole template, not only the statement
        $fileCode = file_get_contents($filePath);

        $lines = explode("\n", $snippet);

        $file_start = 0;

        for ($i = 0; $i < $lineNumber - 1; $i++) {
            $file_start += strlen($lines[$i]) + 1;
        }

        $lineNumber = $lineNumber ?: 1;
        $file_start += strpos($lines[$lineNumber - 1], $snippet);
        $file_end = $file_start + strlen($snippet);

        return new CodeLocation\Raw(
            $fileCode,
            $filePath,
            $fileName,
            $file_start,
            max($file_end, strlen($fileCode))
        );
    }

    public static function getTaintNodeForTwigNamedVariable(
        string $template_id,
        string $variable_name
    ) {
        $label = $arg_id = strtolower($template_id) . '#' . strtolower($variable_name);

        return new TaintNode($arg_id, $label, null, null);
    }
}
