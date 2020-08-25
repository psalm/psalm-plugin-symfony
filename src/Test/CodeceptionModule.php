<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Test;

use Codeception\Module as BaseModule;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use InvalidArgumentException;
use PackageVersions\Versions;
use PHPUnit\Framework\SkippedTestError;
use RuntimeException;
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

    /**
     * @Given I have the following :templateName template :code
     */
    public function haveTheFollowingTemplate(string $templateName, string $code): void
    {
        $rootDirectory = rtrim($this->config['default_dir'], '/');
        $templateRootDirectory = $rootDirectory.'/templates';
        if (!file_exists($templateRootDirectory)) {
            mkdir($templateRootDirectory);
        }

        file_put_contents(
            $templateRootDirectory.'/'.$templateName,
            $code
        );
    }

    /**
     * @Given the :templateName template is compiled in the :cacheDirectory directory
     */
    public function haveTheTemplateCompiled(string $templateName, string $cacheDirectory): void
    {
        $rootDirectory = rtrim($this->config['default_dir'], '/');
        $cacheDirectory = $rootDirectory.'/'.ltrim($cacheDirectory, '/');
        if (!file_exists($cacheDirectory)) {
            mkdir($cacheDirectory, 0777, true);
        }

        $twigEnvironment = self::getEnvironment($rootDirectory, $cacheDirectory);
        $twigEnvironment->load($templateName);
    }

    /**
     * @Given I have the :package package satisfying the :versionConstraint
     */
    public function haveADependencySatisfied(string $package, string $versionConstraint): void
    {
        $parser = new VersionParser();
        /** @psalm-suppress UndefinedClass Composer\InstalledVersions is undefined when using Composer 1.x */
        /** @psalm-suppress DeprecatedClass PackageVersions\Versions is used for Composer 1.x compatibility */
        if (class_exists(InstalledVersions::class)) {
            /** @var bool $isSatisfied */
            $isSatisfied = InstalledVersions::satisfies($parser, $package, $versionConstraint);
        } elseif (class_exists(Versions::class)) {
            $version = (string) Versions::getVersion($package);

            if (false === strpos($version, '@')) {
                throw new RuntimeException('$version must contain @');
            }

            $isSatisfied = $parser->parseConstraints(explode('@', $version)[0])
                ->matches($parser->parseConstraints($versionConstraint));
        }

        if (!isset($isSatisfied) || !$isSatisfied) {
            throw new SkippedTestError("This scenario requires $package to match $versionConstraint");
        }
    }

    public const TEMPLATE_DIR = 'templates';

    public static function getEnvironment(string $rootDirectory, string $cacheDirectory): Environment
    {
        if (!file_exists($rootDirectory.'/'.self::TEMPLATE_DIR)) {
            mkdir($rootDirectory.'/'.self::TEMPLATE_DIR);
        }

        $loader = new FilesystemLoader(self::TEMPLATE_DIR, $rootDirectory);

        if (!is_dir($cacheDirectory)) {
            throw new InvalidArgumentException(sprintf('The %s twig cache directory does not exist or is not readable.', $cacheDirectory));
        }
        $cache = new FilesystemCache($cacheDirectory);

        $twigEnvironment = new Environment($loader, [
            'cache' => $cache,
            'auto_reload' => true,
            'debug' => true,
            'optimizations' => 0,
            'strict_variables' => false,
        ]);

        // the following is a trick to force the twig env to change
        /** @var AbstractExtension $ext */
        $ext = eval('use Twig\Extension\AbstractExtension; return new class() extends AbstractExtension {};');
        $twigEnvironment->addExtension($ext);

        return $twigEnvironment;
    }
}
