<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Symfony;

use Psalm\Exception\ConfigException;
use Symfony\Component\HttpKernel\Kernel;

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

    public function __construct(array $containerXmlPaths)
    {
        $this->init($containerXmlPaths);
    }

    public function get(string $id): ?Service
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        return null;
    }

    public function add(Service $service): void
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

    private function init(array $containerXmlPaths): void
    {
        /** @var string $containerXmlPath */
        foreach ($containerXmlPaths as $containerXmlPath) {
            $xmlPath = realpath((string) $containerXmlPath);
            if (!$xmlPath || !file_exists($xmlPath)) {
                continue;
            }

            $xml = simplexml_load_file($xmlPath);
            if (!$xml->services instanceof \SimpleXMLElement) {
                throw new ConfigException($xmlPath.' is not a valid container xml file');
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

                if (3 < Kernel::MAJOR_VERSION) {
                    $service->setIsPublic('true' === (string) $serviceAttributes->public);
                } else {
                    $service->setIsPublic('false' !== (string) $serviceAttributes->public);
                }

                $this->add($service);
            }

            return;
        }

        throw new ConfigException('Container xml file(s) not found at ');
    }
}
