<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Symfony;

use Composer\Autoload\ClassLoader;

/**
 * The class a stub file declares, whose stub is only added when the project can load it: the stub of a class of a
 * package the project doesn't install would hide that the class doesn't exist.
 */
final class StubbedClass
{
    /**
     * Whether the class of a stub file can be loaded, or true for a stub file of functions or of a class without a
     * namespace, like that of a PHP class.
     */
    public static function isLoadable(string $stubFile): bool
    {
        $className = self::getClassName((string) file_get_contents($stubFile));
        if (null === $className) {
            return true;
        }

        if (class_exists($className, false) || interface_exists($className, false) || trait_exists($className, false) || enum_exists($className, false)) {
            return true;
        }

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            if (false !== $loader->findFile($className)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name of the class, interface, trait or enum a stub declares in a namespace.
     *
     * @psalm-pure
     */
    public static function getClassName(string $stub): ?string
    {
        if (1 !== preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $stub, $namespace)
            || 1 !== preg_match('/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $stub, $class)
        ) {
            return null;
        }

        return $namespace[1].'\\'.$class[1];
    }
}
