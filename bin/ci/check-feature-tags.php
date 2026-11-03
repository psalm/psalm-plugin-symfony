<?php

declare(strict_types=1);

/*
 * Fails when an acceptance feature carries no tag that any "Tests" job runs.
 *
 * Codeception turns Gherkin tags into groups, and the workflow runs `-g symfony-common`, `-g symfony-<version>` and
 * `-g symfony-<major>` for every Symfony version of .github/acceptance-matrix.json. A feature that has none of those
 * tags is never executed, which is easy to miss: the suite stays green. `@symfony-disabled` is the only way to opt out
 * on purpose.
 *
 * The run set is derived from the matrix, so adding a Symfony version there needs no change here.
 *
 * Usage: php bin/ci/check-feature-tags.php [features-dir] [matrix-file]
 */

const OPTED_OUT_TAG = 'symfony-disabled';
const COMMON_TAG = 'symfony-common';

$root = dirname(__DIR__, 2);
$featuresDir = $argv[1] ?? $root.'/tests/acceptance/acceptance';
$matrixFile = $argv[2] ?? $root.'/.github/acceptance-matrix.json';

/**
 * @return list<string> groups that the "tests" job runs for at least one matrix entry
 */
function runGroups(string $matrixFile): array
{
    $matrix = json_decode((string) file_get_contents($matrixFile), true, flags: JSON_THROW_ON_ERROR);

    $groups = [COMMON_TAG];
    foreach (['always', 'scheduled-only'] as $key) {
        foreach ($matrix[$key] ?? [] as $combination) {
            $version = (string) ($combination['symfony-version'] ?? '');
            if ('' === $version) {
                throw new RuntimeException(sprintf('An entry of "%s" in %s has no symfony-version.', $key, $matrixFile));
            }
            $groups[] = 'symfony-'.$version;
            $groups[] = 'symfony-'.explode('.', $version)[0];
        }
    }

    if (1 === count($groups)) {
        throw new RuntimeException(sprintf('No Symfony version found in %s.', $matrixFile));
    }

    return array_values(array_unique($groups));
}

/**
 * @return list<string> tags written above the "Feature:" line, without the leading "@"
 */
function featureTags(string $featureFile): array
{
    $tags = [];
    foreach (file($featureFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'Feature:')) {
            return $tags;
        }
        if (str_starts_with($line, '@')) {
            // Same lexing as Gherkin: a "#" after whitespace starts a comment, and a tag runs up to the next whitespace
            // (so "@a, @b" is the tag "a," followed by "b").
            $line = (string) preg_replace('/\s#.*$/', '', $line);
            foreach (preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (str_starts_with($token, '@')) {
                    $tags[] = substr($token, 1);
                }
            }
        }
    }

    throw new RuntimeException(sprintf('%s has no "Feature:" line.', $featureFile));
}

$groups = runGroups($matrixFile);
$features = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($featuresDir, FilesystemIterator::SKIP_DOTS)),
    '/\.feature$/',
);

$checked = 0;
$neverRun = [];
foreach ($features as $feature) {
    ++$checked;
    $tags = featureTags($feature->getPathname());
    if ([] === array_intersect($tags, [...$groups, OPTED_OUT_TAG])) {
        $neverRun[$feature->getPathname()] = $tags;
    }
}

if (0 === $checked) {
    fwrite(STDERR, sprintf("No .feature files found in %s.\n", $featuresDir));
    exit(1);
}

if ([] !== $neverRun) {
    ksort($neverRun);
    fwrite(STDERR, sprintf("Features that no CI job runs (run groups: %s):\n", implode(', ', $groups)));
    foreach ($neverRun as $path => $tags) {
        fwrite(STDERR, sprintf("  %s [%s]\n", $path, [] === $tags ? 'untagged' : '@'.implode(' @', $tags)));
    }
    fwrite(STDERR, sprintf("Tag them with one of the run groups, or with @%s to switch them off deliberately.\n", OPTED_OUT_TAG));
    exit(1);
}

fwrite(STDOUT, sprintf("All %d features carry a tag that CI runs (%s) or @%s.\n", $checked, implode(', ', $groups), OPTED_OUT_TAG));
