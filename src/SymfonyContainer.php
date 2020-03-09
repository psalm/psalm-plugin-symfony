<?php

declare(strict_types=1);

namespace Seferov\SymfonyPsalmPlugin;

use Psalm\Exception\ConfigException;
use Symfony\Component\DependencyInjection\Definition;

class SymfonyContainer
{
    /**
     * @psalm-var array<string, Definition>
     */
    private $services = [];

    public function __construct(string $containerXmlPath)
    {
        $xml = simplexml_load_file($containerXmlPath);
        if (!$xml->services instanceof \SimpleXMLElement) {
            throw new ConfigException('Not a valid container xml file');
        }

        /** @psalm-var \SimpleXMLElement $service */
        foreach ($xml->services->service as $service) {
            /** @psalm-var \SimpleXMLElement $serviceAttributes */
            $serviceAttributes = $service->attributes();
            $definition = new Definition((string) $serviceAttributes->class);
            if ((bool) $serviceAttributes->public) {
                $definition->setPublic(true);
                $definition->setPrivate(false);
            } else {
                $definition->setPublic(false);
                $definition->setPrivate(true);
            }

            $this->services[(string) $serviceAttributes->id] = $definition;
        }
    }

    public function get(string $id): ?Definition
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        return null;
    }
}
