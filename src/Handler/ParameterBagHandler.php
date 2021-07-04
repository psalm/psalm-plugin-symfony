<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Scalar\String_;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\Type\Atomic;
use Psalm\Type\Union;

class ParameterBagHandler implements AfterMethodCallAnalysisInterface
{
    /**
     * @var ContainerMeta|null
     */
    private static $containerMeta;

    public static function init(ContainerMeta $containerMeta): void
    {
        self::$containerMeta = $containerMeta;
    }

    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $declaring_method_id = $event->getDeclaringMethodId();
        $expr = $event->getExpr();

        if (!self::$containerMeta || 'Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface::get' !== $declaring_method_id) {
            return;
        }

        if (!isset($expr->args[0]->value) || !($expr->args[0]->value instanceof String_)) {
            return;
        }

        $argument = $expr->args[0]->value->value;

        // @todo find a better way to calculate return type
        switch (gettype(self::$containerMeta->getParameter($argument))) {
            case 'string':
                $event->setReturnTypeCandidate(new Union([Atomic::create('string')]));
                break;
            case 'boolean':
                $event->setReturnTypeCandidate(new Union([Atomic::create('bool')]));
                break;
            case 'integer':
                $event->setReturnTypeCandidate(new Union([Atomic::create('integer')]));
                break;
            case 'double':
                $event->setReturnTypeCandidate(new Union([Atomic::create('float')]));
                break;
            case 'array':
                $event->setReturnTypeCandidate(new Union([Atomic::create('array')]));
                break;
        }
    }
}
