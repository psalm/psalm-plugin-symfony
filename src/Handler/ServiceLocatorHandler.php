<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use Psalm\Aliases;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Exception\TypeParseTreeException;
use Psalm\Internal\Analyzer\MethodAnalyzer;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\SymfonyPsalmPlugin\Issue\ServiceNotFound;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

final class ServiceLocatorHandler implements AfterClassLikeVisitInterface, AfterCodebasePopulatedInterface, AfterExpressionAnalysisInterface, AfterMethodCallAnalysisInterface
{
    private static ?ContainerMeta $containerMeta = null;

    public static function init(?ContainerMeta $containerMeta): void
    {
        self::$containerMeta = $containerMeta;
    }

    /** @var array<string, array{Union, ?Union}> */
    private static array $parameters = [];

    /** @var array<string, array{Union, ?Union}> */
    private static array $properties = [];

    /** @var array<string, Union|null> */
    private static array $subscribers = [];

    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $node = $event->getStmt();
        if (!$node instanceof Class_) {
            return;
        }
        $storage = $event->getStorage();
        $aliases = $event->getStatementsSource()->getAliases();
        $data = ['parameters' => [], 'assignments' => [], 'subscribed' => [], 'parent' => null];
        if (null !== $node->extends) {
            $data['parent'] = self::className($node->extends, $aliases, $storage->name);
        }
        foreach ($node->getMethods() as $method) {
            $methodName = strtolower($method->name->name);
            $locals = [];
            foreach ($method->params as $parameter) {
                if (!$parameter->var instanceof Variable || !\is_string($parameter->var->name)) {
                    continue;
                }
                if ('__construct' === $methodName && 0 !== $parameter->flags) {
                    $data['assignments'][$parameter->var->name] = $parameter->var->name;
                }
                foreach ($parameter->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        if ('Symfony\Component\DependencyInjection\Attribute\AutowireLocator' === self::className($attribute->name, $aliases, $storage->name)) {
                            $args = self::arguments($attribute->args, $aliases, $storage->name);
                            $data['parameters'][$methodName][$parameter->var->name] = $args['services'] ?? $args[0] ?? ['kind' => 'unknown'];
                            if ('__construct' === $methodName && 0 !== $parameter->flags) {
                                $data['assignments'][$parameter->var->name] = $parameter->var->name;
                            }
                        }
                    }
                }
            }
            foreach ($method->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ('Symfony\Contracts\Service\Attribute\SubscribedService' === self::className($attribute->name, $aliases, $storage->name)) {
                        $data['subscribed'][$methodName] = ['name' => $method->name->name, 'args' => self::arguments($attribute->args, $aliases, $storage->name)];
                    }
                }
            }
            foreach ($method->getStmts() ?? [] as $statement) {
                if ('getsubscribedservices' === $methodName && $statement instanceof Return_ && null !== $statement->expr) {
                    $data['declared'] = self::describe($statement->expr, $aliases, $storage->name);
                }
                if ('__construct' !== $methodName || !$statement instanceof Expression || !$statement->expr instanceof Assign) {
                    continue;
                }
                $assign = $statement->expr;
                if ($assign->var instanceof Variable && \is_string($assign->var->name)) {
                    $locals[$assign->var->name] = $assign->expr instanceof Variable && \is_string($assign->expr->name) ? ($locals[$assign->expr->name] ?? $assign->expr->name) : null;
                }
                if ($assign->var instanceof PropertyFetch && $assign->var->var instanceof Variable && 'this' === $assign->var->var->name && $assign->var->name instanceof Identifier && $assign->expr instanceof Variable && \is_string($assign->expr->name)) {
                    $name = array_key_exists($assign->expr->name, $locals) ? $locals[$assign->expr->name] : $assign->expr->name;
                    if (null !== $name) {
                        $data['assignments'][$assign->var->name->name] = $name;
                    }
                }
            }
        }
        foreach ($node->getProperties() as $property) {
            $getHook = false;
            foreach ($property->hooks as $hook) {
                $getHook = $getHook || 'get' === $hook->name->name;
            }
            if (!$getHook) {
                continue;
            }
            foreach ($property->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ('Symfony\Contracts\Service\Attribute\SubscribedService' === self::className($attribute->name, $aliases, $storage->name)) {
                        $data['subscribed_properties'][$property->props[0]->name->name] = self::arguments($attribute->args, $aliases, $storage->name);
                    }
                }
            }
        }
        $storage->custom_metadata[self::class] = $data;
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        self::$parameters = self::$properties = self::$subscribers = [];
        $codebase = $event->getCodebase();
        if ($codebase->classlike_storage_provider->has('Psr\Container\ContainerInterface')) {
            $container = $codebase->classlike_storage_provider->get('Psr\Container\ContainerInterface');
            // Existing container implementations don't need to declare a service map.
            $container->template_defaults['TServices'] = Type::getMixed();
        }
        foreach ($codebase->classlike_storage_provider->getAll() as $class) {
            $data = $class->custom_metadata[self::class] ?? null;
            if (!\is_array($data)) {
                continue;
            }
            $methods = $data['parameters'] ?? [];
            if (!\is_array($methods)) {
                continue;
            }
            foreach ($methods as $method => $parameters) {
                if (!\is_array($parameters)) {
                    continue;
                }
                foreach ($parameters as $name => $value) {
                    $map = self::map($value, $codebase);
                    $native = null;
                    foreach ($class->methods[$method]->params as $parameter) {
                        if ($parameter->name === $name) {
                            $native = $parameter->type;
                        }
                    }
                    self::$parameters[strtolower($class->name.'::'.$method).'$'.$name] = [$map, $native];
                    if ('__construct' === $method) {
                        $assignments = $data['assignments'] ?? [];
                        if (!\is_array($assignments)) {
                            continue;
                        }
                        foreach ($assignments as $property => $parameterName) {
                            if ($parameterName === $name && isset($class->properties[$property])) {
                                self::$properties[strtolower($class->name).'::$'.$property] = [$map, $class->properties[$property]->type];
                            }
                        }
                    }
                }
            }
        }
        $subscribers = [];
        foreach ($codebase->classlike_storage_provider->getAll() as $class) {
            if (isset($class->class_implements['symfony\contracts\service\servicesubscriberinterface'])) {
                $map = self::subscriber($class, $codebase);
                if (null !== $map) {
                    $subscribers[strtolower($class->name)] = $map;
                }
            }
        }
        foreach ($codebase->classlike_storage_provider->getAll() as $class) {
            $map = $subscribers[strtolower($class->name)] ?? null;
            if (null === $map) {
                continue;
            }
            $data = $class->custom_metadata[self::class] ?? null;
            $assignments = \is_array($data) ? ($data['assignments'] ?? []) : [];
            foreach ($class->methods as $methodName => $method) {
                foreach ($method->params as $parameter) {
                    $id = strtolower($class->name.'::'.$methodName).'$'.$parameter->name;
                    if (isset(self::$parameters[$id]) || null === $parameter->type || !\in_array(self::objectNames($parameter->type), [['Psr\Container\ContainerInterface'], ['Symfony\Component\DependencyInjection\ContainerInterface']], true)) {
                        continue;
                    }
                    foreach ($parameter->attributes as $attribute) {
                        if ('Symfony\Component\DependencyInjection\Attribute\Autowire' === $attribute->fq_class_name) {
                            continue 2;
                        }
                    }
                    self::$parameters[$id] = [$map, $parameter->type];
                    if ('__construct' === $methodName && \is_array($assignments)) {
                        foreach ($assignments as $property => $name) {
                            if ($name === $parameter->name && isset($class->properties[$property])) {
                                self::$properties[strtolower($class->name).'::$'.$property] = [$map, $class->properties[$property]->type];
                            }
                        }
                    }
                }
            }
        }
    }

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        $source = $event->getStatementsSource();
        $provider = $source->getNodeTypeProvider();
        $original = $provider->getType($expr);
        if (null === $original || null !== self::getServices($original)) {
            return null;
        }
        $entry = null;
        if ($expr instanceof Variable && \is_string($expr->name) && $source->getSource() instanceof MethodAnalyzer) {
            $method = $event->getContext()->calling_method_id;
            if (null !== $method) {
                $entry = self::$parameters[$method.'$'.$expr->name] ?? null;
            }
        } elseif ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            $receiver = $provider->getType($expr->var);
            foreach ($receiver?->getAtomicTypes() ?? [] as $object) {
                if (!$object instanceof TNamedObject || !$event->getCodebase()->classlike_storage_provider->has($object->value)) {
                    continue;
                }
                $class = $event->getCodebase()->classlike_storage_provider->get($object->value);
                if ('container' === $expr->name->name && isset(self::$subscribers[strtolower($class->name)])) {
                    $owner = $class;
                    while (!self::usesSubscriberTrait($owner, $event->getCodebase()) && null !== $owner->parent_class && $event->getCodebase()->classlike_storage_provider->has($owner->parent_class)) {
                        $owner = $event->getCodebase()->classlike_storage_provider->get($owner->parent_class);
                    }
                    if (self::usesSubscriberTrait($owner, $event->getCodebase())) {
                        $entry = [self::$subscribers[strtolower($class->name)], $original];
                        break;
                    }
                }
                $declaring = $class->declaring_property_ids[$expr->name->name] ?? $class->name;
                $declaring = explode('::$', $declaring)[0];
                $entry = self::$properties[strtolower($declaring).'::$'.$expr->name->name] ?? null;
            }
        }
        if (null === $entry || null === $entry[0] || null === $entry[1] || self::objectNames($original) !== self::objectNames($entry[1])) {
            return null;
        }
        $locator = new TGenericObject('Psr\Container\ContainerInterface', [$entry[0]]);
        $types = [];
        foreach ($original->getAtomicTypes() as $type) {
            if (!$type instanceof TNamedObject) {
                $types[] = $type;
            } elseif ('Psr\Container\ContainerInterface' === $type->value) {
                $types[] = $locator;
            } else {
                $types[] = $type->addIntersectionType($locator);
            }
        }
        $refined = new Union($types);
        $provider->setType($expr, $refined);
        if ($expr instanceof Variable && \is_string($expr->name)) {
            $event->getContext()->vars_in_scope['$'.$expr->name] = $refined;
        }

        return null;
    }

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $expr = $event->getExpr();
        if (!$expr instanceof Expr\MethodCall || !$expr->name instanceof Identifier || !\in_array($expr->name->name, ['get', 'has'], true) || !isset($expr->args[0]) || !$expr->args[0] instanceof Arg) {
            return;
        }
        $source = $event->getStatementsSource();
        $map = self::getServices($source->getNodeTypeProvider()->getType($expr->var));
        if (null === $map) {
            return;
        }
        $keys = $source->getNodeTypeProvider()->getType($expr->args[0]->value);
        $array = $map->getSingleAtomic();
        $result = null;
        foreach ($keys?->getAtomicTypes() ?? [] as $key) {
            if (!$key instanceof TLiteralString) {
                $event->setReturnTypeCandidate('has' === $expr->name->name ? Type::getBool() : self::values($map));

                return;
            }
            $value = $array instanceof TKeyedArray ? ($array->properties[$key->value] ?? null) : null;
            $closed = ($array instanceof TKeyedArray && null === $array->fallback_params) || $map->isEmptyArray();
            if ('has' === $expr->name->name) {
                $current = null !== $value ? ($value->possibly_undefined ? Type::getBool() : Type::getTrue()) : ($closed ? Type::getFalse() : Type::getBool());
            } elseif (null !== $value) {
                $current = $value->setProperties(['possibly_undefined' => false]);
            } else {
                if ($closed) {
                    IssueBuffer::maybeAdd(new ServiceNotFound($key->value, new CodeLocation($source, $expr->args[0]->value)), $source->getSuppressedIssues());
                }
                $current = Type::getMixed();
            }
            $result = Type::combineUnionTypes($result, $current);
        }
        $event->setReturnTypeCandidate($result ?? Type::getMixed());
    }

    /** @psalm-pure */
    public static function getServices(?Union $type): ?Union
    {
        foreach ($type?->getAtomicTypes() ?? [] as $atomic) {
            $objects = $atomic instanceof TNamedObject ? [$atomic, ...array_values($atomic->extra_types)] : [];
            foreach ($objects as $object) {
                if ($object instanceof TGenericObject && 'Psr\Container\ContainerInterface' === $object->value && $object->type_params[0]->isArray()) {
                    return $object->type_params[0];
                }
            }
        }

        return null;
    }

    private static function subscriber(ClassLikeStorage $class, Codebase $codebase): ?Union
    {
        $name = strtolower($class->name);
        if (array_key_exists($name, self::$subscribers)) {
            return self::$subscribers[$name];
        }
        self::$subscribers[$name] = null;
        $data = $class->custom_metadata[self::class] ?? null;
        if (!\is_array($data)) {
            return self::$subscribers[$name] = self::compiledSubscriber($class->name, null, $codebase);
        }
        if (isset($data['declared'])) {
            $map = self::map($data['declared'], $codebase);

            $map = $map->isEmptyArray() || $map->getSingleAtomic() instanceof TKeyedArray ? $map : null;

            return self::$subscribers[$name] = self::compiledSubscriber($class->name, $map, $codebase);
        }
        $parent = $data['parent'] ?? null;
        $map = \is_string($parent) && $codebase->classlike_storage_provider->has($parent) ? self::subscriber($codebase->classlike_storage_provider->get($parent), $codebase) : null;
        $usesTrait = self::usesSubscriberTrait($class, $codebase);
        if (!$usesTrait) {
            return self::$subscribers[$name] = self::compiledSubscriber($class->name, $map, $codebase);
        }
        $properties = [];
        if (null !== $map && !$map->isEmptyArray()) {
            $atomic = $map->getSingleAtomic();
            if (!$atomic instanceof TKeyedArray || null !== $atomic->fallback_params) {
                return self::$subscribers[$name] = Type::getArray();
            }
            $properties = $atomic->properties;
        }
        $subscriptions = $data['subscribed'] ?? [];
        if (!\is_array($subscriptions)) {
            return null;
        }
        foreach ($subscriptions as $methodName => $subscription) {
            if (!\is_array($subscription)) {
                continue;
            }
            $subscriptionName = $subscription['name'] ?? null;
            $args = $subscription['args'] ?? null;
            if (!\is_string($subscriptionName) || !\is_array($args)) {
                continue;
            }
            $storage = $class->methods[$methodName] ?? null;
            $type = $storage?->signature_return_type;
            if (null === $storage || $storage->is_static || $storage->abstract || null === $type) {
                continue;
            }
            $properties = self::addSubscribed($properties, $args, $class->name.'::'.$subscriptionName, $type, $codebase);
        }
        $propertySubscriptions = $data['subscribed_properties'] ?? [];
        if (\is_array($propertySubscriptions) && self::usesSubscriberTrait($class, $codebase, true)) {
            foreach ($propertySubscriptions as $propertyName => $args) {
                $type = $class->properties[$propertyName]->signature_type ?? null;
                if (null !== $type && \is_array($args)) {
                    $properties = self::addSubscribed($properties, $args, $class->name.'::$'.$propertyName.'::get', $type, $codebase);
                }
            }
        }

        $map = [] === $properties ? Type::getEmptyArray() : new Union([TKeyedArray::make($properties)]);

        return self::$subscribers[$name] = self::compiledSubscriber($class->name, $map, $codebase);
    }

    private static function compiledSubscriber(string $class, ?Union $map, Codebase $codebase): ?Union
    {
        $ids = self::$containerMeta?->getSubscribedServiceIds($class);
        if (null === $ids) {
            return $map;
        }
        $atomic = $map?->isSingle() ? $map->getSingleAtomic() : null;
        $properties = $atomic instanceof TKeyedArray ? $atomic->properties : [];
        foreach ($ids as $key => $id) {
            try {
                $type = self::$containerMeta?->get($id)->getClass();
            } catch (ServiceNotFoundException) {
                $type = $codebase->classlike_storage_provider->has($id) ? $id : null;
            }
            if (null !== $type) {
                $properties[$key] = new Union([new TNamedObject($type)]);
            } elseif (!isset($properties[$key])) {
                $properties[$key] = Type::getMixed();
            }
        }

        return [] === $properties ? Type::getEmptyArray() : new Union([TKeyedArray::make($properties)]);
    }

    /** @psalm-capabilities read-props */
    private static function usesSubscriberTrait(ClassLikeStorage $class, Codebase $codebase, bool $properties = false): bool
    {
        foreach ($class->used_traits as $trait) {
            if ('symfony\contracts\service\servicemethodssubscribertrait' === strtolower($trait) || (!$properties && 'symfony\contracts\service\servicesubscribertrait' === strtolower($trait))) {
                return true;
            }
            if ($codebase->classlike_storage_provider->has($trait) && self::usesSubscriberTrait($codebase->classlike_storage_provider->get($trait), $codebase, $properties)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, Union> $properties
     * @param array<array-key, mixed> $args
     *
     * @return array<array-key, Union>
     */
    private static function addSubscribed(array $properties, array $args, string $key, Union $type, Codebase $codebase): array
    {
        $override = self::evaluate($args['key'] ?? $args[0] ?? null, $codebase);
        $key = \is_string($override) ? $override : $key;
        $typeValue = self::evaluate($args['type'] ?? $args[1] ?? null, $codebase);
        $optional = $type->isNullable() || true === self::evaluate($args['nullable'] ?? $args[2] ?? false, $codebase);
        if (\is_string($typeValue)) {
            $optional = $optional || str_starts_with($typeValue, '?');
            $type = self::serviceType(ltrim($typeValue, '?'));
        }
        $properties[$key] = self::nonNull($type)->setProperties(['possibly_undefined' => $optional]);

        return $properties;
    }

    private static function map(mixed $description, Codebase $codebase): Union
    {
        $array = self::evaluate($description, $codebase);
        if (!\is_array($array) || 'array' !== ($array['kind'] ?? null)) {
            return Type::getArray();
        }
        $entries = [];
        $next = 0;
        foreach ($array['items'] as $item) {
            if ($item['unpack']) {
                return Type::getArray();
            }
            $key = null === $item['key'] ? $next++ : self::evaluate($item['key'], $codebase);
            if (!\is_string($key) && !\is_int($key)) {
                return Type::getArray();
            }
            $entries[$key] = $item['value'];
            if (\is_int($key) && $key >= $next) {
                $next = $key + 1;
            }
        }
        $properties = [];
        foreach ($entries as $key => $entry) {
            $value = self::evaluate($entry, $codebase);
            $optional = false;
            if (\is_array($value) && 'new' === ($value['kind'] ?? null) && 'Symfony\Contracts\Service\Attribute\SubscribedService' === $value['class']) {
                $args = $value['args'];
                $override = self::evaluate($args['key'] ?? $args[0] ?? null, $codebase);
                $key = \is_string($override) ? $override : $key;
                $optional = false !== self::evaluate($args['nullable'] ?? $args[2] ?? false, $codebase);
                $value = self::evaluate($args['type'] ?? $args[1] ?? null, $codebase);
            }
            if (!\is_string($value)) {
                if (\is_int($key)) {
                    return Type::getArray();
                }
                $properties[$key] = Type::getMixed();
                continue;
            }
            $optional = $optional || str_starts_with($value, '?');
            $value = ltrim($value, '?');
            $key = \is_int($key) ? $value : $key;
            $properties[$key] = self::serviceType($value)->setProperties(['possibly_undefined' => $optional]);
        }

        return [] === $properties ? Type::getEmptyArray() : new Union([TKeyedArray::make($properties)]);
    }

    private static function serviceType(string $type): Union
    {
        try {
            return Type::parseString($type);
        } catch (TypeParseTreeException) {
            return Type::getMixed();
        }
    }

    private static function evaluate(mixed $value, Codebase $codebase): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if ('concat' === ($value['kind'] ?? null)) {
            $left = self::evaluate($value['left'], $codebase);
            $right = self::evaluate($value['right'], $codebase);

            return \is_string($left) && \is_string($right) ? $left.$right : null;
        }
        if ('constant' === ($value['kind'] ?? null)) {
            $type = $codebase->classlikes->getClassConstantType($value['class'], $value['name'], \ReflectionProperty::IS_PRIVATE);
            $atomic = $type?->isSingle() ? $type->getSingleAtomic() : null;
            if ($atomic instanceof TLiteralString || $atomic instanceof TLiteralInt) {
                return $atomic->value;
            }
            if ($atomic instanceof TKeyedArray) {
                $items = [];
                foreach ($atomic->properties as $key => $property) {
                    $part = $property->isSingle() ? $property->getSingleAtomic() : null;
                    $items[] = ['key' => $key, 'value' => $part instanceof TLiteralString ? $part->value : null, 'unpack' => false];
                }

                return ['kind' => 'array', 'items' => $items];
            }

            return null;
        }

        return $value;
    }

    /** @param array<array-key, Arg> $args */
    private static function arguments(array $args, Aliases $aliases, string $class): array
    {
        $values = [];
        foreach ($args as $i => $arg) {
            $values[$arg->name?->name ?? $i] = self::describe($arg->value, $aliases, $class);
        }

        return $values;
    }

    private static function describe(Expr $expr, Aliases $aliases, string $class): mixed
    {
        if ($expr instanceof String_ || $expr instanceof Int_) {
            return $expr->value;
        }
        if ($expr instanceof ConstFetch) {
            return match (strtolower($expr->name->toString())) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }
        if ($expr instanceof Concat) {
            return ['kind' => 'concat', 'left' => self::describe($expr->left, $aliases, $class), 'right' => self::describe($expr->right, $aliases, $class)];
        }
        if ($expr instanceof ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            $name = self::className($expr->class, $aliases, $class);

            return 'class' === strtolower($expr->name->name) ? $name : ['kind' => 'constant', 'class' => $name, 'name' => $expr->name->name];
        }
        if ($expr instanceof New_ && $expr->class instanceof Name) {
            return ['kind' => 'new', 'class' => self::className($expr->class, $aliases, $class), 'args' => self::arguments($expr->getArgs(), $aliases, $class)];
        }
        if ($expr instanceof Array_) {
            $items = [];
            foreach ($expr->items as $item) {
                $items[] = ['key' => null === $item->key ? null : self::describe($item->key, $aliases, $class), 'value' => self::describe($item->value, $aliases, $class), 'unpack' => $item->unpack];
            }

            return ['kind' => 'array', 'items' => $items];
        }

        return ['kind' => 'unknown'];
    }

    private static function className(Name $name, Aliases $aliases, string $class): string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return $resolved->toString();
        }
        if ($name instanceof Name\FullyQualified) {
            return $name->toString();
        }
        if (\in_array(strtolower($name->toString()), ['self', 'static'], true)) {
            return $class;
        }
        $parts = explode('\\', $name->toString());
        $alias = $aliases->uses[strtolower($parts[0])] ?? null;

        return null !== $alias ? $alias.substr($name->toString(), \strlen($parts[0])) : (null === $aliases->namespace || '' === $aliases->namespace ? '' : $aliases->namespace.'\\').$name->toString();
    }

    /** @psalm-pure */
    private static function nonNull(Union $type): Union
    {
        $types = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNull) {
                $types[] = $atomic;
            }
        }

        return [] === $types ? Type::getMixed() : new Union($types);
    }

    /** @psalm-capabilities read-props|write-this-props */
    private static function values(Union $map): Union
    {
        $array = $map->getSingleAtomic();
        if ($array instanceof TArray) {
            return $array->type_params[1];
        }
        $result = null;
        if ($array instanceof TKeyedArray) {
            foreach ($array->properties as $value) {
                $result = Type::combineUnionTypes($result, $value->setProperties(['possibly_undefined' => false]));
            }
        }

        return $result ?? Type::getMixed();
    }

    /**
     * @return list<string>
     *
     * @psalm-pure
     */
    private static function objectNames(Union $type): array
    {
        $names = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject) {
                $names[] = $atomic->value;
            }
        }
        sort($names);

        return $names;
    }
}
