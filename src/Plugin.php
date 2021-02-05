<?php

namespace Psalm\SymfonyPsalmPlugin;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Psalm\Exception\ConfigException;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\SymfonyPsalmPlugin\Handler\AnnotationHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ConsoleHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ContainerDependencyHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ContainerHandler;
use Psalm\SymfonyPsalmPlugin\Handler\DoctrineRepositoryHandler;
use Psalm\SymfonyPsalmPlugin\Handler\HeaderBagHandler;
use Psalm\SymfonyPsalmPlugin\Handler\RequiredSetterHandler;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\SymfonyPsalmPlugin\Twig\AnalyzedTemplatesTainter;
use Psalm\SymfonyPsalmPlugin\Twig\CachedTemplatesMapping;
use Psalm\SymfonyPsalmPlugin\Twig\CachedTemplatesTainter;
use SimpleXMLElement;
use Symfony\Component\HttpKernel\Kernel;
use function array_merge;

/**
 * @psalm-suppress UnusedClass
 */
class Plugin implements PluginEntryPointInterface
{
    /**
     * @return string[]
     */
    protected function getFormStubs(): array
    {
        $forms = glob(__DIR__ . '/Stubs/common/forms/*.stubphp') ?: [];
        $events = glob(__DIR__ . '/Stubs/common/forms/Event/*.stubphp') ?: [];

        return array_merge($forms, $events);
    }

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
    public function __invoke(RegistrationInterface $api, SimpleXMLElement $config = null): void
    {
        require_once __DIR__.'/Handler/HeaderBagHandler.php';
        require_once __DIR__.'/Handler/ContainerHandler.php';
        require_once __DIR__.'/Handler/ConsoleHandler.php';
        require_once __DIR__.'/Handler/ContainerDependencyHandler.php';
        require_once __DIR__.'/Handler/RequiredSetterHandler.php';

        $api->registerHooksFromClass(HeaderBagHandler::class);
        $api->registerHooksFromClass(ConsoleHandler::class);
        $api->registerHooksFromClass(ContainerDependencyHandler::class);
        $api->registerHooksFromClass(RequiredSetterHandler::class);

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
            $this->getCommonStubs(),
            $this->getStubsForMajorVersion(Kernel::MAJOR_VERSION),
            $this->getFormStubs()
        );

        foreach ($stubs as $stubFilePath) {
            $api->addStubFile($stubFilePath);
        }

        if (isset($config->twigCachePath)) {
            $twig_cache_path = getcwd().DIRECTORY_SEPARATOR.ltrim((string) $config->twigCachePath, DIRECTORY_SEPARATOR);
            if (!is_dir($twig_cache_path) || !is_readable($twig_cache_path)) {
                throw new ConfigException(sprintf('The twig directory %s is missing or not readable.', $twig_cache_path));
            }

            require_once __DIR__.'/Twig/CachedTemplatesTainter.php';
            $api->registerHooksFromClass(CachedTemplatesTainter::class);

            require_once __DIR__.'/Twig/CachedTemplatesMapping.php';
            $api->registerHooksFromClass(CachedTemplatesMapping::class);
            CachedTemplatesMapping::setCachePath($twig_cache_path);
        }

        require_once __DIR__.'/Twig/AnalyzedTemplatesTainter.php';
        $api->registerHooksFromClass(AnalyzedTemplatesTainter::class);
    }
}
