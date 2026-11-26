<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Symfony;

use Composer\InstalledVersions;

/**
 * The major version of an installed Symfony package, which selects the stubs of its classes that changed between
 * versions.
 */
final class SymfonyVersion
{
    /**
     * The major version of an installed package, or of the monorepo that replaces it. Null when neither is
     * installed.
     *
     * Each package has its own version: Laravel 13 installs http-kernel 7.4 next to http-foundation 8.1. The version
     * comes from the installed packages, without loading their classes: loading the kernel fails where a class it
     * extends isn't installed (that of symfony/dependency-injection, in http-kernel 8.1).
     */
    public static function detectMajorVersion(string $package): ?string
    {
        foreach ([$package, 'symfony/symfony'] as $installedPackage) {
            if (!InstalledVersions::isInstalled($installedPackage)) {
                continue;
            }

            // A package the monorepo replaces is installed without a version of its own.
            $majorVersion = self::getMajorVersion(InstalledVersions::getVersion($installedPackage));
            if (null !== $majorVersion) {
                return $majorVersion;
            }
        }

        return null;
    }

    /**
     * The major version of a normalized Composer version, as `7` of `7.4.1.0` or of `7.4.9999999.9999999-dev`. Null
     * for a branch without a version, as `dev-main`.
     *
     * @psalm-pure
     */
    public static function getMajorVersion(?string $normalizedVersion): ?string
    {
        if (null === $normalizedVersion || 1 !== preg_match('/^(\d+)\./', $normalizedVersion, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
