<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Call\MethodCallAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\Hook\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
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

    public static function getMethodReturnType(
        StatementsSource $source,
        string $fq_classlike_name,
        string $method_name_lowercase,
        array $call_args,
        Context $context,
        CodeLocation $code_location,
        ?array $template_type_parameters = null,
        ?string $called_fq_classlike_name = null,
        ?string $called_method_name_lowercase = null
    ): ?Union {
        if (!$source instanceof StatementsAnalyzer) {
            throw new RuntimeException(sprintf('The %s::%s hook can only be called using a %s.', __CLASS__, __METHOD__, StatementsAnalyzer::class));
        }

        if ('render' !== $method_name_lowercase) {
            return null;
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
        if (!$firstArgument instanceof String_) {
            return null;
        }

        $cacheClassName = CachedTemplatesMapping::getCacheClassName($firstArgument->value);

        $context->vars_in_scope['$__fake_twig_env_var__'] = new Union([
            new TNamedObject($cacheClassName),
        ]);

        MethodCallAnalyzer::analyze(
            $source,
            $fake_method_call,
            $context
        );

        return null;
    }
}
