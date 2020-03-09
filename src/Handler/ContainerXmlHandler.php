<?php

namespace Seferov\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Seferov\SymfonyPsalmPlugin\SymfonyContainer;
use Webmozart\Assert\Assert;

class ContainerXmlHandler implements AfterMethodCallAnalysisInterface
{
    /**
     * {@inheritdoc}
     */
    public static function afterMethodCallAnalysis(
        Expr $expr,
        string $method_id,
        string $appearing_method_id,
        string $declaring_method_id,
        Context $context,
        StatementsSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = [],
        Union &$return_type_candidate = null
    ) {
        if (!in_array($declaring_method_id, ['Psr\Container\ContainerInterface::get', 'Symfony\Component\DependencyInjection\ContainerInterface::get'])) {
            return;
        }

        if ($return_type_candidate && $expr->args[0]->value instanceof String_) {
            $simpleXmlConfig = null;
            if (count($codebase->config->getPluginClasses())) {
                foreach ($codebase->config->getPluginClasses() as $pluginClass) {
                    if ($pluginClass['class'] === str_replace('Handler', 'Plugin', __NAMESPACE__)) {
                        $simpleXmlConfig = (string) $pluginClass['config'];
                    }
                }
            }

            if (!is_string($simpleXmlConfig)) {
                throw new \LogicException('This hook is registered when xml file is set');
            }

            $symfonyContainer = new SymfonyContainer($simpleXmlConfig);
            $serviceId = (string) $expr->args[0]->value->value;
            $serviceDefinition = $symfonyContainer->get($serviceId);
            if ($serviceDefinition) {
                if ($serviceDefinition->isPublic()) {
                    $class = $serviceDefinition->getClass();
                    Assert::string($class);
                    $return_type_candidate = new Union([new TNamedObject($class)]);
                }
                // @todo: else emit "get private service" issue
            }
            // @todo: else emit "get non existent service" issue
        }
    }
}
