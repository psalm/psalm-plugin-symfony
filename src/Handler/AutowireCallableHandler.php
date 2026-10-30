<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use Psalm\Codebase;
use Psalm\Context;
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
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\FunctionLikeStorage;
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
        $constructor = $node instanceof Class_ ? $node->getMethod('__construct') : null;
        if (null === $constructor) {
            return;
        }
        $assignments = [];
        foreach ($constructor->getStmts() ?? [] as $statement) {
            if (!$statement instanceof Expression || !$statement->expr instanceof Assign) {
                continue;
            }
            $assign = $statement->expr;
            if ($assign->var instanceof PropertyFetch && $assign->var->var instanceof Variable && 'this' === $assign->var->var->name && $assign->var->name instanceof Identifier && $assign->expr instanceof Variable && \is_string($assign->expr->name)) {
                $assignments[$assign->var->name->name] = $assign->expr->name;
            }
        }
        $event->getStorage()->custom_metadata[self::class] = $assignments;
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
                foreach ($method->params as $parameter) {
                    unset($parameter->custom_metadata[self::class.'::reference']);
                    $type = self::resolve($codebase, $parameter, $class->name.'::'.$methodName);
                    if (null === $type) {
                        continue;
                    }
                    self::checkType($codebase, $parameter, $type, array_merge($method->suppressed_issues, $class->suppressed_issues));
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
                    $properties = $class->custom_metadata[self::class] ?? [];
                    if (!\is_array($properties)) {
                        continue;
                    }
                    if ($parameter->promoted_property) {
                        $properties[$parameter->name] = $parameter->name;
                    }
                    foreach ($properties as $property => $name) {
                        if ($name === $parameter->name && isset($class->properties[$property]) && (self::isUnspecifiedCallable($class->properties[$property]->type) || true === ($class->properties[$property]->custom_metadata[self::class] ?? false))) {
                            $class->properties[$property]->type = $class->properties[$property]->type?->isNullable() ? Type::combineUnionTypes($type, Type::getNull()) : $type;
                            $class->properties[$property]->custom_metadata[self::class] = true;
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

    private static function resolve(Codebase $codebase, FunctionLikeParameter $parameter, string $caller): ?Union
    {
        foreach ($parameter->attributes as $attribute) {
            $name = $attribute->fq_class_name;
            if ('Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure' === $name) {
                $service = self::serviceClass($codebase, self::getString($codebase, $attribute, 'service', 0));

                return null !== $service && $codebase->classlike_storage_provider->has($service) ? new Union([new TClosure([], new Union([new TNamedObject($service)]))]) : null;
            }
            if ('Symfony\Component\DependencyInjection\Attribute\AutowireMethodOf' === $name) {
                $service = self::serviceClass($codebase, self::getString($codebase, $attribute, 'service', 0));

                return null !== $service ? self::methodType($codebase, $service, $parameter->name, $caller, $attribute, false, $parameter) : null;
            }
            if ('Symfony\Component\DependencyInjection\Attribute\AutowireCallable' !== $name) {
                continue;
            }
            $service = self::serviceClass($codebase, self::getString($codebase, $attribute, 'service', 1));
            if (null !== $service) {
                return self::methodType($codebase, $service, self::getString($codebase, $attribute, 'method', 2) ?? '__invoke', $caller, $attribute, false, $parameter);
            }
            $callable = self::getArgumentType($codebase, $attribute, 'callable', 0);
            if ($callable instanceof TLiteralString) {
                if (str_contains($callable->value, '::')) {
                    [$service, $method] = explode('::', $callable->value, 2) + [1 => ''];

                    return self::methodType($codebase, $service, $method, $caller, $attribute, true, $parameter);
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
                if (function_exists($function)) {
                    $parameter->custom_metadata[self::class.'::reference'] = $function;

                    return self::closureType($codebase->functions->getStorage(null, strtolower($function)), $function);
                }
            }
            if ($callable instanceof TKeyedArray) {
                $classType = $callable->properties[0] ?? null;
                $methodType = $callable->properties[1] ?? null;
                if ($classType?->isSingleStringLiteral() && $methodType?->isSingleStringLiteral()) {
                    return self::methodType($codebase, $classType->getSingleStringLiteral()->value, $methodType->getSingleStringLiteral()->value, $caller, $attribute, true, $parameter);
                }
            }
        }

        return null;
    }

    private static function methodType(Codebase $codebase, string $class, string $method, string $caller, AttributeStorage $attribute, bool $static, FunctionLikeParameter $parameter): ?Union
    {
        if (!$codebase->classlike_storage_provider->has($class)) {
            return null;
        }
        $id = new MethodIdentifier($class, strtolower($method));
        if (!$codebase->methodExists($id, $caller, $attribute->location)) {
            if ($codebase->methodExists(new MethodIdentifier($class, $static ? '__callstatic' : '__call'))) {
                return null;
            }
            IssueBuffer::maybeAdd(new InvalidAutowireCallable(sprintf('Method %s::%s() does not exist.', $class, $method), $attribute->location));

            return null;
        }
        $storage = $codebase->methods->getStorage($id);
        if (1 !== $storage->visibility) {
            IssueBuffer::maybeAdd(new InvalidAutowireCallable(sprintf('Method %s::%s() is not public.', $class, $method), $attribute->location));

            return null;
        }
        if ($static && !$storage->is_static) {
            IssueBuffer::maybeAdd(new InvalidAutowireCallable(sprintf('Method %s::%s() is not static.', $class, $method), $attribute->location));

            return null;
        }
        $parameter->custom_metadata[self::class.'::reference'] = strtolower((string) $id);
        $declaringClass = $storage->defining_fqcln ?? $class;
        if (null !== $storage->template_types || null !== $codebase->classlike_storage_provider->get($declaringClass)->template_types) {
            return null;
        }

        $params = [];
        foreach ($storage->params as $parameter) {
            $parameter = clone $parameter;
            if (null !== $parameter->type) {
                $parameter->type = TypeExpander::expandUnion($codebase, $parameter->type, $declaringClass, $class, null);
            }
            $params[] = $parameter;
        }
        $returnType = null === $storage->return_type ? null : TypeExpander::expandUnion($codebase, $storage->return_type, $declaringClass, $class, null);

        return new Union([new TClosure($params, $returnType, $storage->capabilities, callable_id: strtolower((string) $id))]);
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
