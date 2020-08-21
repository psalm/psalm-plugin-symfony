<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\Codebase\Taint;
use Psalm\Internal\Taint\Sink;
use Psalm\Internal\Taint\Taintable;
use Psalm\Internal\Taint\TaintNode;
use Psalm\Type;
use Psalm\Type\TaintKind;
use RuntimeException;
use Twig\Node\BodyNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\Source;

/**
 * @internal
 * This class is not meant to be used outside of the `TemplateFileAnalyzer`
 */
class TwigModuleAnalyzer
{
    /**
     * @var Context
     */
    private $context;
    /**
     * @var Source
     */
    private $twig_source;

    /**
     * @var Taint
     */
    private $taint;

    public function __construct(Context $context, Source $twig_source, Taint $taint)
    {
        $this->context = $context;
        $this->twig_source = $twig_source;
        $this->taint = $taint;
    }

    public function analyzeModule(ModuleNode $node): void
    {
        $bodyNode = $node->getNode('body');
        if (!$bodyNode instanceof BodyNode) {
            throw new RuntimeException('The body node has an expected type.');
        }

        $this->analyzeBody($bodyNode);
    }

    private function analyzeBody(BodyNode $node): void
    {
        /** @var Node $innerNode */
        foreach ($node->getIterator() as $innerNode) {
            /** @var Node $sub_node */
            foreach ($innerNode->getIterator() as $sub_node) {
                if (!$sub_node instanceof PrintNode) {
                    continue;
                }

                $this->analyzePrintNode($sub_node);
            }
        }
    }

    private function analyzePrintNode(PrintNode $node): Taintable
    {
        $codeLocation = self::getLocation($node->getSourceContext() ?? $this->twig_source, $node->getTemplateLine());

        $sink = Sink::getForMethodArgument(
            'twig_print', 'twig_print', 0, null, $codeLocation
        );

        $sink->taints = [
            TaintKind::INPUT_HTML,
            TaintKind::USER_SECRET,
            TaintKind::SYSTEM_SECRET,
        ];

        $this->taint->addSink($sink);

        $expression = $node->getNode('expr');
        if (!$expression instanceof AbstractExpression) {
            throw new RuntimeException('The expr node has an expected type.');
        }

        if ($expression_taint = $this->analyzeExpression($expression)) {
            $this->taint->addPath($expression_taint, $sink, 'arg');
        }

        return $sink;
    }

    private function analyzeExpression(AbstractExpression $expression): ?Taintable
    {
        if ($expression instanceof FilterExpression) {
            return $this->analyzeFilter($expression);
        }

        return null;
    }

    private function analyzeFilter(FilterExpression $expression): ?Taintable
    {
        if ('raw' !== $expression->getNode('filter')->getAttribute('value')) {
            return null;
        }

        $function_id = 'filter_raw';
        $return_location = self::getLocation($expression->getSourceContext() ?? $this->twig_source, $expression->getTemplateLine());
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
            $this->taint->addPath($sub_node_taint, $argument_taint, 'arg');
        }

        return $return_taint;
    }

    private function analyzeName(NameExpression $expression): Taintable
    {
        /** @var string $var_id */
        $var_id = $expression->getAttribute('name');
        $var_type = Type::getMixed();
        $this->context->vars_in_scope[$var_id] = $var_type;

        $var_location = self::getLocation($expression->getSourceContext() ?? $this->twig_source, $expression->getTemplateLine());
        $variable_taint = TaintNode::getForAssignment($var_id, $var_location);
        $this->taint->addTaintNode($variable_taint);

        $var_type->parent_nodes = [$variable_taint];

        return $variable_taint;
    }

    private static function getLocation(Source $sourceContext, int $lineNumber): CodeLocation
    {
        $fileName = $sourceContext->getName();
        $filePath = $sourceContext->getPath();
        $snippet = (string) $sourceContext->getCode(); // warning : the getCode method returns the whole template, not only the statement
        $fileCode = file_get_contents($filePath);

        $lines = explode("\n", $snippet);

        $file_start = 0;

        for ($i = 0; $i < $lineNumber - 1; ++$i) {
            $file_start += strlen($lines[$i]) + 1;
        }

        $lineNumber = $lineNumber ?: 1;
        $file_start += intval(strpos($lines[$lineNumber - 1], $snippet));
        $file_end = $file_start + strlen($snippet);

        return new CodeLocation\Raw(
            $fileCode,
            $filePath,
            $fileName,
            $file_start,
            max($file_end, strlen($fileCode))
        );
    }
}
