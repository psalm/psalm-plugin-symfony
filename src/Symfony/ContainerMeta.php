<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Symfony;

use Psalm\Exception\ConfigException;

class ContainerMeta
{
    /**
     * @psalm-var array<string, Service>
     */
    private $services = [];

    /**
     * @var array<string>
     */
    private $classNames = [];

    public function __construct(string $containerXmlPath)
    {
        if (!file_exists($containerXmlPath)) {
            throw new ConfigException('Container xml file not found at '.$containerXmlPath);
        }

        $xml = simplexml_load_file($containerXmlPath);
        if (!$xml->services instanceof \SimpleXMLElement) {
            throw new ConfigException('Not a valid container xml file');
        }

        /** @psalm-var \SimpleXMLElement $serviceXml */
        foreach ($xml->services->service as $serviceXml) {
            /** @psalm-var \SimpleXMLElement $serviceAttributes */
            $serviceAttributes = $serviceXml->attributes();

            $className = (string) $serviceAttributes->class;

            if ($className) {
                $this->classNames[] = $className;
            }

            $service = new Service((string) $serviceAttributes->id, $className);
            if (isset($serviceAttributes->alias)) {
                $service->setAlias((string) $serviceAttributes->alias);
            }
            $service->setIsPublic('false' !== (string) $serviceAttributes->public);

            $this->add($service);
        }
    }

    public function get(string $id): ?Service
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        return null;
    }

    private function add(Service $service): void
    {
        if (($alias = $service->getAlias()) && isset($this->services[$alias])) {
            $aliasedService = $this->services[$alias];
            $service->setClassName($aliasedService->getClassName());
        }

        $this->services[$service->getId()] = $service;
    }

    /**
     * @return array<string>
     */
    public function getClassNames(): array
    {
        return $this->classNames;
    }
}
