<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Test;


use Codeception\Exception\ModuleRequireException;
use Codeception\Module as BaseModule;
use Codeception\Module\Filesystem;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use PackageVersions\Versions;
use PHPUnit\Framework\SkippedTestError;
use RuntimeException;

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

    /** @var FileSystem */
    private $fs;

    /**
     * @Given I have the following :templateName template :code
     */
    public function haveTheFollowingTemplate(string $templateName, string $code): void
    {
        $rootDirectory = rtrim($this->config['default_dir'], '/');
        $templateRootDirectory = $rootDirectory . '/templates';
        $cacheDirectory = $rootDirectory.'/cache/twig';
        if(!file_exists($templateRootDirectory)) {
            mkdir($templateRootDirectory);
        }
        if(!file_exists($cacheDirectory)) {
            mkdir($cacheDirectory, 0777, true);
        }

        $this->fs()->writeToFile(
            $templateRootDirectory . '/' . $templateName,
            $code
        );

//         Generate template compiled classes so psalm can analyse them
        $twigEnvironment = TwigBridge::getEnvironment($rootDirectory, $cacheDirectory);
        $twigEnvironment->load($templateName);
    }

    private function fs(): Filesystem
    {
        if (null === $this->fs) {
            $fs = $this->getModule('Filesystem');
            if (!$fs instanceof Filesystem) {
                throw new ModuleRequireException($this, 'Needs Filesystem module');
            }
            $this->fs = $fs;
        }
        return $this->fs;
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
}
