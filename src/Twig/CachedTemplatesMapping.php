<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * This class is used to store a mapping of all analyzed twig template cache files with their corresponding actual templates.
 */
class CachedTemplatesMapping implements AfterCodebasePopulatedInterface
{
    /**
     * @var string
     */
    public const CACHED_TEMPLATE_HEADER_PATTERN =
        'use Twig\\\\Template;\n\n'.
        '\/\* (?<name>@?.+\.twig) \*\/\n'.
        'class (?<class>__TwigTemplate_[a-z0-9]{64}) extends (\\\\Twig\\\\)?Template';

    /**
     * @var string|null
     */
    private static $cachePath;

    /**
     * @var CachedTemplatesRegistry|null
     */
    private static $cacheRegistry;

    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event)
    {
        $codebase = $event->getCodebase();

        if (null === self::$cachePath) {
            return;
        }

        self::$cacheRegistry = new CachedTemplatesRegistry();
        $cacheFiles = $codebase->file_provider->getFilesInDir(self::$cachePath, ['php']);

        foreach ($cacheFiles as $file) {
            $rawSource = $codebase->file_provider->getContents($file);

            if (!preg_match('/'.self::CACHED_TEMPLATE_HEADER_PATTERN.'/m', $rawSource, $matchingParts)) {
                continue;
            }
            $templateName = $matchingParts['name'];
            $cacheClassName = $matchingParts['class'];

            self::$cacheRegistry->addTemplate($cacheClassName, $templateName);
        }
    }

    public static function setCachePath(string $cachePath): void
    {
        self::$cachePath = $cachePath;
    }

    /**
     * @throws CachedTemplateNotFoundException
     */
    public static function getCacheClassName(string $templateName): string
    {
        if (null === self::$cacheRegistry) {
            throw new \RuntimeException(sprintf('Can not load template %s, because no cache registry is provided.', $templateName));
        }

        return self::$cacheRegistry->getCacheClassName($templateName);
    }
}
