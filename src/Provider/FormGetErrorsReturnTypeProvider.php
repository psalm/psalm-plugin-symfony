<?php

namespace Psalm\SymfonyPsalmPlugin\Provider;

use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;

class FormGetErrorsReturnTypeProvider implements \Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface
{
    public static function getClassLikeNames(): array
    {
        return ['Symfony\Component\Form\FormInterface'];
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $method_name_lowercase = $event->getMethodNameLowercase();
        if ('geterrors' !== $method_name_lowercase) {
            return null;
        }

        $args = $event->getCallArgs();
        $source = $event->getSource();

        if (isset($args[0]) && isset($args[1])) {
            $first_arg_type = $source->getNodeTypeProvider()->getType($args[0]->value);
            $second_arg_type = $source->getNodeTypeProvider()->getType($args[1]->value);

            if (
                $first_arg_type
                && $first_arg_type->isTrue()
                && $second_arg_type
                && $second_arg_type->isFalse()
            ) {
                return new Type\Union([
                    new Type\Atomic\TGenericObject(FormErrorIterator::class, [
                        new Type\Union([
                            new TNamedObject(FormError::class),
                            new TNamedObject(FormErrorIterator::class),
                        ]),
                    ]),
                ]);
            }
        }

        return new Type\Union([
            new Type\Atomic\TGenericObject(FormErrorIterator::class, [
                new Type\Union([new TNamedObject(FormError::class)]),
            ]),
        ]);
    }
}
