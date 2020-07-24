<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin;


use Psalm\CodeLocation\Raw;
use Psalm\Context;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Codebase\Taint;
use Psalm\Internal\Taint\Sink;
use Psalm\Internal\Taint\TaintNode;
use Psalm\SymfonyPsalmPlugin\Test\TwigBridge;
use Psalm\Type\TaintKind;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Node;
use Twig\Node\PrintNode;

class TwigAnalyzer extends FileAnalyzer
{
    /** @var Taint */
    private static $taint;

    public function analyze(
        Context $file_context = null,
        $preserve_analyzers = false,
        Context $global_context = null
    ) {
        $codebase = $this->project_analyzer->getCodebase();

        $loader = new FilesystemLoader('templates', $codebase->config->base_dir);
        $twig = new Environment($loader, [
            'cache' => false,
            'auto_reload' => true,
            'debug' => false,
            'optimizations' => 0,
            'strict_variables' => false,
        ]);
        $source = $loader->getSourceContext(str_replace('templates/', '', $this->file_name));
        $tree = $twig->parse($twig->tokenize($source));

        $nodes = $tree->getIterator();

        file_put_contents('/tmp/amod_tree', var_export($tree, true), FILE_APPEND);
    }

    private static function analyzeNode(Node $node)
    {
        $children = $node->getIterator();
        while($subnode = $children->current()) {
            if ($subnode instanceof PrintNode) {
                self::analyzePrintNode($subnode);
            } else {
                self::analyzeNode($subnode);
            }
            $children->next();
        }
    }

    private static function analyzePrintNode(PrintNode $node): void
    {
        $expression = $node->getNode('expr');
        self::analyzeExpression($expression);

        $codeLocation = new Raw(
            $node->getSourceContext()->getCode(),
            $node->getSourceContext()->getName(),
            $node->getSourceContext()->getPath(),
            9, 28
        );

        $taintSink = Sink::getForMethodArgument(
            'print', 'print', 1, null, $codeLocation
        );

        $taintSink->taints = [
            TaintKind::INPUT_HTML,
            TaintKind::USER_SECRET,
            TaintKind::SYSTEM_SECRET
        ];

        static::$taint->addSink($taintSink);
    }

    private static function analyzeExpression(AbstractExpression $expression)
    {
        if ($expression instanceof FilterExpression) {

        }
    }
}
