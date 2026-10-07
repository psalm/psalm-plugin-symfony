<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Codebase;
use Psalm\Context as PsalmContext;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Extension\ExtensionInterface;
use Twig\Loader\FilesystemLoader;
use Twig\NodeTraverser;

/**
 * This class is to be used as a "checker" for the `.twig` files in the psalm configuration.
 */
final class TemplateFileAnalyzer extends FileAnalyzer
{
    private static string $rootPath = 'templates';

    /**
     * @var list<class-string>
     */
    private static array $extensionClasses = [];

    private static ?Environment $environment = null;

    /**
     * @param list<class-string> $extensionClasses
     */
    public static function initExtensions(array $extensionClasses): void
    {
        self::$extensionClasses = $extensionClasses;
        self::$environment = null;
    }

    #[\Override]
    public function analyze(
        ?PsalmContext $file_context = null,
        ?PsalmContext $global_context = null,
    ): void {
        $codebase = $this->project_analyzer->getCodebase();
        $taint = $codebase->taint_flow_graph;

        if (null === $taint) {
            return;
        }

        $twig = self::getEnvironment($codebase);

        $local_file_name = str_starts_with($this->file_name, self::$rootPath.'/')
            ? substr($this->file_name, \strlen(self::$rootPath) + 1)
            : $this->file_name;
        try {
            $twig_source = $twig->getLoader()->getSourceContext($local_file_name);
            $tree = $twig->parse($twig->tokenize($twig_source));
        } catch (Error $error) {
            // e.g. a filter of an extension the analysis was not given
            $this->project_analyzer->progress->warning(\sprintf('The taints of the Twig template %s are not analyzed: %s', $local_file_name, $error->getMessage()));

            return;
        }

        $twigContext = new Context($twig_source, $taint, $twig, $codebase);

        $traverser = new NodeTraverser($twig, [
            new TaintAnalysisVisitor($twigContext),
        ]);

        $traverser->traverse($tree);

        $twigContext->taintUnassignedVariables($local_file_name);
        $twigContext->taintSinks($local_file_name);
    }

    /**
     * The Twig environment the templates are analyzed with.
     */
    public static function getEnvironment(Codebase $codebase): Environment
    {
        if (null !== self::$environment) {
            return self::$environment;
        }

        // a template outside of the root is named after its path in the project
        $loader = new FilesystemLoader([self::$rootPath, '.'], $codebase->config->base_dir);
        $twig = new Environment($loader, [
            'cache' => false,
            'auto_reload' => true,
            'debug' => true,
            'optimizations' => 0,
            'strict_variables' => false,
        ]);
        foreach (self::$extensionClasses as $extensionClass) {
            if (class_exists($extensionClass) && is_a($extensionClass, ExtensionInterface::class, true)) {
                $twig->addExtension((new \ReflectionClass($extensionClass))->newInstanceWithoutConstructor());
            }
        }

        return self::$environment = $twig;
    }

    public static function setTemplateRootPath(string $rootPath): void
    {
        self::$rootPath = $rootPath;
        self::$environment = null;
    }
}
