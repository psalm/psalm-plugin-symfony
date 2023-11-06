<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Psalm\Internal\Analyzer\Statements\Expression\Call\MethodCallAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\SymfonyPsalmPlugin\Exception\TemplateNameUnresolvedException;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Twig\Environment;

/**
 * This hook transforms a call to `Twig\Environment::render()` in a call to the actual twig compiled template `doDisplay()` method.
 */
class CachedTemplatesTainter implements MethodReturnTypeProviderInterface
{
    public static function getClassLikeNames(): array
    {
        return [Environment::class];
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();
        $method_name_lowercase = $event->getMethodNameLowercase();
        $context = $event->getContext();
        $call_args = $event->getCallArgs();

        if (!$source instanceof StatementsAnalyzer) {
            throw new \RuntimeException(sprintf('The %s::%s hook can only be called using a %s.', __CLASS__, __METHOD__, StatementsAnalyzer::class));
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
            isset($call_args[1]) ? [$call_args[1]] : []
        );

        if (!isset($call_args[0])) {
            return null;
        }

        try {
            $templateName = TwigUtils::extractTemplateNameFromExpression($call_args[0]->value, $source);
        } catch (TemplateNameUnresolvedException $exception) {
            if ($source instanceof StatementsAnalyzer) {
                $source->getProjectAnalyzer()->progress->debug($exception->getMessage());
            }

            return null;
        }

        $cacheClassName = CachedTemplatesMapping::getCacheClassName($templateName);

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
