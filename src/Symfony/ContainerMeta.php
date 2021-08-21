<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Symfony;

use Psalm\Exception\ConfigException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class ContainerMeta
{
    /**
     * @var array<string>
     */
    private $classNames = [];

    /**
     * @var array<string, string>
     */
    private $classLocators = [];

    /**
     * @var array<string, array<string, string>>
     */
    private $serviceLocators = [];

    /**
     * @var ContainerBuilder
     */
    private $container;

    public function __construct(array $containerXmlPaths)
    {
        $this->init($containerXmlPaths);
    }

    /**
     * @throws ServiceNotFoundException
     */
    public function get(string $id, ?string $contextClass = null): Definition
    {
        if ($contextClass && isset($this->classLocators[$contextClass]) && isset($this->serviceLocators[$this->classLocators[$contextClass]]) && isset($this->serviceLocators[$this->classLocators[$contextClass]][$id])) {
            $id = $this->serviceLocators[$this->classLocators[$contextClass]][$id];

            try {
                $definition = $this->container->getDefinition($id);
            } catch (ServiceNotFoundException $e) {
                if (!class_exists($id)) {
                    throw $e;
                }

                $definition = new Definition($id);
                $definition->setPublic(true);
            }
        } else {
            $definition = $this->container->getDefinition($id);
        }

        return $definition;
    }

    /**
     * @return mixed|null
     */
    public function getParameter(string $key)
    {
        return $this->container->getParameter($key);
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
        $xml = new XmlFileLoader($this->container, new FileLocator());

        $containerXmlPath = null;
        foreach ($containerXmlPaths as $filePath) {
            $containerXmlPath = realpath((string) $filePath);
            if ($containerXmlPath) {
                break;
            }
        }

        if (!$containerXmlPath) {
            throw new ConfigException('Container xml file(s) not found at ');
        }

        $xml->load($containerXmlPath);

        foreach ($this->container->getDefinitions() as $definition) {
            $definitionFactory = $definition->getFactory();
            if ($definition->hasTag('container.service_locator_context') && is_array($definitionFactory)) {
                /** @var Reference $reference */
                $reference = $definitionFactory[0];
                $this->classLocators[$definition->getTag('container.service_locator_context')[0]['id']] = (string) $reference;
            } elseif ($definition->hasTag('container.service_locator')) {
                continue;
            } elseif ($className = $definition->getClass()) {
                $this->classNames[] = $className;
            }
        }

        foreach ($this->container->findTaggedServiceIds('container.service_locator') as $key => $a) {
            $definition = $this->container->getDefinition($key);
            foreach ($definition->getArgument(0) as $id => $reference) {
                /** @var Reference $reference */
                $this->serviceLocators[$key][$id] = (string) $reference;
                // maybe add class (string reference) for discovery to $this->classNames
            }
        }
    }
}
