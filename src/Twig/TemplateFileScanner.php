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
    }
}
