<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Test;

use Behat\Gherkin\Node\PyStringNode;
use Codeception\Exception\ModuleRequireException;
use Codeception\Module as BaseModule;
use Codeception\TestInterface;
use Psalm\SymfonyPsalmPlugin\Twig\CachedTemplatesMapping;
use Twig\Cache\FilesystemCache;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\FilesystemLoader;
use Weirdan\Codeception\Psalm\Module;

/**
 * @psalm-suppress UnusedClass
 * This class is to be used in codeception configuration - like in tests/acceptance/acceptance.suite.yml.
 */
class CodeceptionModule extends BaseModule
{
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

    /**
     * @var list<string>
     */
    private $suppressedIssueHandlers = [];

    public function _initialize(): void
    {
        $this->_setConfig([
            'default_dir' => 'tests/_run/',
        ]);
    }

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
    public function haveTheFollowingTemplate(string $templateName, PyStringNode $code): void
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

        file_put_contents($templatePath, $code->getRaw());
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

    public function _before(TestInterface $test): void
    {
        $this->suppressedIssueHandlers = ['UnusedVariable'];
    }

    /**
     * @Given I have issue handler :issueHandlers suppressed
     * @Given I have issue handlers :issueHandlers suppressed
     */
    public function configureIgnoredIssueHandlers(string $issueHandlers): void
    {
        $this->suppressedIssueHandlers = array_map('trim', explode(',', $issueHandlers));
    }

    /**
     * @Given I have Symfony plugin enabled
     */
    public function configureCommonPsalmconfigEmpty(): void
    {
        $this->configureCommonPsalmconfig(new PyStringNode([], 0));
    }

    /**
     * @Given I have Symfony plugin enabled with the following config :configuration
     */
    public function configureCommonPsalmconfig(PyStringNode $configuration): void
    {
        $suppressedIssueHandlers = implode("\n", array_map(function (string $issueHandler) {
            return "<$issueHandler errorLevel=\"info\"/>";
        }, $this->suppressedIssueHandlers));

        $psalmModule = $this->getModule(Module::class);

        if (!$psalmModule instanceof Module) {
            throw new ModuleRequireException($this, sprintf('Needs "%s" module', Module::class));
        }

        $psalmModule->haveTheFollowingConfig(<<<XML
<?xml version="1.0"?>
  <psalm errorLevel="1">
    <projectFiles>
      <directory name="."/>
      <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
    </projectFiles>

    <plugins>
      <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
        {$configuration->getRaw()}
      </pluginClass>
    </plugins>
    <issueHandlers>
      $suppressedIssueHandlers
    </issueHandlers>
  </psalm>
XML
        );
    }

    private function loadTemplate(string $templateName, string $rootDirectory, string $cacheDirectory): void
    {
        if (null === $this->twigCache) {
            if (!is_dir($cacheDirectory)) {
                throw new \InvalidArgumentException(sprintf('The %s twig cache directory does not exist or is not readable.', $cacheDirectory));
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
