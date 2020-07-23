<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Taint;


use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Call\MethodCallAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\Hook\AfterCodebasePopulatedInterface;
use Psalm\Plugin\Hook\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\SymfonyPsalmPlugin\Plugin;
use Psalm\SymfonyPsalmPlugin\Test\TwigBridge;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use RuntimeException;
use Twig\Environment;
use Twig\Node\ModuleNode;
use Twig\NodeTraverser;

class TwigTaint implements MethodReturnTypeProviderInterface
{
    public static function getClassLikeNames(): array
    {
        return [Environment::class];
    }

    public static function getMethodReturnType(
        StatementsSource $source,
        string $fq_classlike_name,
        string $method_name_lowercase,
        array $call_args,
        Context $context,
        CodeLocation $code_location,
        array $template_type_parameters = null,
        string $called_fq_classlike_name = null,
        string $called_method_name_lowercase = null
    ) {
        if(!$source instanceof StatementsAnalyzer) {
            throw new RuntimeException(sprintf('The %s::%s hook can only be called using a %s.', __CLASS__, __METHOD__, StatementsAnalyzer::class));
        }

        if ($method_name_lowercase !== 'render') {
            return;
        }

        $firstArgument = $call_args[0]->value;

        if (!$firstArgument instanceof String_) {
            return;
        }

        $codebase = $source->getCodebase();

        $config = $codebase->config;

        $twigEnvironment = TwigBridge::getEnvironment($config->base_dir, $config->base_dir.'cache/twig');

        $node = self::getParsedTemplate($twigEnvironment, $firstArgument->value);

        $printNodeTainter = new PrintNodeTainter();

        $traverser = new NodeTraverser($twigEnvironment, [$printNodeTainter]);

        $traverser->traverse($node);

        if ($printNodeTainter->sinks) {
            foreach ($printNodeTainter->sinks as ['name' => $name, 'location' => $location]) {
                $file_analyzer = new \Psalm\Internal\Analyzer\FileAnalyzer(
                    $source->getProjectAnalyzer(),
                    $location->file_path,
                    $location->file_name
                );

                $node_data = new \Psalm\Internal\Provider\NodeDataProvider();

                $second_arg_type = $source->getNodeTypeProvider()->getType($call_args[1]->value);

                $echo_statements_analyzer = new StatementsAnalyzer(
                    $file_analyzer,
                    $node_data
                );

                if (!$codebase->file_storage_provider->has($location->file_path)) {
                    $codebase->file_storage_provider->create($location->file_path);
                    $source->getProjectAnalyzer()->addProjectFile($location->file_path);
                }

                $context = new \Psalm\Context();

                $context->vars_in_scope['$__twig_context__'] = clone $second_arg_type;

                $fake_echo = new \PhpParser\Node\Stmt\Echo_(
                    [
                        new \PhpParser\Node\Expr\ArrayDimFetch(
                            new \PhpParser\Node\Expr\Variable(
                                '__twig_context__',
                                [
                                    'startLine' => $location->raw_line_number,
                                    'startFilePos' => $location->raw_file_start,
                                    'endFilePos' => $location->raw_file_end,
                                ]
                            ),
                            new \PhpParser\Node\Scalar\String_(
                                $name,
                                [
                                    'startLine' => $location->raw_line_number,
                                    'startFilePos' => $location->raw_file_start,
                                    'endFilePos' => $location->raw_file_end,
                                ]
                            ),
                            [
                                'startLine' => $location->raw_line_number,
                                'startFilePos' => $location->raw_file_start,
                                'endFilePos' => $location->raw_file_end,
                            ]
                        )
                    ],
                    [
                        'startLine' => $location->raw_line_number,
                        'startFilePos' => $location->raw_file_start,
                        'endFilePos' => $location->raw_file_end,
                    ]
                );

                $echo_statements_analyzer->analyze([$fake_echo], $context);
            }
        }
    }

    private static function getParsedTemplate(Environment $twigEnvironment, $templateName): ModuleNode
    {
        $template = $twigEnvironment->load($templateName);

        $source = $twigEnvironment->getLoader()->getSourceContext($templateName);

        return $twigEnvironment->parse($twigEnvironment->tokenize($source));
    }
}
