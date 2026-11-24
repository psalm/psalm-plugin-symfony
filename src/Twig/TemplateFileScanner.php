<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Codebase;
use Psalm\Internal\Scanner\FileScanner;
use Psalm\Progress\Progress;
use Psalm\Storage\FileStorage;

/**
 * This class is to be used as a "scanner" for the `.twig` files in the psalm configuration, along with TemplateFileAnalyzer.
 *
 * A template declares nothing PHP code can use, and is not PHP: parsing it as such reports parse errors for the
 * templates containing `<?php`, such as those generating PHP code.
 *
 * @psalm-api
 */
final class TemplateFileScanner extends FileScanner
{
    #[\Override]
    public function scan(
        Codebase $codebase,
        FileStorage $file_storage,
        bool $storage_from_cache = false,
        ?Progress $progress = null,
    ): void {
        $file_storage->deep_scan = $this->will_analyze;

        // the analysis follows the PHP callables of the filters, functions and tests into their classes, like those
        // of Twig, which no PHP file of the project may name
        foreach (TemplateFileAnalyzer::getEnvironment($codebase)->getExtensions() as $extension) {
            foreach ([...$extension->getFilters(), ...$extension->getFunctions(), ...$extension->getTests()] as $twigCallable) {
                $className = self::getClassName($twigCallable->getCallable());
                if (null !== $className) {
                    $codebase->queueClassLikeForScanning($className);
                    $file_storage->referenced_classlikes[strtolower($className)] = $className;
                }
            }
        }
    }

    private static function getClassName(mixed $callable): ?string
    {
        if (\is_array($callable) && isset($callable[0])) {
            $class = \is_object($callable[0]) ? $callable[0]::class : $callable[0];

            return \is_string($class) && '' !== $class ? $class : null;
        }

        if (\is_string($callable) && str_contains($callable, '::')) {
            return explode('::', $callable, 2)[0];
        }

        return null;
    }
}
