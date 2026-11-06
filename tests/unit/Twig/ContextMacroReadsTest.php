<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\SymfonyPsalmPlugin\Twig\Context;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Node\Expression\MacroReferenceExpression;
use Twig\Node\Node;
use Twig\Source;

/**
 * A call of a macro only links the chains of attributes the macro reads, so that analysing many calls of one macro
 * with different attributes of one object costs no more than one call (https://github.com/psalm/psalm-plugin-symfony/issues/426).
 */
class ContextMacroReadsTest extends TestCase
{
    private const CALLS = 30;

    protected function setUp(): void
    {
        foreach (['templateReads', 'templateMacros', 'templateScopes', 'macroReads', 'templateReadsInProgress', 'macroReadsInProgress', 'calledTemplates', 'reachableReads'] as $property) {
            (new \ReflectionProperty(Context::class, $property))->setValue(null, []);
        }
    }

    public function testMacroReadingNoAttributeReadsNoChain(): void
    {
        $twig = $this->createTwig(['t.twig' => self::getTemplate(self::CALLS)]);

        self::assertSame(['field' => []], self::callContext('getMacroReads', $twig, 't.twig'));
        // the template reads the chains of its arguments, and no combination of them
        self::assertCount(self::CALLS, self::callContext('getTemplateReads', $twig, 't.twig')['attributes']);
    }

    public function testMacroReadsTheChainsOfItsBodyAndOfTheMacrosItCalls(): void
    {
        $twig = $this->createTwig([
            't.twig' => '{% import "m.twig" as m %}{% macro show(p) %}{{ p.name }}{{ _self.inner(p.manager) }}{% endmacro %}'
                .'{% macro inner(q) %}{% for i in q.items %}{{ i.id }}{% endfor %}{% endmacro %}'
                .'{% macro far(r) %}{{ m.leaf(r.next) }}{% endmacro %}',
            'm.twig' => '{% macro leaf(s) %}{{ s.title }}{% endmacro %}',
        ]);

        $reads = self::callContext('getMacroReads', $twig, 't.twig');

        self::assertEqualsCanonicalizing([
            ['name'], ['manager'], ['manager', 'items'], ['manager', 'id'], ['manager', 'items[]', 'id'],
            ['manager[]', 'items'], ['manager[]', 'id'], ['manager[]', 'items[]', 'id'],
        ], $reads['show']);
        self::assertEqualsCanonicalizing([['items'], ['id'], ['items[]', 'id']], $reads['inner']);
        self::assertEqualsCanonicalizing([['next'], ['next', 'title'], ['next[]', 'title']], $reads['far']);
    }

    public function testMacrosOfTemplatesCallingEachOtherReadWhatTheTemplatesRead(): void
    {
        $twig = $this->createTwig([
            'a.twig' => '{% import "b.twig" as b %}{% macro outer(p) %}{{ b.middle(p) }}{% endmacro %}{% macro leaf(p) %}{{ p.name }}{% endmacro %}',
            'b.twig' => '{% import "a.twig" as a %}{% macro middle(p) %}{{ a.leaf(p) }}{% endmacro %}',
        ]);

        // the reads of the macros can't be told: they are not kept, and a call takes the reads of the templates
        self::assertNull(self::callContext('getMacroReads', $twig, 'a.twig'));
        self::assertContains(['name'], self::callContext('getReachableReads', $twig, 'b.twig'));
    }

    public function testCallsOfAMacroLinkTheSameNodesWhateverTheirNumber(): void
    {
        self::assertSame(self::countNodesLinkedBy(1), self::countNodesLinkedBy(self::CALLS));
    }

    /**
     * @param array<string, string> $templates
     */
    private function createTwig(array $templates): Environment
    {
        return new Environment(new ArrayLoader($templates));
    }

    private static function getTemplate(int $calls): string
    {
        $template = '{% macro field(label, value) %}{{ label }}: {{ value|raw }}{% endmacro %}{% import _self as f %}';
        for ($i = 1; $i <= $calls; ++$i) {
            $template .= "{{ f.field('l$i', x.a$i) }}";
        }

        return $template;
    }

    /**
     * @return mixed
     */
    private static function callContext(string $method, mixed ...$arguments)
    {
        return (new \ReflectionMethod(Context::class, $method))->invoke(null, ...$arguments);
    }

    /**
     * How many nodes the graph has once the template of $calls calls of the macro is analyzed as far as its calls.
     */
    private function countNodesLinkedBy(int $calls): int
    {
        $this->setUp();

        $source = new Source(self::getTemplate($calls), 't.twig');
        $twig = $this->createTwig(['t.twig' => $source->getCode()]);
        $graph = new TaintFlowGraph();
        $context = new Context($source, $graph, $twig);
        $module = $twig->parse($twig->tokenize($source));
        $context->setMacroImports($module);

        foreach (self::getMacroCalls($module) as $call) {
            // the attributes of the objects of `x`, given as the first and the second argument
            $context->callMacro($call, [[0, [], [], []], [1, [], ['t.twig#x'], []]]);
        }

        return \count((new \ReflectionProperty($graph, 'nodes'))->getValue($graph));
    }

    /**
     * @return list<MacroReferenceExpression>
     */
    private static function getMacroCalls(Node $node): array
    {
        $calls = $node instanceof MacroReferenceExpression ? [$node] : [];
        foreach ($node as $child) {
            $calls = [...$calls, ...self::getMacroCalls($child)];
        }

        return $calls;
    }
}
