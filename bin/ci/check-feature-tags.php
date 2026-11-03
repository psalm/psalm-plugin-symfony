<?php

declare(strict_types=1);

/*
 * Fails when an acceptance feature carries no tag that any "Tests" job of integrate.yaml runs.
 *
 * Codeception turns Gherkin tags into groups, and the workflow runs `-g symfony-common`, `-g symfony-<matrix value>`
 * and `-g symfony-<Symfony major>` for every matrix entry. A feature that has none of those tags is never executed,
 * which is easy to miss: the suite stays green. `@symfony-disabled` is the only way to opt out on purpose.
 *
 * The run set is derived from the matrix, so adding a Symfony version there needs no change here.
 *
 * Usage: php bin/ci/check-feature-tags.php [features-dir] [workflow-file]
 */

use Symfony\Component\Yaml\Yaml;

require __DIR__.'/../../vendor/autoload.php';

const OPTED_OUT_TAG = 'symfony-disabled';
const COMMON_TAG = 'symfony-common';

$root = dirname(__DIR__, 2);
$featuresDir = $argv[1] ?? $root.'/tests/acceptance/acceptance';
$workflowFile = $argv[2] ?? $root.'/.github/workflows/integrate.yaml';

/**
 * @return list<string> groups that the "tests" job runs for at least one matrix entry
 */
function runGroups(string $workflowFile): array
{
    $workflow = Yaml::parseFile($workflowFile);
    $versions = $workflow['jobs']['tests']['strategy']['matrix']['symfony-version'] ?? null;

    if (!is_array($versions) || [] === $versions) {
        throw new RuntimeException(sprintf('No jobs.tests.strategy.matrix.symfony-version list found in %s.', $workflowFile));
    }

    $groups = [COMMON_TAG];
    foreach ($versions as $version) {
        // GitHub renders unquoted YAML numbers like PHP casts them: 7.0 becomes "7", 7.4 stays "7.4"
        $rendered = (string) $version;
        $groups[] = 'symfony-'.$rendered;
        $groups[] = 'symfony-'.explode('.', $rendered)[0];
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
            foreach (preg_split('/[\s,]+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tag) {
                $tags[] = ltrim($tag, '@');
            }
        }
    }

    throw new RuntimeException(sprintf('%s has no "Feature:" line.', $featureFile));
}

$groups = runGroups($workflowFile);
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
