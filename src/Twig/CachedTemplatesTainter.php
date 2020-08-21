<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;


use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Call\MethodCallAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\Hook\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\SymfonyPsalmPlugin\Test\TwigBridge;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use RuntimeException;
use Twig\Environment;
use Twig\Template;

/**
 * This hook transforms a call to `Twig\Environment::render()` in a call to the actual twig compiled template `doDisplay()` method.
 */
class CachedTemplatesTainter implements MethodReturnTypeProviderInterface
{
    public static function getClassLikeNames(): array
    {
        return [Environment::class];
    }

    public static function getMethodReturnType(StatementsSource $source, string $fq_classlike_name, string $method_name_lowercase, array $call_args, Context $context, CodeLocation $code_location, array $template_type_parameters = null, string $called_fq_classlike_name = null, string $called_method_name_lowercase = null): void
    {
        if(!$source instanceof StatementsAnalyzer) {
            throw new RuntimeException(sprintf('The %s::%s hook can only be called using a %s.', __CLASS__, __METHOD__, StatementsAnalyzer::class));
        }

        if ($method_name_lowercase !== 'render') {
            return;
        }

        $fake_method_call = new MethodCall(
            new Variable(
                '__fake_twig_env_var__'
            ),
            new Identifier(
                'doDisplay'
            ),
            [$call_args[1]]
        );

        $firstArgument = $call_args[0]->value;
        if(!$firstArgument instanceof String_) {
            return;
        }

        $template = self::getTemplate($source->getCodebase()->config, $firstArgument->value);

        $context->vars_in_scope['$__fake_twig_env_var__'] = new Union([
            new TNamedObject(get_class($template))
        ]);

        MethodCallAnalyzer::analyze(
            $source,
            $fake_method_call,
            $context
        );
    }

    private static function getTemplate(Config $config, string $templateName): Template
    {
        $twigEnvironment = TwigBridge::getEnvironment($config->base_dir, $config->base_dir.'cache/twig');
        $template = $twigEnvironment->load($templateName);

        /** @psalm-suppress InternalMethod This is mandatory to be able to link back to corresponding PHP class */
        return $template->unwrap();
    }
}
