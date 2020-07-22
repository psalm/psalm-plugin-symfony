<?php

namespace Psalm\SymfonyPsalmPlugin;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\SymfonyPsalmPlugin\Handler\AnnotationHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ConsoleHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ContainerDependencyHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ContainerHandler;
use Psalm\SymfonyPsalmPlugin\Handler\DoctrineRepositoryHandler;
use Psalm\SymfonyPsalmPlugin\Handler\HeaderBagHandler;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\SymfonyPsalmPlugin\Taint\RequestTaint;
use Psalm\SymfonyPsalmPlugin\Taint\TwigTaint;
use SimpleXMLElement;
use Symfony\Component\HttpKernel\Kernel;

/**
 * @psalm-suppress UnusedClass
 */
class Plugin implements PluginEntryPointInterface
{
    /**
     * @return string[]
     */
    protected function getCommonStubs(): array
    {
        return glob(__DIR__.'/Stubs/common/*.stubphp') ?: [];
    }

    /**
     * @param int $majorVersion symfony major version
     *
     * @return string[]
     */
    protected function getStubsForMajorVersion(int $majorVersion): array
    {
        $version = (string) $majorVersion;

        return glob(__DIR__.'/Stubs/'.$version.'/*.stubphp') ?: [];
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(RegistrationInterface $api, SimpleXMLElement $config = null)
    {
        require_once __DIR__.'/Handler/HeaderBagHandler.php';
        require_once __DIR__.'/Handler/ContainerHandler.php';
        require_once __DIR__.'/Handler/ConsoleHandler.php';
        require_once __DIR__.'/Handler/ContainerDependencyHandler.php';

        $api->registerHooksFromClass(HeaderBagHandler::class);
        $api->registerHooksFromClass(ConsoleHandler::class);
        $api->registerHooksFromClass(ContainerDependencyHandler::class);

        if (class_exists(AnnotationRegistry::class)) {
            require_once __DIR__.'/Handler/DoctrineRepositoryHandler.php';
            /** @psalm-suppress DeprecatedMethod */
            AnnotationRegistry::registerLoader('class_exists');
            $api->registerHooksFromClass(DoctrineRepositoryHandler::class);

            require_once __DIR__.'/Handler/AnnotationHandler.php';
            $api->registerHooksFromClass(AnnotationHandler::class);
        }

        if (isset($config->containerXml)) {
            ContainerHandler::init(new ContainerMeta((array) $config->containerXml));
        }

        $api->registerHooksFromClass(ContainerHandler::class);

        $stubs = array_merge(
            $this->getCommonStubs(), $this->getStubsForMajorVersion(Kernel::MAJOR_VERSION)
        );

        foreach ($stubs as $stubFilePath) {
            $api->addStubFile($stubFilePath);
        }

        require_once __DIR__.'/Taint/RequestTaint.php';
        $api->registerHooksFromClass(RequestTaint::class);

        require_once __DIR__.'/Taint/TwigTaint.php';
        $api->registerHooksFromClass(TwigTaint::class);
    }
}
