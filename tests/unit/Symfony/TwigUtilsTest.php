<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Tests\Symfony;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Provider\FileProvider;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\Internal\Provider\Providers;
use Psalm\Internal\Provider\StatementsProvider;
use Psalm\Plugin\EventHandler\AfterEveryFunctionCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterEveryFunctionCallAnalysisEvent;
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
        $hasErrors = false;
        $code = '<?php'."\n".$expression;
        $statements = StatementsProvider::parseStatements($code, '7.1', $hasErrors);

        $assertionHook = new class() implements AfterEveryFunctionCallAnalysisInterface {
            public static function afterEveryFunctionCallAnalysis(AfterEveryFunctionCallAnalysisEvent $event): void
            {
                Assert::assertSame('expected.twig', TwigUtils::extractTemplateNameFromExpression(
                    $event->getExpr()->args[0]->value,
                    $event->getStatementsSource()
                ));
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
            ['$a = "expected"; $b = ".twig"; dummy($a.$b);'],
            ['$a = "pected"; dummy("ex".$a.".twig");'],
        ];
    }

    public function testItThrowsAnExceptionWhenTryingToExtractTemplateNameFromAnUnsupportedExpression()
    {
        $hasErrors = false;
        $code = '<?php'."\n".'dummy(123);';
        $statements = StatementsProvider::parseStatements($code, '8_01_00', $hasErrors);

        $assertionHook = new class() implements AfterEveryFunctionCallAnalysisInterface {
            public static function afterEveryFunctionCallAnalysis(AfterEveryFunctionCallAnalysisEvent $event): void
            {
                TwigUtils::extractTemplateNameFromExpression(
                    $event->getExpr()->args[0]->value,
                    $event->getStatementsSource()
                );
            }
        };

        $statementsAnalyzer = self::createStatementsAnalyzer($assertionHook);

        $this->expectException(RuntimeException::class);
        $statementsAnalyzer->analyze($statements, new Context());
    }

    private static function createStatementsAnalyzer(AfterEveryFunctionCallAnalysisInterface $hook): StatementsAnalyzer
    {
        /** @var Config $config */
        $config = (function () { return new self(); })->bindTo(null, Config::class)();
        $config->eventDispatcher->registerClass(get_class($hook));

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
