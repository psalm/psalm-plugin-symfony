<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Scalar\String_;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\Type\Atomic;
use Psalm\Type\Union;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;

final class ParameterBagHandler implements AfterMethodCallAnalysisInterface
{
    /**
     * @var ContainerMeta|null
     */
    private static $containerMeta;

    public static function init(ContainerMeta $containerMeta): void
    {
        self::$containerMeta = $containerMeta;
    }

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        if (!self::$containerMeta) {
            return;
        }

        $declaring_method_id = $event->getDeclaringMethodId();
        $expr = $event->getExpr();

        if (!ContainerHandler::isContainerMethod($declaring_method_id, 'getparameter')
            && 'Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface::get' !== $declaring_method_id
        ) {
            return;
        }

        if (!isset($expr->args[0]->value) || !($expr->args[0]->value instanceof String_)) {
            return;
        }

        $argument = $expr->args[0]->value->value;

        try {
            $parameterTypes = self::$containerMeta->guessParameterType($argument);
        } catch (ParameterNotFoundException) {
            // maybe emit ParameterNotFound issue
            return;
        }

        if (null === $parameterTypes || [] === $parameterTypes) {
            return;
        }

        $event->setReturnTypeCandidate(new Union(array_map(fn (string $parameterType): Atomic => Atomic::create($parameterType), $parameterTypes)));
    }
}
