<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Symfony;

use Psalm\Exception\ConfigException;
use Psalm\SymfonyPsalmPlugin\Symfony\Loader\XmlFileLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;

final class ContainerMeta
{
    /**
     * @var array<string>
     */
    private array $classNames = [];

    /**
     * @var array<string, string>
     */
    private array $classLocators = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $serviceLocators = [];

    private ContainerBuilder $container;

    public function __construct(array $containerXmlPaths)
    {
        $this->init($containerXmlPaths);
    }

    /**
     * @throws ServiceNotFoundException
     */
    public function get(string $id, ?string $contextClass = null): Definition
    {
        if (null !== $contextClass && isset($this->classLocators[$contextClass]) && isset($this->serviceLocators[$this->classLocators[$contextClass]]) && isset($this->serviceLocators[$this->classLocators[$contextClass]][$id])) {
            $id = $this->serviceLocators[$this->classLocators[$contextClass]][$id];

            try {
                $definition = $this->getDefinition($id);
            } catch (ServiceNotFoundException $e) {
                if (!class_exists($id)) {
                    throw $e;
                }

                $definition = new Definition($id);
            }

            $definition->setPublic(true);
        } else {
            $definition = $this->getDefinition($id);
        }

        return $definition;
    }

    public function getParameter(string $key): mixed
    {
        return $this->container->getParameter($key);
    }

    /**
     * @throw ParameterNotFoundException
     *
     * @return ?array<string>
     */
    public function guessParameterType(string $key): ?array
    {
        $parameter = $this->getParameter($key);

        if (is_string($parameter) && str_starts_with($parameter, '%env(')) {
            return $this->envParameterType($parameter);
        }

        return match (gettype($parameter)) {
            'string' => ['string'],
            'boolean' => ['bool'],
            'integer' => ['int'],
            'double' => ['float'],
            'array' => ['array'],
            default => null,
        };
    }

    /**
     * @return array<string>
     */
    public function getClassNames(): array
    {
        return $this->classNames;
    }

    private function init(array $containerXmlPaths): void
    {
        $this->container = new ContainerBuilder();

        if (Kernel::MAJOR_VERSION >= 8) {
            $xmlLoaderClass = XmlFileLoader::class;
        } else {
            $xmlLoaderClass = 'Symfony\Component\DependencyInjection\Loader\XmlFileLoader';
        }

        if (!class_exists($xmlLoaderClass)) {
            throw new \RuntimeException("The loader class '$xmlLoaderClass' does not exist.");
        }

        $xml = new $xmlLoaderClass($this->container, new FileLocator());

        $containerXmlPath = null;
        foreach ($containerXmlPaths as $filePath) {
            $containerXmlPath = realpath((string) $filePath);
            if (false !== $containerXmlPath) {
                break;
            }
        }

        if (!is_string($containerXmlPath)) {
            throw new ConfigException('Container xml file(s) not found!');
        }

        $xml->load($containerXmlPath);

        foreach ($this->container->getDefinitions() as $definition) {
            if ($definition->hasTag('container.service_locator')) {
                continue;
            }

            if ($definition->hasTag('container.excluded')) {
                continue;
            }

            $definitionFactory = $definition->getFactory();
            if ($definition->hasTag('container.service_locator_context') && is_array($definitionFactory)) {
                /** @var Reference $reference */
                $reference = $definitionFactory[0];
                $id = $definition->getTag('container.service_locator_context')[0]['id'];
                try {
                    $this->classLocators[$this->container->getDefinition($id)->getClass() ?? $id] = (string) $reference;
                } catch (ServiceNotFoundException) {
                    continue;
                }
            } elseif (null !== $className = $definition->getClass()) {
                $this->classNames[] = $className;
            }
        }

        foreach ($this->container->findTaggedServiceIds('container.service_locator') as $key => $_) {
            foreach ($this->container->getDefinition($key)->getArgument(0) as $id => $argument) {
                if ($argument instanceof Reference) {
                    $this->addServiceLocator($key, $id, $argument);
                } elseif ($argument instanceof ServiceClosureArgument) {
                    foreach ($argument->getValues() as $value) {
                        if ($value instanceof Reference) {
                            $this->addServiceLocator($key, $id, $value);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array-key $id
     */
    private function addServiceLocator(string $key, mixed $id, Reference $reference): void
    {
        $this->serviceLocators[$key][$id] = (string) $reference;

        try {
            $definition = $this->getDefinition((string) $reference);
            $className = $definition->getClass();
            if (null !== $className) {
                $this->classNames[] = $className;
            }
        } catch (ServiceNotFoundException) {
        }
    }

    /**
     * @throws ServiceNotFoundException
     */
    private function getDefinition(string $id): Definition
    {
        try {
            $definition = $this->container->getDefinition($id);
        } catch (ServiceNotFoundException $serviceNotFoundException) {
            try {
                $alias = $this->container->getAlias($id);
            } catch (InvalidArgumentException) {
                throw $serviceNotFoundException;
            }

            $definition = $this->container->getDefinition((string) $alias);
            $definition->setPublic($alias->isPublic());
        }

        return $definition;
    }

    private function envParameterType(string $envParameter): ?array
    {
        // extract bool from %env(bool:ENV_PARAM)%, string from %env(string:ENV_PARAM)%
        $type = preg_match('/^%env\((\w+):/', $envParameter, $matches) ? $matches[1] : null;

        $envVarTypes = EnvVarProcessor::getProvidedTypes();
        if (null === $type || !isset($envVarTypes[$type])) {
            return null;
        }

        return explode('|', $envVarTypes[$type]);
    }
}
