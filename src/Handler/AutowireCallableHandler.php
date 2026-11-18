<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Codebase\ConstantTypeResolver;
use Psalm\Internal\Codebase\InternalCallMapHandler;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TypeExpander;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\AfterFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterFileAnalysisEvent;
use Psalm\Storage\AttributeStorage;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\PropertyStorage;
use Psalm\SymfonyPsalmPlugin\Issue\InvalidAutowireCallable;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\Type;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

final class AutowireCallableHandler implements AfterClassLikeVisitInterface, AfterCodebasePopulatedInterface, AfterFileAnalysisInterface
{
    private static ?ContainerMeta $containerMeta = null;

    public static function init(?ContainerMeta $containerMeta): void
    {
        self::$containerMeta = $containerMeta;
    }

    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $node = $event->getStmt();
        $constructor = $node->getMethod('__construct');
        if ($node instanceof Class_) {
            // only the parameters of the constructor that have attributes can be copied to properties
            if ([] === array_filter($constructor?->params ?? [], static fn (Param $parameter): bool => [] !== $parameter->attrGroups)) {
                return;
            }
        } elseif (!$node instanceof Trait_) {
            return;
        }
        $assignments = [];
        $copies = [];
        foreach ($constructor?->getStmts() ?? [] as $statement) {
            if (!$statement instanceof Expression || !$statement->expr instanceof Assign) {
                continue;
            }
            $assign = $statement->expr;
            if ($assign->var instanceof PropertyFetch && $assign->var->var instanceof Variable && 'this' === $assign->var->var->name && $assign->var->name instanceof Identifier && $assign->expr instanceof Variable && \is_string($assign->expr->name)) {
                $assignments[$assign->var->name->name][] = $assign->expr->name;
                $copies[spl_object_id($assign)] = true;
            }
        }

        // Collect the other writes to properties, on any object: a property gets the inferred signature only when the
        // parameter is the only value written to it
        $targets = [];
        $writes = [];
        $references = [];
        $dynamic = false;
        $isWrite = static fn (Node $node): bool => $node instanceof Assign || $node instanceof AssignRef || $node instanceof AssignOp || $node instanceof PreInc || $node instanceof PreDec || $node instanceof PostInc || $node instanceof PostDec || $node instanceof Foreach_ || $node instanceof FuncCall || $node instanceof StaticCall || $node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof New_;
        foreach ((new NodeFinder())->find($node->stmts, $isWrite) as $write) {
            if ($write instanceof Assign) {
                if (!isset($copies[spl_object_id($write)]) && !self::isNull($write->expr)) {
                    $targets[] = $write->var;
                }
            } elseif ($write instanceof AssignRef) {
                $targets[] = $write->var;
                $targets[] = $write->expr;
            } elseif ($write instanceof AssignOp || $write instanceof PreInc || $write instanceof PreDec || $write instanceof PostInc || $write instanceof PostDec) {
                $targets[] = $write->var;
            } elseif ($write instanceof Foreach_) {
                $targets[] = $write->valueVar;
                if (null !== $write->keyVar) {
                    $targets[] = $write->keyVar;
                }
            } elseif ($write instanceof FuncCall && $write->name instanceof Name && 'clone' === $write->name->toLowerString()) {
                $cloned = self::getClonedProperties($write);
                $dynamic = $dynamic || null === $cloned;
                $writes += array_fill_keys($cloned ?? [], true);
            } elseif ($write instanceof FuncCall || $write instanceof StaticCall || $write instanceof MethodCall || $write instanceof NullsafeMethodCall || $write instanceof New_) {
                $references = [...$references, ...self::getPropertyArguments($write)];
            }
        }
        while ([] !== $targets) {
            $target = array_pop($targets);
            if ($target instanceof List_ || $target instanceof Array_) {
                foreach ($target->items as $item) {
                    if (null !== $item) {
                        $targets[] = $item->value;
                    }
                }
            } elseif ($target instanceof PropertyFetch) {
                if ($target->name instanceof Identifier) {
                    $writes[$target->name->name] = true;
                } elseif ($target->name instanceof String_) {
                    $writes[$target->name->value] = true;
                } else {
                    $dynamic = true;
                }
            }
        }

