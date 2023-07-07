<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

class CachedTemplatesRegistry
{
    /**
     * @var array<string, string>
     */
    private $mapping = [];

    public function addTemplate(string $cacheClassName, string $templateName): void
    {
        $this->mapping[$templateName] = $cacheClassName;
    }

    /**
     * @throws CachedTemplateNotFoundException
     */
    public function getCacheClassName(string $templateName): string
    {
        $probableException = new CachedTemplateNotFoundException();

        foreach (self::generateNames($templateName) as $possibleName) {
            if (array_key_exists($possibleName, $this->mapping)) {
                return $this->mapping[$possibleName];
            }
            $probableException->addTriedName($possibleName);
        }

        throw $probableException;
    }

    /**
     * @return \Generator<string>
     */
    private static function generateNames(string $baseName): \Generator
    {
        yield $baseName;

        /** @var string|null $oldNotation */
        $oldNotation = null;

        $alternativeNotation = preg_replace('/^@([^\/]+)\/?(.+)?\/([^\/]+\.twig)/', '$1Bundle:$2:$3', $baseName);
        if ($alternativeNotation !== $baseName) {
            yield $alternativeNotation;
            $oldNotation = $alternativeNotation;
        }

        $alternativeNotation = preg_replace('/^(.+)Bundle:(.+)?:(.+\.twig)$/', '@$1/$2/$3', $baseName);
        if ($alternativeNotation !== $baseName) {
            yield str_replace('//', '/', $alternativeNotation);
            $oldNotation = $baseName;
        }

        if (null !== $oldNotation && false !== strpos($oldNotation, ':')) {
            /** @psalm-suppress PossiblyUndefinedArrayOffset */
            list($bundleName, $rest) = explode(':', $oldNotation, 2);
            /** @psalm-suppress PossiblyUndefinedArrayOffset */
            list($revTemplateName, $revRest) = explode(':', strrev($rest), 2);
            $pathParts = explode('/', strrev($revRest));
            $pathParts = array_merge($pathParts, explode('/', strrev($revTemplateName)));
            for ($i = 0; $i <= count($pathParts); ++$i) {
                yield $bundleName.':'.
                    implode('/', array_slice($pathParts, 0, $i)).':'.
                    implode('/', array_slice($pathParts, $i));
            }
        }
    }
}
