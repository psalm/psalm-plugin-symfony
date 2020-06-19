<?php

namespace Psalm\SymfonyPsalmPlugin;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\SymfonyPsalmPlugin\Handler\ClassHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ConsoleHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ContainerHandler;
use Psalm\SymfonyPsalmPlugin\Handler\DoctrineRepositoryHandler;
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
        require_once __DIR__.'/Handler/ConsoleHandler.php';
        require_once __DIR__.'/Handler/DoctrineRepositoryHandler.php';

        $api->registerHooksFromClass(ClassHandler::class);
        $api->registerHooksFromClass(ConsoleHandler::class);

        if (class_exists(AnnotationRegistry::class)) {
            /** @psalm-suppress DeprecatedMethod */
            AnnotationRegistry::registerLoader('class_exists');
            $api->registerHooksFromClass(DoctrineRepositoryHandler::class);
        }

        if (isset($config->containerXml)) {
            ContainerHandler::init(new ContainerMeta((array) $config->containerXml));
        }

        $api->registerHooksFromClass(ContainerHandler::class);

        foreach (glob(__DIR__.'/Stubs/*.stubphp') as $stubFilePath) {
            $api->addStubFile($stubFilePath);
        }
    }
}
