<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Codebase;
use Psalm\Context;
use Psalm\Plugin\Hook\AfterFileAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FileStorage;
use Psalm\SymfonyPsalmPlugin\Plugin;
use RuntimeException;

/**
 * This class is used to store a mapping of all analyzed twig template cache files with their corresponding actual templates.
 */
class CachedTemplatesMapping implements AfterFileAnalysisInterface
{
    /**
     * @var string
     */
    private const CACHED_TEMPLATE_HEADER_PATTERN = 'use Twig\\\\Template;\n\n\/\* (@?.+\.twig) \*\/\nclass __TwigTemplate';

    /**
     * @var array<string, string>
     */
    private static $mapping = [];

    public static function afterAnalyzeFile(StatementsSource $statements_source, Context $file_context, FileStorage $file_storage, Codebase $codebase): void
    {
        $basePath = Plugin::$twig_cache_path;
        if (null === $basePath || 0 !== strpos($file_storage->file_path, $basePath)) {
            return;
        }

        $rawSource = file_get_contents($file_storage->file_path);
        if (!preg_match('/'.self::CACHED_TEMPLATE_HEADER_PATTERN.'/m', $rawSource, $matchingParts)) {
            return;
        }

        /** @var string|null $cacheClassName */
        [$cacheClassName] = array_values($file_storage->classlikes_in_file);
        if (null === $cacheClassName) {
            return;
        }

        self::registerNewCache($cacheClassName, $matchingParts[1]);
    }

    private static function registerNewCache(string $cacheClassName, string $templateName): void
    {
        static::$mapping[$templateName] = $cacheClassName;
    }

    public static function getCacheClassName(string $templateName): string
    {
        if (!array_key_exists($templateName, static::$mapping)) {
            throw new RuntimeException(sprintf('The template %s was not found.', $templateName));
        }

        return static::$mapping[$templateName];
    }
}
