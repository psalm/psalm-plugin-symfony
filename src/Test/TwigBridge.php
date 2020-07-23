<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Test;


use InvalidArgumentException;
use RuntimeException;
use Twig\Cache\CacheInterface;
use Twig\Cache\FilesystemCache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TwigBridge
{
    public const TEMPLATE_DIR = 'templates';

    public static function getEnvironment(string $rootDirectory, string $cacheDirectory): Environment
    {
        static $environment = null;
        if($environment !== null){
            return $environment;
        }

        if (!file_exists($rootDirectory.'/'.self::TEMPLATE_DIR)) {
            mkdir($rootDirectory.'/'.self::TEMPLATE_DIR);
        }

        $loader = new FilesystemLoader(self::TEMPLATE_DIR, $rootDirectory);

        if (!is_dir($cacheDirectory)) {
            throw new InvalidArgumentException(sprintf('The %s twig cache directory does not exist or is not readable.', $cacheDirectory));
        }
        $cache = new FilesystemCache($cacheDirectory);

        return $environment = new Environment($loader, [
            'cache' => $cache,
            'auto_reload' => true,
            'debug' => false,
            'optimizations' => 0,
            'strict_variables' => false,
        ]);
    }

    public static function getCache(string $directory = null): CacheInterface
    {
        static $cache = null;
        if($cache !== null){
            return $cache;
        }

        if ($directory === null) {
            throw new InvalidArgumentException(sprintf('The first call to %s should have the cache directory as argument.', __METHOD__));
        }

        return $cache = new FilesystemCache($directory);
    }
}
