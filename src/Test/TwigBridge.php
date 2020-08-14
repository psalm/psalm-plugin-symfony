<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Test;


use InvalidArgumentException;
use Psalm\CodeLocation;
use RuntimeException;
use Twig\Cache\CacheInterface;
use Twig\Cache\FilesystemCache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TwigBridge
{
    public const TEMPLATE_DIR = 'templates';

    /**
     * @var Environment|null
     */
    static private $environment;

    public static function getEnvironment(string $rootDirectory, string $cacheDirectory): Environment
    {
        if(self::$environment !== null){
            return self::$environment;
        }

        if (!file_exists($rootDirectory.'/'.self::TEMPLATE_DIR)) {
            mkdir($rootDirectory.'/'.self::TEMPLATE_DIR);
        }

        $loader = new FilesystemLoader(self::TEMPLATE_DIR, $rootDirectory);

        if (!is_dir($cacheDirectory)) {
            throw new InvalidArgumentException(sprintf('The %s twig cache directory does not exist or is not readable.', $cacheDirectory));
        }
        $cache = new FilesystemCache($cacheDirectory);

        return self::$environment = new Environment($loader, [
            'cache' => $cache,
            'auto_reload' => true,
            'debug' => true,
            'optimizations' => 0,
            'strict_variables' => false,
        ]);
    }
}
