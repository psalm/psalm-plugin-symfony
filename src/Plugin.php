<?php

namespace Psalm\SymfonyPsalmPlugin;

use Psalm\Exception\ConfigException;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\SymfonyPsalmPlugin\Handler\ClassHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ConsoleHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ContainerHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ContainerXmlHandler;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
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
        require_once __DIR__.'/Handler/ConsoleHandler.php';

        $api->registerHooksFromClass(ClassHandler::class);
        $api->registerHooksFromClass(ConsoleHandler::class);

        if (isset($config->containerXml)) {
            $containerXmlPath = realpath((string) $config->containerXml);
            if (!$containerXmlPath) {
                throw new ConfigException(sprintf('Container XML file (%s) does not exits', $containerXmlPath));
            }

            ContainerXmlHandler::init(new ContainerMeta($containerXmlPath));

            $api->registerHooksFromClass(ContainerXmlHandler::class);
        } else {
            $api->registerHooksFromClass(ContainerHandler::class);
        }
    }
}
