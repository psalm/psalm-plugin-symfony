<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Tests\Symfony;

use PhpParser\Node\Expr\FuncCall;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Provider\FileProvider;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\Internal\Provider\Providers;
use Psalm\Internal\Provider\StatementsProvider;
use Psalm\Plugin\Hook\AfterEveryFunctionCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionStorage;
use Psalm\SymfonyPsalmPlugin\Twig\TwigUtils;
use RuntimeException;

class TwigUtilsTest extends TestCase
{
    /**
     * @dataProvider provideExpressions
     */
    public function testItCanExtractTheTemplateNameFromAnExpression(string $expression)
    {
        $code = '<?php'."\n".$expression;
        $statements = StatementsProvider::parseStatements($code, '7.1');

        $assertionHook = new class() implements AfterEveryFunctionCallAnalysisInterface {
            public static function afterEveryFunctionCallAnalysis(FuncCall $expr, string $function_id, Context $context, StatementsSource $statements_source, Codebase $codebase): void
            {
                Assert::assertSame('expected.twig', TwigUtils::extractTemplateNameFromExpression($expr->args[0]->value, $statements_source));
            }
        };

        $statementsAnalyzer = self::createStatementsAnalyzer($assertionHook);
        $statementsAnalyzer->analyze($statements, new Context());
    }

    public function provideExpressions(): array
    {
        return [
            ['dummy("expected.twig");'],
            ['dummy(\'expected.twig\');'],
            ['$a = "expected.twig"; dummy($a);'],
        ];
    }

    public function testItThrowsAnExceptionWhenTryingToExtractTemplateNameFromAnUnsupportedExpression()
    {
        $code = '<?php'."\n".'dummy(123);';
        $statements = StatementsProvider::parseStatements($code, '7.1');

        $assertionHook = new class() implements AfterEveryFunctionCallAnalysisInterface {
            public static function afterEveryFunctionCallAnalysis(FuncCall $expr, string $function_id, Context $context, StatementsSource $statements_source, Codebase $codebase): void
            {
                TwigUtils::extractTemplateNameFromExpression($expr->args[0]->value, $statements_source);
            }
        };

        $statementsAnalyzer = self::createStatementsAnalyzer($assertionHook);

        $this->expectException(RuntimeException::class);
        $statementsAnalyzer->analyze($statements, new Context());
    }

    private static function createStatementsAnalyzer(AfterEveryFunctionCallAnalysisInterface $hook): StatementsAnalyzer
    {
        $config = (function () { return new self(); })->bindTo(null, Config::class)();
        $config->after_every_function_checks[] = $hook;

        $nullFileAnalyzer = new FileAnalyzer(new ProjectAnalyzer($config, new Providers(new FileProvider())), '', '');
        $nullFileAnalyzer->codebase->functions->addGlobalFunction('dummy', new FunctionStorage());
        $nullFileAnalyzer->codebase->file_storage_provider->create('');

        $nodeData = new NodeDataProvider();
        (function () use ($nodeData) {
            $this->node_data = $nodeData;
        })->bindTo($nullFileAnalyzer, $nullFileAnalyzer)();

        return new StatementsAnalyzer($nullFileAnalyzer, $nodeData);
    }
}
