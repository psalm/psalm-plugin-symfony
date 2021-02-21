<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Test;

use Codeception\Module as BaseModule;
use Codeception\TestInterface;
use InvalidArgumentException;
use Psalm\SymfonyPsalmPlugin\Twig\CachedTemplatesMapping;
use Twig\Cache\FilesystemCache;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\FilesystemLoader;

/**
 * @psalm-suppress UnusedClass
 * This class is to be used in codeception configuration - like in tests/acceptance/acceptance.suite.yml.
 */
class CodeceptionModule extends BaseModule
{
    /** @var array<string,string> */
    protected $config = [
        'default_dir' => 'tests/_run/',
    ];

    private const DEFAULT_TWIG_TEMPLATES_DIR = 'templates';

    /**
     * @var string
     */
    private $twigTemplateDir = self::DEFAULT_TWIG_TEMPLATES_DIR;

    /**
     * @var FilesystemCache|null
     */
    private $twigCache;

    /**
     * @var string|null
     */
    private $lastCachePath;

    public function _after(TestInterface $test): void
    {
        $this->twigCache = $this->lastCachePath = null;
        $this->twigTemplateDir = self::DEFAULT_TWIG_TEMPLATES_DIR;
    }

    /**
     * @Given the template root directory is :rootDir
     */
    public function setTheTemplateRootDirectory(string $rootDir): void
    {
        $this->twigTemplateDir = $rootDir;
    }

    /**
     * @Given I have the following :templateName template :code
     */
    public function haveTheFollowingTemplate(string $templateName, string $code): void
    {
        $rootDirectory = rtrim($this->config['default_dir'], DIRECTORY_SEPARATOR);
        $templatePath = (
            $rootDirectory.DIRECTORY_SEPARATOR.
            $this->twigTemplateDir.DIRECTORY_SEPARATOR.
            $templateName
        );
        $templateDirectory = dirname($templatePath);
        if (!file_exists($templateDirectory)) {
            mkdir($templateDirectory, 0755, true);
        }

        file_put_contents($templatePath, $code);
    }

    /**
     * @Given the :templateName template is compiled in the :cacheDirectory directory
     */
    public function haveTheTemplateCompiled(string $templateName, string $cacheDirectory): void
    {
        $rootDirectory = rtrim($this->config['default_dir'], DIRECTORY_SEPARATOR);
        $cacheDirectory = $rootDirectory.DIRECTORY_SEPARATOR.ltrim($cacheDirectory, DIRECTORY_SEPARATOR);
        if (!file_exists($cacheDirectory)) {
            mkdir($cacheDirectory, 0755, true);
        }

        $this->loadTemplate($templateName, $rootDirectory, $cacheDirectory);
    }

    /**
     * @Given the last compiled template got his alias changed to :newAlias
     */
    public function changeTheLastTemplateAlias(string $newAlias): void
    {
        if (null === $this->lastCachePath) {
            throw new \RuntimeException('You have to compile a template first.');
        }

        $cacheContent = file_get_contents($this->lastCachePath);

        if (!preg_match('/'.CachedTemplatesMapping::CACHED_TEMPLATE_HEADER_PATTERN.'/m', $cacheContent, $cacheHeadParts)) {
            throw new \RuntimeException('The cache file is somehow malformed.');
        }

        file_put_contents($this->lastCachePath, str_replace(
            $cacheHeadParts[0],
            str_replace($cacheHeadParts['name'], $newAlias, $cacheHeadParts[0]),
            $cacheContent
        ));
    }

    private function loadTemplate(string $templateName, string $rootDirectory, string $cacheDirectory): void
    {
        if (null === $this->twigCache) {
            if (!is_dir($cacheDirectory)) {
                throw new InvalidArgumentException(sprintf('The %s twig cache directory does not exist or is not readable.', $cacheDirectory));
            }
            $this->twigCache = new FilesystemCache($cacheDirectory);
        }

        $twigEnvironment = $this->getEnvironment($rootDirectory, $this->twigCache);
        $template = $twigEnvironment->load($templateName);

        /** @psalm-suppress InternalMethod */
        $this->lastCachePath = $this->twigCache->generateKey($templateName, get_class($template->unwrap()));
    }

    private function getEnvironment(string $rootDirectory, FilesystemCache $twigCache): Environment
    {
        if (!file_exists($rootDirectory.DIRECTORY_SEPARATOR.$this->twigTemplateDir)) {
            mkdir($rootDirectory.DIRECTORY_SEPARATOR.$this->twigTemplateDir);
        }

        $loader = new FilesystemLoader($this->twigTemplateDir, $rootDirectory);

        $twigEnvironment = new Environment($loader, [
            'cache' => $twigCache,
            'auto_reload' => true,
            'debug' => true,
            'optimizations' => 0,
            'strict_variables' => false,
        ]);

        // The following is a trick to have a different twig cache hash everytime, preventing collisions from one test to another :
        // the extension construction has to be evaled so the class name will change each time,
        // making the env calculate a different Twig\Environment::$optionHash (which is partly based on the extension names).
        /** @var AbstractExtension $ext */
        $ext = eval('use Twig\Extension\AbstractExtension; return new class() extends AbstractExtension {};');
        $twigEnvironment->addExtension($ext);

        return $twigEnvironment;
    }
}
