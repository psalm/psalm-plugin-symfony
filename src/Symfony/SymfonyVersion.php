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
     * The major version of an installed package, or of the monorepo when it replaces the package. Null when the
     * package isn't installed.
     *
     * Each package has its own version: Laravel 13 can install http-kernel 7.4 next to http-foundation 8.1. The
     * version comes from the installed packages, without loading their classes: loading the kernel fails where a
     * class it extends isn't installed (that of symfony/dependency-injection, in http-kernel 8.1).
     */
    public static function detectMajorVersion(string $package): ?string
    {
        if (!InstalledVersions::isInstalled($package)) {
            return null;
        }

        $majorVersion = self::getMajorVersion(InstalledVersions::getVersion($package));
        if (null !== $majorVersion || !InstalledVersions::isInstalled('symfony/symfony')) {
            return $majorVersion;
        }

        // A package the monorepo replaces is installed without a version of its own.
        return self::getMajorVersion(InstalledVersions::getVersion('symfony/symfony'));
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
