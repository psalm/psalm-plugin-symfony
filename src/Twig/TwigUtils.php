<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\StatementsSource;
use Psalm\SymfonyPsalmPlugin\Exception\TemplateNameUnresolvedException;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

final class TwigUtils
{
    /**
     * The values of the constants Psalm does not know the value of, like those defined at run time.
     *
     * @var array<string, string>
     */
    private static array $constants = [];

    /**
     * Makes the constant $name stand for $value in the template names and directories: for the constants whose value
     * is known to the plugins or the configuration but not to Psalm, like a directory of the project defined at run
     * time.
     */
    public static function addConstant(string $name, string $value): void
    {
        self::$constants[ltrim($name, '\\')] = $value;
    }

    public static function extractTemplateNameFromExpression(Expr $templateName, StatementsSource $source): string
    {
        return self::resolveStringFromExpression($templateName, $source);
    }

    /**
     * The name of the template $templateName names in the directory $directory (an absolute path, like `__DIR__`),
     * as the TemplateFileAnalyzer names the template files: its path from the template root, or from the project for
     * a template outside of the root.
     */
    public static function extractTemplateNameInDirectory(Expr $directory, Expr $templateName, StatementsSource $source, Codebase $codebase): string
    {
        $directoryPath = self::resolveStringFromExpression($directory, $source);
        $directoryPath = rtrim(realpath($directoryPath) ?: $directoryPath, '/');
        $projectPath = rtrim(realpath($codebase->config->base_dir) ?: $codebase->config->base_dir, '/');
        if ($directoryPath !== $projectPath && !str_starts_with($directoryPath, $projectPath.'/')) {
            throw new TemplateNameUnresolvedException(sprintf('The template directory %s is not in the project', $directoryPath));
        }

        $relativePath = substr($directoryPath, \strlen($projectPath) + 1);

        return TemplateFileAnalyzer::getTemplateName(
            ('' === $relativePath ? '' : $relativePath.'/').self::resolveStringFromExpression($templateName, $source),
        );
    }

    private static function resolveStringFromExpression(Expr $templateName, StatementsSource $source): string
    {
        if ($templateName instanceof Expr\BinaryOp\Concat) {
            $right = self::resolveStringFromExpression($templateName->right, $source);
            $left = self::resolveStringFromExpression($templateName->left, $source);

            return $left.$right;
        }

        if ($templateName instanceof Expr\ConstFetch && isset(self::$constants[$templateName->name->toString()])) {
            return self::$constants[$templateName->name->toString()];
        }

        // a variable, a constant or `__DIR__` of a literal value
        if (!$templateName instanceof String_) {
            $type = $source->getNodeTypeProvider()->getType($templateName) ?? new Union([new TNull()]);
            $templateName = array_values($type->getAtomicTypes())[0];
        }

        if (!$templateName instanceof String_ && !$templateName instanceof TLiteralString) {
            throw new TemplateNameUnresolvedException(sprintf('Can not retrieve template name from given expression (%s)', get_class($templateName)));
        }

        return $templateName->value;
    }
}
