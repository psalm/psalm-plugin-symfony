<?php

namespace Seferov\SymfonyPsalmPlugin;

use Psalm\Exception\ConfigException;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Seferov\SymfonyPsalmPlugin\Handler\ClassHandler;
use Seferov\SymfonyPsalmPlugin\Handler\ContainerHandler;
use Seferov\SymfonyPsalmPlugin\Handler\ContainerXmlHandler;
use SimpleXMLElement;

class Plugin implements PluginEntryPointInterface
{
    /**
     * {@inheritdoc}
     */
    public function __invoke(RegistrationInterface $api, SimpleXMLElement $config = null)
    {
        require_once __DIR__.'/Handler/ClassHandler.php';
        require_once __DIR__.'/Handler/ContainerHandler.php';
        require_once __DIR__.'/Handler/ContainerXmlHandler.php';

        $api->registerHooksFromClass(ClassHandler::class);

        if (isset($config->containerXml)) {
            $containerXmlPath = realpath((string) $config->containerXml);
            if (!$containerXmlPath) {
                throw new ConfigException(sprintf('Container XML file (%s) does not exits', $containerXmlPath));
            }

            $api->registerHooksFromClass(ContainerXmlHandler::class);
        } else {
            $api->registerHooksFromClass(ContainerHandler::class);
        }
    }
}
