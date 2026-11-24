<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Symfony;

use Composer\InstalledVersions;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The major version of the installed Symfony, which selects the stubs of the classes that changed between versions.
 */
final class SymfonyVersion
{
    /**
     * The packages of the classes the version-specific stubs declare.
     */
    private const STUBBED_PACKAGES = [
        'symfony/http-foundation',
        'symfony/framework-bundle',
        'symfony/serializer',
    ];

    /**
     * The major version of the installed Symfony: that of the kernel, or else that of a package with stubs for each
     * version. Null when none of them is installed, as in a project using only other components.
     */
    public static function detectMajorVersion(): ?string
    {
        if (class_exists(Kernel::class)) {
            return (string) Kernel::MAJOR_VERSION;
        }

        foreach (self::STUBBED_PACKAGES as $package) {
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
