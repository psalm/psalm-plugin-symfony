<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\String_;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\TaintKindGroup;
use Psalm\Type\Union;
use Symfony\Component\HttpFoundation\HeaderBag;

class HeaderBagHandler implements MethodReturnTypeProviderInterface
{
    public static function getClassLikeNames(): array
    {
        return [
            HeaderBag::class,
        ];
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $fq_classlike_name = $event->getFqClasslikeName();
        $method_name_lowercase = $event->getMethodNameLowercase();
        $call_args = $event->getCallArgs();
        $source = $event->getSource();
        $code_location = $event->getCodeLocation();

        if (HeaderBag::class !== $fq_classlike_name) {
            return null;
        }

        if ('get' !== $method_name_lowercase) {
            return null;
        }

        $type = static::makeReturnType($call_args);

        if ($call_args[0]->value instanceof String_ && 'user-agent' === $call_args[0]->value->value) {
            $uniqId = $source->getFileName().':'.$code_location->getLineNumber().'-'.$code_location->getColumn();
            $source->getCodebase()->addTaintSource(
                $type,
                'tainted-'.$uniqId,
                TaintKindGroup::ALL_INPUT,
                $code_location
            );
        }

        return $type;
    }

    /**
     * @param array<\PhpParser\Node\Arg> $call_args
     */
    private static function makeReturnType(array $call_args): Union
    {
        if (3 === count($call_args) && (($arg = $call_args[2]->value) instanceof ConstFetch) && 'false' === $arg->name->parts[0]) {
            return new Union([new TArray([new Union([new TInt()]), new Union([new TString()])])]);
        }

        if (isset($call_args[1])) {
            if ($call_args[1]->value instanceof String_) {
                return new Union([new TString()]);
            }
        }

        return new Union([new TString(), new TNull()]);
    }
}
