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
            'debug' => true,
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

    public static function getLocation(\Twig\Source $sourceContext, int $lineNumber) : CodeLocation
    {
        $fileName = $sourceContext->getName();
        $filePath = $sourceContext->getPath();
        $fileCode = file_get_contents($filePath);

        $lines = explode("\n", $fileCode);

        $file_start = 0;

        for ($i = 0; $i < $lineNumber - 1; $i++) {
            $file_start += strlen($lines[$i]) + 1;
        }

        $file_start += strpos($lines[$lineNumber - 1], $fileCode);
        $file_end = $file_start + strlen($fileCode);

        return new CodeLocation\Raw(
            $fileCode,
            $filePath,
            $fileName,
            $file_start,
            $file_end
        );
    }
}
