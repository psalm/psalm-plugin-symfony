<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Handler;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\SymfonyPsalmPlugin\Symfony\FrameworkCalls;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * Marks the controllers of the routes that the router dumped in its cache as used.
 */
final class RouterHandler implements AfterCodebasePopulatedInterface
{
    private const ROUTES_CACHE_FILE = 'url_generating_routes.php';

    /**
     * The cache directory of the dumped container, which the router shares.
     */
    private static ?string $cacheDir = null;

    private static ?ContainerMeta $containerMeta = null;

    public static function init(ContainerMeta $containerMeta): void
    {
        self::$cacheDir = dirname($containerMeta->getContainerXmlPath());
        self::$containerMeta = $containerMeta;
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $routesCacheFile = (self::$cacheDir ?? rtrim($event->getCodebase()->config->base_dir, DIRECTORY_SEPARATOR).'/var/cache/dev').'/'.self::ROUTES_CACHE_FILE;
        if (!is_file($routesCacheFile) || !is_readable($routesCacheFile)) {
            return;
        }

        $routes = include $routesCacheFile;
        if (!is_array($routes)) {
            return;
        }

        foreach ($routes as $route) {
            // @see \Symfony\Component\Routing\Generator\Dumper\CompiledUrlGeneratorDumper::getCompiledRoutes()
            if (!is_array($route) || !is_array($route[1] ?? null)) {
                continue;
            }

            $controller = self::parseController($route[1]['_controller'] ?? null);
            if (null !== $controller) {
                // the controller resolver instantiates the controllers that are not services
                FrameworkCalls::callMethod($event->getCodebase(), $controller[0], '__construct', FrameworkCalls::KERNEL);
                FrameworkCalls::callMethod($event->getCodebase(), $controller[0], $controller[1], FrameworkCalls::KERNEL);
            }
        }
    }

    /**
     * @see \Symfony\Component\HttpKernel\Controller\ContainerControllerResolver
     *
     * @return array{string, string}|null
     */
    private static function parseController(mixed $controller): ?array
    {
        if (is_array($controller) && is_string($controller[0] ?? null) && is_string($controller[1] ?? null)) {
            [$class, $method] = $controller;
        } elseif (is_string($controller) && '' !== $controller) {
            $parts = explode('::', $controller, 2);
            [$class, $method] = [$parts[0], $parts[1] ?? '__invoke'];
        } else {
            return null;
        }

        if (null !== self::$containerMeta) {
            try {
                $class = self::$containerMeta->get($class)->getClass() ?? $class;
            } catch (ServiceNotFoundException) {
            }
        }

        return [$class, $method];
    }
}
