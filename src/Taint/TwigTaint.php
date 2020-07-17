<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Taint;


use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Plugin\Hook\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\SymfonyPsalmPlugin\Test\TwigBridge;
use Twig\Environment;

class TwigTaint implements MethodReturnTypeProviderInterface
{
    public static function getClassLikeNames(): array
    {
        return [
            Environment::class,
        ];
    }

    public static function getMethodReturnType(StatementsSource $source, string $fq_classlike_name, string $method_name_lowercase, array $call_args, Context $context, CodeLocation $code_location, array $template_type_parameters = null, string $called_fq_classlike_name = null, string $called_method_name_lowercase = null)
    {
        if ($method_name_lowercase !== 'render') {
            return;
        }

        $rootDir = __DIR__.'/../../tests/_run';
        $twigEnvironment = TwigBridge::getEnvironment($rootDir, $rootDir.'/cache');

        $templateName = $call_args[0]->value->value;
        $template = $twigEnvironment->load($templateName);
        $internalTemplate = $template->unwrap();

        // The internal template is the real stuff, with calls to `twig_raw_filter` (the actual sink)
        // $internalTemplate::display(...)
    }

    /**
     * This method should be called by some hook happening before the ProjectAnalyzer scans the files
     * Here in the test, as the template cache directory is located under the root directory, it will be analysed, but it will no longer be the case in a real world project
     */
    public function beforeCodebaseIsPopulated(Codebase $codebase)
    {
        // Add some logic to find the twig cache directory (maybe simply using config ?)
        // foreach twigCompiledClass : $codebase->addFilesToAnalyze();
    }
}
