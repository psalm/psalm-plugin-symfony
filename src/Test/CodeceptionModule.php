<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Test;

use Codeception\Module as BaseModule;
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
