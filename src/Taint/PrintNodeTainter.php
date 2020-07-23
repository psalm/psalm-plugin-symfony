<?php

namespace Psalm\SymfonyPsalmPlugin\Taint;

use Psalm\CodeLocation;

use Twig\Environment;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\NodeTraverser;

final class PrintNodeTainter implements \Twig\NodeVisitor\NodeVisitorInterface
{
    /**
     * @param array<int, array{name: string, location: CodeLocation}>
     */
    public $sinks = [];

    public function enterNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        if ($node instanceof PrintNode) {
            $expression = $node->getNode('expr');

            if ($expression instanceof FilterExpression) {
                $filter = $expression->getNode('filter');

                if ($filter->getAttribute('value') === 'raw') {
                    $sub_expr = $expression->getNode('node');

                    if ($sub_expr instanceof NameExpression) {
                        $name = $sub_expr->getAttribute('name');

                        $this->sinks[] = [
                            'name' => $name,
                            'location' => self::getLocation($sub_expr->getSourceContext(), $sub_expr->getTemplateLine())
                        ];
                    }
                }
            }
        }

        return $node;
    }

    public function getPriority(): int
    {
        return 0;
    }

    private static function getLocation(\Twig\Source $sourceContext, int $lineNumber) : CodeLocation
    {
        $fileName = $sourceContext->getName();
        $filePath = $sourceContext->getPath();
        $snippet = $sourceContext->getCode();
        $fileCode = file_get_contents($filePath);

        $lines = explode("\n", $snippet);

        $file_start = 0;

        for ($i = 0; $i < $lineNumber - 1; $i++) {
            $file_start += strlen($lines[$i]) + 1;
        }

        $file_start += strpos($lines[$lineNumber - 1], $snippet);
        $file_end = $file_start + strlen($snippet);

        return new \Psalm\CodeLocation\Raw(
            $fileCode,
            $filePath,
            $fileName,
            $file_start,
            $file_end
        );
    }
}
