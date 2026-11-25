<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Symfony;

use Composer\InstalledVersions;

/**
 * The major version of the installed Symfony, which selects the stubs of the classes that changed between versions.
 */
final class SymfonyVersion
{
    /**
     * The packages whose version is that of the installed Symfony: the kernel (on its own or in the monorepo), then
     * those of the classes the version-specific stubs declare.
     */
    private const VERSION_PACKAGES = [
        'symfony/http-kernel',
        'symfony/symfony',
        'symfony/http-foundation',
        'symfony/framework-bundle',
        'symfony/serializer',
    ];

    /**
     * The major version of the installed Symfony: that of the kernel, or else that of a package with stubs for each
     * version. Null when none of them is installed, as in a project using only other components.
     *
     * The versions come from the installed packages: loading the kernel fails where a class it extends isn't
     * installed (that of symfony/dependency-injection, in http-kernel 8.1).
     */
    public static function detectMajorVersion(): ?string
    {
        foreach (self::VERSION_PACKAGES as $package) {
            if (!InstalledVersions::isInstalled($package)) {
                continue;
            }

            $majorVersion = self::getMajorVersion(InstalledVersions::getVersion($package));
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
