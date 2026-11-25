<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use Psalm\Codebase;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Storage\AttributeStorage;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\PropertyStorage;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Union;

/**
 * Taints what the browser sends to a Symfony UX live component: the writable live props and those synchronized with
 * the query string, the old values the `onUpdated` hooks of the writable props are given, and the `#[LiveArg]`
 * arguments of the actions and listeners. The other live props come back with a checksum of the server, so they are
 * what the server rendered.
 */
final class LiveComponentHandler implements AfterClassLikeAnalysisInterface
{
    private const LIVE_PROP = 'Symfony\UX\LiveComponent\Attribute\LiveProp';

    private const LIVE_ARG = 'Symfony\UX\LiveComponent\Attribute\LiveArg';

    private const IDENTITY = '@identity';

    /**
     * The positions of the arguments of the LiveProp attribute read here.
     */
    private const WRITABLE_ARGUMENT = 0;
    private const ON_UPDATED_ARGUMENT = 8;
    private const URL_ARGUMENT = 9;

    #[\Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        $codebase = $event->getCodebase();
        $statementsSource = $event->getStatementsSource();
        if (!$codebase->tracksTaintFlow($statementsSource)) {
            return null;
        }

        $storage = $event->getClasslikeStorage();
        foreach ($storage->properties as $name => $property) {
            foreach ($property->attributes as $attribute) {
                if (self::LIVE_PROP === $attribute->fq_class_name) {
                    self::taintLiveProp($codebase, $statementsSource, $storage, $name, $property, $attribute);
                }
            }
        }

        foreach ($storage->methods as $methodName => $method) {
            foreach ($method->params as $offset => $param) {
                foreach ($param->attributes as $attribute) {
                    if (self::LIVE_ARG === $attribute->fq_class_name) {
                        self::taintArgument($codebase, $statementsSource, $storage, $methodName, $offset, $attribute, 'live arg');
                    }
                }
            }
        }

        return null;
    }

    private static function taintLiveProp(Codebase $codebase, StatementsSource $statementsSource, ClassLikeStorage $storage, string $name, PropertyStorage $property, AttributeStorage $attribute): void
    {
        $writable = self::getArgument($attribute, 'writable', self::WRITABLE_ARGUMENT);
        $url = self::getArgument($attribute, 'url', self::URL_ARGUMENT);

        $isUrl = null !== $url && !$url->isFalse();
        $isWritable = null !== $writable && !$writable->isFalse();
        if (!$isUrl && !$isWritable) {
            return;
        }

        $writablePaths = null === $writable ? null : self::getWritablePaths($writable);
        $objectClasses = self::getObjectClasses($property->type);
        $source = self::getSource($codebase, $attribute, 'live prop '.$storage->name.'::$'.$name);
        if (null === $source) {
            return;
        }

        // a whole value the browser sends, or writable paths of an array: the browser sets any of its keys
        if ($isUrl || null === $writablePaths || [] === $objectClasses || in_array(self::IDENTITY, $writablePaths, true)) {
            $codebase->addTaintPropertyAssignment($statementsSource, $source, $storage->name.'::$'.$name);
        } else {
            // writable paths of an object: the browser sets these properties of the object the server sent
            foreach ($objectClasses as $class) {
                foreach ($writablePaths as $path) {
                    $codebase->addTaintPropertyAssignment($statementsSource, $source, $class.'::$'.explode('.', $path)[0]);
                }
            }
        }

        $onUpdated = self::getArgument($attribute, 'onUpdated', self::ON_UPDATED_ARGUMENT);
        foreach ($onUpdated?->getAtomicTypes() ?? [] as $atomic) {
            $hooks = $atomic instanceof TKeyedArray ? array_values($atomic->properties) : [new Union([$atomic])];
            foreach ($hooks as $hook) {
                if ($hook->isSingleStringLiteral()) {
                    self::taintArgument($codebase, $statementsSource, $storage, strtolower($hook->getSingleStringLiteral()->value), 0, $attribute, 'live prop old value');
                }
            }
        }
    }

    /**
     * @param lowercase-string $methodName
     */
    private static function taintArgument(Codebase $codebase, StatementsSource $statementsSource, ClassLikeStorage $storage, string $methodName, int $offset, AttributeStorage $attribute, string $label): void
    {
        if (!isset($storage->methods[$methodName])) {
            return;
        }

        $methodId = $storage->name.'::'.$storage->methods[$methodName]->cased_name;
        $argument = $codebase->getTaintArgumentNode($statementsSource, $methodId, $offset);
        $source = self::getSource($codebase, $attribute, $label.' '.$methodId);
        if (null !== $argument && null !== $source) {
            $codebase->addTaintPath($statementsSource, $source, $argument);
        }
    }

    private static function getSource(Codebase $codebase, AttributeStorage $attribute, string $label): ?DataFlowNode
    {
        $nodes = $codebase->addTaintSource(new Union([new TString()]), $label, $attribute->location)->parent_nodes;

        return [] === $nodes ? null : reset($nodes);
    }

    /**
     * The type of an argument of the attribute, null when it isn't given, or `true` when Psalm doesn't know its value.
     */
    private static function getArgument(AttributeStorage $attribute, string $name, int $position): ?Union
    {
        foreach ($attribute->args as $i => $arg) {
            if (null === $arg->name ? $position === $i : $name === $arg->name) {
                return $arg->type instanceof Union ? $arg->type : new Union([new TTrue()]);
            }
        }

        return null;
    }

    /**
     * The writable paths of a `writable` argument, or null when the whole value is writable.
     *
     * @return list<string>|null
     */
    private static function getWritablePaths(Union $writable): ?array
    {
        $paths = [];
        foreach ($writable->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TKeyedArray) {
                return null;
            }

            foreach ($atomic->properties as $path) {
                if (!$path->isSingleStringLiteral()) {
                    return null;
                }

                $paths[] = $path->getSingleStringLiteral()->value;
            }
        }

        return $paths;
    }

    /**
     * The classes of the objects a property holds, empty if it can hold something else.
     *
     * @return list<string>
     */
    private static function getObjectClasses(?Union $type): array
    {
        $classes = [];
        foreach ($type?->getAtomicTypes() ?? [] as $atomic) {
            if ($atomic instanceof TNull) {
                continue;
            }

            if (!$atomic instanceof TNamedObject) {
                return [];
            }

            $classes[] = $atomic->value;
        }

        return $classes;
    }
}
