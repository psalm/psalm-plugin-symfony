<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use Psalm\Config;
use Psalm\Internal\MethodIdentifier;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Fetches routes cache file and marks all methods, that are routes, as used.
 */
final class RouterHandler implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $callerIdentifier = new MethodIdentifier(Kernel::class, 'handle');
        foreach (self::getRoutesFromCache() as $route) {
            $controller = $route['_controller'] ?? '';
            if (is_string($controller) && str_contains($controller, '\\')) {
                [$class, $method] = self::parseController($controller);
                $event->getCodebase()->methodExists($class.'::'.$method, null, $callerIdentifier);
            }
        }
    }

    private static function getRoutesFromCache(): array
    {
        $cacheFile = self::getRoutesCacheFilePath();

        if (!is_file($cacheFile) || !is_readable($cacheFile)) {
            return [];
        }

        return \array_column(include $cacheFile, 1);
    }

    private static function getRoutesCacheFilePath(): string
    {
        $baseDir = Config::getInstance()->base_dir;

        return $baseDir.'/var/cache/dev/url_generating_routes.php';
    }

    /**
     * @return list{class-string, string}
     *
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement, LessSpecificReturnStatement
     */
    private static function parseController(string $controller): array
    {
        if (str_contains($controller, '::')) {
            return explode('::', $controller);
        }

        return [$controller, '__invoke'];
    }
}