        $storage = $event->getStorage();
        $storage->custom_metadata[self::class] = $assignments;
        $storage->custom_metadata[self::class.'::writes'] = $writes;
        $storage->custom_metadata[self::class.'::references'] = $references;
        $storage->custom_metadata[self::class.'::dynamic_writes'] = $dynamic;
    }

    /**
     * Returns the properties that the call is given as arguments, which a by-reference parameter can write: the
     * callee (the candidate names of a function, or a class and a method, `self`, `static` and `parent` resolved
     * later), and the position, the name and the property of each argument. The callees whose class is only known at
     * run time are left out.
     *
     * @return list<array{0: 'function'|'method', 1: list<string>, 2: string, 3: int, 4: string, 5: string}>
     */
    private static function getPropertyArguments(FuncCall|StaticCall|MethodCall|NullsafeMethodCall|New_ $call): array
    {
        if ($call->isFirstClassCallable()) {
            return [];
        }
        $resolve = static function (Name $name): string {
            $resolved = $name->getAttribute('resolvedName');

            return \is_string($resolved) ? $resolved : $name->toString();
        };
        if ($call instanceof FuncCall) {
            if (!$call->name instanceof Name) {
                return [];
            }
            // an unqualified name of a namespace falls back to the global function
            $resolved = $call->name->getAttribute('resolvedName');
            $namespaced = $call->name->getAttribute('namespacedName');
            $callee = ['function', \is_string($resolved) ? [$resolved] : array_values(array_filter([\is_string($namespaced) ? $namespaced : null, $call->name->toString()])), ''];
        } elseif ($call instanceof New_) {
            if (!$call->class instanceof Name) {
                return [];
            }
            $callee = ['method', [$resolve($call->class)], '__construct'];
        } else {
            $method = $call->name;
            if (!$method instanceof Identifier) {
                return [];
            }
            if ($call instanceof StaticCall) {
                if (!$call->class instanceof Name) {
                    return [];
                }
                $callee = ['method', [$call->class->isSpecialClassName() ? $call->class->toLowerString() : $resolve($call->class)], $method->name];
            } elseif ($call->var instanceof Variable && 'this' === $call->var->name) {
                $callee = ['method', ['self'], $method->name];
            } else {
                return [];
            }
        }

        $arguments = [];
        foreach ($call->args as $position => $argument) {
            if (!$argument instanceof Arg || $argument->unpack || !$argument->value instanceof PropertyFetch) {
                continue;
            }
            $property = $argument->value->name;
            $property = $property instanceof Identifier ? $property->name : ($property instanceof String_ ? $property->value : null);
            if (null !== $property) {
                $arguments[] = [...$callee, $position, $argument->name->name ?? '', $property];
            }
        }

        return $arguments;
    }

    /**
     * Whether the parameter the call of the class $class gives the argument to (see getPropertyArguments()) takes it by
     * reference. A callee that can't be found doesn't.
     *
     * @param array<array-key, mixed> $reference
     */
    private static function isPassedByReference(Codebase $codebase, ClassLikeStorage $class, ClassLikeStorage $scope, array $reference): bool
    {
        [$kind, $callees, $method, $position, $name] = $reference + [null, [], '', 0, ''];
        if (!\is_array($callees) || !\is_string($method) || !\is_int($position) || !\is_string($name)) {
            return false;
        }
        $parameters = null;
        foreach ($callees as $callee) {
            if (!\is_string($callee)) {
                continue;
            }
            if ('function' === $kind) {
                $parameters = self::getFunctionParameters($codebase, strtolower(ltrim($callee, '\\')), $scope->location?->file_path);
            } else {
                // a call of a method of a trait on self or static calls the method of the class using it
                $calledClass = match ($callee) {
                    'self', 'static' => $class->name,
                    'parent' => $class->parent_class,
                    default => $callee,
                };
                $methodId = null === $calledClass || '' === $method || !$codebase->classlike_storage_provider->has($calledClass)
                    ? null
                    : $codebase->methods->getDeclaringMethodId(new MethodIdentifier($codebase->classlike_storage_provider->get($calledClass)->name, strtolower($method)));
                $parameters = null === $methodId ? null : $codebase->methods->getStorage($methodId)->params;
            }
            if (null !== $parameters) {
                break;
            }
        }
        foreach ($parameters ?? [] as $offset => $parameter) {
            if ('' !== $name ? $parameter->name === $name : ($offset === $position || ($parameter->is_variadic && $position > $offset))) {
                return $parameter->by_ref;
            }
        }

        return false;
    }

    /**
     * @param lowercase-string $function
     *
     * @return list<FunctionLikeParameter>|null
     */
    private static function getFunctionParameters(Codebase $codebase, string $function, ?string $file): ?array
    {
        if ('' === $function) {
            return null;
        }
        try {
            return null === $file ? null : $codebase->functions->getStorage(null, $function, $file, $file)->params;
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
        }
        $callables = InternalCallMapHandler::getCallablesFromCallMap($function);
        if (null === $callables) {
            return null;
        }
        // a variant of the builtin taking an argument by reference can write it
        foreach ($callables as $callable) {
            foreach ($callable->params ?? [] as $parameter) {
                if ($parameter->by_ref) {
                    return $callable->params;
                }
            }
        }

        return $callables[0]->params ?? null;
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        foreach ($codebase->classlike_storage_provider->getAll() as $class) {
            if (!$class->user_defined) {
                continue;
            }
            foreach ($class->methods as $methodName => $method) {
                $suppressedIssues = $method->suppressed_issues + $class->suppressed_issues;
                foreach ($method->params as $parameter) {
                    unset($parameter->custom_metadata[self::class.'::reference']);
                    $type = self::resolve($codebase, $parameter, $class->name.'::'.$methodName, $suppressedIssues);
                    if (null === $type) {
                        continue;
                    }
                    self::checkType($codebase, $parameter, $type, $suppressedIssues);
                    if (!self::isUnspecifiedCallable($parameter->type) && true !== ($parameter->custom_metadata[self::class] ?? false)) {
                        continue;
                    }
                    if ($parameter->type?->isNullable()) {
                        $type = Type::combineUnionTypes($type, Type::getNull());
                    }
                    $parameter->type = $type;
                    $parameter->custom_metadata[self::class] = true;
                    if ('__construct' !== $methodName) {
                        continue;
                    }
                    foreach (self::getCopies($codebase, $class, $parameter) as $property) {
                        if (self::isUnspecifiedCallable($property->type) || true === ($property->custom_metadata[self::class] ?? false)) {
                            $property->type = $property->type?->isNullable() ? Type::combineUnionTypes($type, Type::getNull()) : $type;
                            $property->custom_metadata[self::class] = true;
                        }
                    }
                }
            }
        }
    }

    #[\Override]
    public static function afterAnalyzeFile(AfterFileAnalysisEvent $event): void
    {
        $codebase = $event->getCodebase();
        $source = $event->getStatementsSource();
        foreach ($event->getFileStorage()->classlikes_in_file as $name) {
            $class = $codebase->classlike_storage_provider->get($name);
            foreach ($class->methods as $methodName => $method) {
                $caller = strtolower($class->name.'::'.$methodName);
                foreach ($method->params as $parameter) {
                    $reference = $parameter->custom_metadata[self::class.'::reference'] ?? null;
                    if (!\is_string($reference)) {
                        continue;
                    }
                    if (str_contains($reference, '::')) {
                        $codebase->methodExists($reference, $caller, $parameter->location, $source);
                    } else {
                        $context = new Context($class->name);
                        $context->calling_method_id = $caller;
                        $codebase->addReferenceToFunctionLike(strtolower($reference), $parameter->location, $context, true, $source->getFilePath());
                    }
                }
            }
        }
    }

    /**
     * Returns the properties that a PHP 8.5 `clone($object, ['property' => $value])` call writes.
     *
     * @return list<string>|null null when the call can write any property
     */
    private static function getClonedProperties(FuncCall $call): ?array
    {
        $properties = null;
        foreach ($call->args as $i => $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                return null;
            }
            if ('withProperties' === $argument->name?->name || (null === $argument->name && 1 === $i)) {
                $properties = $argument->value;
            }
        }
        if (null === $properties) {
            return [];
        }
        if (!$properties instanceof Array_) {
            return null;
        }
        $names = [];
        foreach ($properties->items as $item) {
            if (!$item->key instanceof String_) {
                return null;
            }
            if (!self::isNull($item->value)) {
                $names[] = $item->key->value;
            }
        }

        return $names;
    }

    private static function isNull(Expr $expr): bool
    {
        return $expr instanceof ConstFetch && 'null' === $expr->name->toLowerString();
    }

    /**
     * Returns the properties of the class that the constructor parameter is the only value written to.
     *
     * @return list<PropertyStorage>
     */
    private static function getCopies(Codebase $codebase, ClassLikeStorage $class, FunctionLikeParameter $parameter): array
    {
        $assignments = $class->custom_metadata[self::class] ?? null;
        if (!\is_array($assignments) || !isset($class->custom_metadata[self::class.'::writes'])) {
            return [];
        }
        $writes = [];
        $classes = [$class];
        $visited = [];
        while ([] !== $classes) {
            $storage = array_pop($classes);
            if (isset($visited[$storage->name])) {
                continue;
            }
            $visited[$storage->name] = true;
            if (true === ($storage->custom_metadata[self::class.'::dynamic_writes'] ?? false)) {
                return [];
            }
            $traitWrites = $storage->custom_metadata[self::class.'::writes'] ?? [];
            $writes += \is_array($traitWrites) ? $traitWrites : [];
            $references = $storage->custom_metadata[self::class.'::references'] ?? [];
            foreach (\is_array($references) ? $references : [] as $reference) {
                $property = \is_array($reference) ? $reference[5] ?? null : null;
                if (\is_array($reference) && \is_string($property) && self::isPassedByReference($codebase, $class, $storage, $reference)) {
                    $writes[$property] = true;
                }
            }
            foreach ($storage->used_traits as $trait) {
                if ($codebase->classlike_storage_provider->has($trait)) {
                    $classes[] = $codebase->classlike_storage_provider->get($trait);
                }
            }
        }

        $copies = [];
        foreach ($class->properties as $name => $property) {
            $variables = $assignments[$name] ?? [];
            if (!\is_array($variables) || isset($writes[$name])) {
                continue;
            }
            if ($parameter->promoted_property && $parameter->name === $name) {
                $variables[] = $parameter->name;
            }
            if ([$parameter->name] === array_unique($variables) && self::isOnlyWrittenByItsClass($class, $property)) {
                $copies[] = $property;
            }
        }

        return $copies;
    }

    private static function isOnlyWrittenByItsClass(ClassLikeStorage $class, PropertyStorage $property): bool
    {
        // a hook can read or write another value than the parameter
        if (null !== $property->hook_get || null !== $property->hook_set) {
            return false;
        }
        if (ClassLikeAnalyzer::VISIBILITY_PRIVATE === $property->set_visibility) {
            return true;
        }

        // subclasses can write the properties that are not private, and any code the public ones that are not readonly
        return $class->final && ($property->readonly || ClassLikeAnalyzer::VISIBILITY_PUBLIC !== $property->set_visibility);
    }

    /** @param array<int, string> $suppressedIssues */
    private static function resolve(Codebase $codebase, FunctionLikeParameter $parameter, string $caller, array $suppressedIssues): ?Union
    {
        foreach ($parameter->attributes as $attribute) {
            $name = $attribute->fq_class_name;
            if ('Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure' === $name) {
                $service = self::serviceClass($codebase, self::getString($codebase, $attribute, 'service', 0));

                return null !== $service && $codebase->classlike_storage_provider->has($service) ? new Union([new TClosure([], new Union([new TNamedObject($service)]))]) : null;
            }
            if ('Symfony\Component\DependencyInjection\Attribute\AutowireMethodOf' === $name) {
                $service = self::serviceClass($codebase, self::getString($codebase, $attribute, 'service', 0));

                return null !== $service ? self::methodType($codebase, $service, $parameter->name, $caller, $attribute, false, $parameter, $suppressedIssues) : null;
            }
            if ('Symfony\Component\DependencyInjection\Attribute\AutowireCallable' !== $name) {
                continue;
            }
            $service = self::serviceClass($codebase, self::getString($codebase, $attribute, 'service', 1));
            if (null !== $service) {
                return self::methodType($codebase, $service, self::getString($codebase, $attribute, 'method', 2) ?? '__invoke', $caller, $attribute, false, $parameter, $suppressedIssues);
            }
            $callable = self::getArgumentType($codebase, $attribute, 'callable', 0);
            if ($callable instanceof TLiteralString) {
                if (str_contains($callable->value, '::')) {
                    [$service, $method] = explode('::', $callable->value, 2) + [1 => ''];

                    return self::methodType($codebase, $service, $method, $caller, $attribute, true, $parameter, $suppressedIssues);
                }
                $function = strtolower(ltrim($callable->value, '\\'));
                if ('' === $function) {
                    return null;
                }
                foreach ($codebase->file_storage_provider->getAll() as $file) {
                    if (isset($file->functions[$function])) {
                        $parameter->custom_metadata[self::class.'::reference'] = $function;
                        $type = self::closureType($file->functions[$function], $function);
                        if (null !== $type) {
                            return $type;
                        }
                    }
                }
                $callables = InternalCallMapHandler::getCallablesFromCallMap($function);
                if (null !== $callables) {
                    $closures = [];
                    foreach ($callables as $callable) {
                        $closures[] = new TClosure($callable->params, $callable->return_type, $callable->purity, callable_id: $function);
                    }
                    $parameter->custom_metadata[self::class.'::reference'] = $function;

                    return new Union($closures);
                }
                if (function_exists(ltrim($callable->value, '\\'))) {
                    $parameter->custom_metadata[self::class.'::reference'] = $function;

                    return self::closureType($codebase->functions->getStorage(null, $function), $function);
                }
            }
            if ($callable instanceof TKeyedArray) {
                $classType = $callable->properties[0] ?? null;
                $methodType = $callable->properties[1] ?? null;
                if ($classType?->isSingleStringLiteral() && $methodType?->isSingleStringLiteral()) {
                    return self::methodType($codebase, $classType->getSingleStringLiteral()->value, $methodType->getSingleStringLiteral()->value, $caller, $attribute, true, $parameter, $suppressedIssues);
                }
            }
        }

        return null;
    }

    /** @param array<int, string> $suppressedIssues */
    private static function methodType(Codebase $codebase, string $class, string $method, string $caller, AttributeStorage $attribute, bool $static, FunctionLikeParameter $parameter, array $suppressedIssues): ?Union
    {
        if (!$codebase->classlike_storage_provider->has($class)) {
            return null;
        }
        $id = new MethodIdentifier($class, strtolower($method));
        if (!$codebase->methodExists($id, $caller, $attribute->location)) {
            if ($codebase->methodExists(new MethodIdentifier($class, $static ? '__callstatic' : '__call'))) {
                return null;
            }
            IssueBuffer::maybeAdd(new InvalidAutowireCallable(sprintf('Method %s::%s() does not exist.', $class, $method), $attribute->location), $suppressedIssues);

            return null;
        }
        $storage = $codebase->methods->getStorage($codebase->methods->getDeclaringMethodId($id) ?? $id);
        // A trait method takes the visibility of its `use` adaptation (as in Psalm's MethodVisibilityAnalyzer), and the
        // class using the trait as `self`
        $visibility = $storage->visibility;
        $appearingClass = null;
        $appearingId = $codebase->methods->getAppearingMethodId($id);
        if (null !== $appearingId) {
            $appearingClass = $codebase->classlike_storage_provider->get($appearingId->fq_class_name);
            $visibility = $appearingClass->trait_visibility_map[$appearingId->method_name] ?? $visibility;
        }
        if (1 !== $visibility) {
            IssueBuffer::maybeAdd(new InvalidAutowireCallable(sprintf('Method %s::%s() is not public.', $class, $method), $attribute->location), $suppressedIssues);

            return null;
        }
        if ($static && !$storage->is_static) {
            IssueBuffer::maybeAdd(new InvalidAutowireCallable(sprintf('Method %s::%s() is not static.', $class, $method), $attribute->location), $suppressedIssues);

            return null;
        }
        $parameter->custom_metadata[self::class.'::reference'] = strtolower((string) $id);
        $declaringClass = $storage->defining_fqcln ?? $class;
        $selfClass = null !== $appearingClass && $codebase->classlike_storage_provider->get($declaringClass)->is_trait ? $appearingClass->name : $declaringClass;
        if (null !== $storage->template_types || null !== $codebase->classlike_storage_provider->get($declaringClass)->template_types) {
            return null;
        }

        // The closure is bound to an instance of $class, or of a subtype when $class is not final: then only a top-level
        // `static` return type is known (it widens to $class), and any other `static` leaves the signature as declared
        $final = $codebase->classlike_storage_provider->get($class)->final;
        $params = [];
        foreach ($storage->params as $parameter) {
            $parameter = clone $parameter;
            if (null !== $parameter->type) {
                if (!$final && self::mentionsStatic($codebase, $parameter->type, $selfClass, $class, false)) {
                    return null;
                }
                $parameter->type = TypeExpander::expandUnion($codebase, $parameter->type, $selfClass, $class, null, final: true);
            }
            $params[] = $parameter;
        }
        $returnType = null;
        if (null !== $storage->return_type) {
            if (!$final && self::mentionsStatic($codebase, $storage->return_type, $selfClass, $class, true)) {
                return null;
            }
            $returnType = TypeExpander::expandUnion($codebase, $storage->return_type, $selfClass, $class, null, final: true);
        }

        return new Union([new TClosure($params, $returnType, $storage->capabilities, callable_id: strtolower((string) $id))]);
    }

    private static function mentionsStatic(Codebase $codebase, Union $type, string $selfClass, string $class, bool $exceptTopLevel): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            // only a plain `static` (not generic, not an intersection) is exempted
            if ($exceptTopLevel && TNamedObject::class === $atomic::class && [] === $atomic->extra_types && ($atomic->is_static || \in_array(strtolower($atomic->value), ['static', '$this'], true))) {
                continue;
            }
            $single = new Union([$atomic]);
            // the expansions only differ when the type mentions `static` or `$this`, at any depth
            if (TypeExpander::expandUnion($codebase, $single, $selfClass, $class, null, final: true)->getId() !== TypeExpander::expandUnion($codebase, $single, $selfClass, $class, null)->getId()) {
                return true;
            }
        }

        return false;
    }

    private static function getString(Codebase $codebase, AttributeStorage $attribute, string $name, int $position): ?string
    {
        $type = self::getArgumentType($codebase, $attribute, $name, $position);

        return $type instanceof TLiteralString ? $type->value : null;
    }

    private static function getArgumentType(Codebase $codebase, AttributeStorage $attribute, string $name, int $position): ?Type\Atomic
    {
        foreach ($attribute->args as $i => $argument) {
            if ($name !== $argument->name && (null !== $argument->name || $i !== $position)) {
                continue;
            }
            $type = $argument->type;
            $atomic = $type instanceof Union ? ($type->isSingle() ? $type->getSingleAtomic() : null) : ConstantTypeResolver::resolve($codebase->classlikes, $type);

            return $atomic;
        }

        return null;
    }

    private static function serviceClass(Codebase $codebase, ?string $service): ?string
    {
        if (null === $service) {
            return null;
        }
        if (null !== self::$containerMeta) {
            try {
                return self::$containerMeta->get($service)->getClass();
            } catch (ServiceNotFoundException) {
            }
        }

        return $codebase->classlike_storage_provider->has($service) ? $service : null;
    }

    /** @psalm-capabilities read-props */
    private static function closureType(FunctionLikeStorage $storage, string $id): ?Union
    {
        if (null !== $storage->template_types || '' === $id) {
            return null;
        }
        $params = array_map(static fn (FunctionLikeParameter $parameter): FunctionLikeParameter => clone $parameter, $storage->params);

        return new Union([new TClosure($params, $storage->return_type, $storage->capabilities, callable_id: strtolower($id))]);
    }

    /** @param array<int, string> $suppressedIssues */
    private static function checkType(Codebase $codebase, FunctionLikeParameter $parameter, Union $type, array $suppressedIssues): void
    {
        if (null === $parameter->type) {
            return;
        }
        foreach ($parameter->type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject || $atomic instanceof TClosure || !$codebase->classlike_storage_provider->has($atomic->value)) {
                continue;
            }
            $class = $codebase->classlike_storage_provider->get($atomic->value);
            $methods = $class->declaring_method_ids;
            if (!$class->is_interface || 1 !== \count($methods)) {
                $message = sprintf('Adapter interface %s must have exactly one method.', $class->name);
            } else {
                $method = reset($methods);
                $storage = $codebase->methods->getStorage($method);
                $contract = new Union([new TCallable($storage->params, $storage->return_type)]);
                if ($codebase->isTypeContainedByType($type, $contract)) {
                    return;
                }
                $message = sprintf('Callable signature is incompatible with %s::%s().', $class->name, $storage->cased_name ?? $method->method_name);
            }
            IssueBuffer::maybeAdd(new InvalidAutowireCallable($message, $parameter->attributes[0]->location), $suppressedIssues);

            return;
        }
    }

    /** @psalm-pure */
    private static function isUnspecifiedCallable(?Union $type): bool
    {
        if (null === $type) {
            return false;
        }
        $found = false;
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof Type\Atomic\TNull) {
                continue;
            }
            if (($atomic instanceof TClosure || $atomic instanceof TCallable) && null === $atomic->params && null === $atomic->return_type) {
                $found = true;
                continue;
            }
            if ($atomic instanceof TNamedObject && !$atomic instanceof TClosure && 'Closure' === $atomic->value) {
                $found = true;
                continue;
            }

            return false;
        }

        return $found;
    }
}
