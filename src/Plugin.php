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
use Psalm\SymfonyPsalmPlugin\Handler\DoctrineQueryBuilderHandler;
use Psalm\SymfonyPsalmPlugin\Handler\DoctrineRepositoryHandler;
use Psalm\SymfonyPsalmPlugin\Handler\HeaderBagHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ParameterBagHandler;
use Psalm\SymfonyPsalmPlugin\Handler\RequiredSetterHandler;
use Psalm\SymfonyPsalmPlugin\Provider\FormGetErrorsReturnTypeProvider;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\SymfonyPsalmPlugin\Twig\AnalyzedTemplatesTainter;
use Psalm\SymfonyPsalmPlugin\Twig\CachedTemplatesMapping;
use Psalm\SymfonyPsalmPlugin\Twig\CachedTemplatesTainter;
use Psalm\SymfonyPsalmPlugin\Twig\TemplateFileAnalyzer;
use SimpleXMLElement;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpKernel\Kernel;

/**
 * @psalm-suppress UnusedClass
 */
class Plugin implements PluginEntryPointInterface
{
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
        require_once __DIR__.'/Handler/DoctrineQueryBuilderHandler.php';
        require_once __DIR__.'/Provider/FormGetErrorsReturnTypeProvider.php';

        $api->registerHooksFromClass(HeaderBagHandler::class);
        $api->registerHooksFromClass(ConsoleHandler::class);
        $api->registerHooksFromClass(ContainerDependencyHandler::class);
        $api->registerHooksFromClass(RequiredSetterHandler::class);

        if (class_exists(\Doctrine\ORM\QueryBuilder::class)) {
            $api->registerHooksFromClass(DoctrineQueryBuilderHandler::class);
        }

        if (class_exists(AnnotationRegistry::class)) {
            require_once __DIR__.'/Handler/DoctrineRepositoryHandler.php';
            /** @psalm-suppress DeprecatedMethod */
            AnnotationRegistry::registerLoader('class_exists');
            $api->registerHooksFromClass(DoctrineRepositoryHandler::class);

            require_once __DIR__.'/Handler/AnnotationHandler.php';
            $api->registerHooksFromClass(AnnotationHandler::class);
        }

        if (isset($config->containerXml)) {
            $containerMeta = new ContainerMeta((array) $config->containerXml);
            ContainerHandler::init($containerMeta);

            try {
                TemplateFileAnalyzer::initExtensions(array_filter(array_map(function (array $m) use ($containerMeta) {
                    if ('addExtension' !== $m[0]) {
                        return null;
                    }

                    try {
                        return $containerMeta->get($m[1][0])->getClass();
                    } catch (ServiceNotFoundException $e) {
                        return null;
                    }
                }, $containerMeta->get('twig')->getMethodCalls())));
            } catch (ServiceNotFoundException $e) {
            }

            require_once __DIR__.'/Handler/ParameterBagHandler.php';
            ParameterBagHandler::init($containerMeta);
            $api->registerHooksFromClass(ParameterBagHandler::class);
        }

        $api->registerHooksFromClass(ContainerHandler::class);

        $this->addStubs($api, __DIR__.'/Stubs/common');
        $this->addStubs($api, __DIR__.'/Stubs/'.Kernel::MAJOR_VERSION);
        $this->addStubs($api, __DIR__.'/Stubs/php');

        if (isset($config->twigCachePath)) {
            $twig_cache_path = getcwd().DIRECTORY_SEPARATOR.ltrim((string) $config->twigCachePath, DIRECTORY_SEPARATOR);
            if (!is_dir($twig_cache_path) || !is_readable($twig_cache_path)) {
                throw new ConfigException(sprintf('The twig cache directory %s is missing or not readable.', $twig_cache_path));
            }

            require_once __DIR__.'/Twig/CachedTemplatesTainter.php';
            $api->registerHooksFromClass(CachedTemplatesTainter::class);

            require_once __DIR__.'/Twig/CachedTemplatesMapping.php';
            $api->registerHooksFromClass(CachedTemplatesMapping::class);
            CachedTemplatesMapping::setCachePath($twig_cache_path);
        }

        require_once __DIR__.'/Twig/AnalyzedTemplatesTainter.php';
        $api->registerHooksFromClass(AnalyzedTemplatesTainter::class);

        if (isset($config->twigRootPath)) {
            $twig_root_path = trim((string) $config->twigRootPath, DIRECTORY_SEPARATOR);
            $real_path = getcwd().DIRECTORY_SEPARATOR.$twig_root_path;
            if (!is_dir($real_path) || !is_readable($real_path)) {
                throw new ConfigException(sprintf('The twig templates root directory %s is missing or not readable.', $twig_root_path));
            }

            TemplateFileAnalyzer::setTemplateRootPath($twig_root_path);
        }

        $api->registerHooksFromClass(FormGetErrorsReturnTypeProvider::class);
    }

    private function addStubs(RegistrationInterface $api, string $path): void
    {
        if (!is_dir($path)) {
            // e.g. looking for stubs for version 3, but there aren't any at time of writing, so don't try and load them.
            return;
        }

        $a = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        foreach ($a as $file) {
            if (!$file->isDir()) {
                $api->addStubFile($file->getPathname());
            }
        }
    }
}
