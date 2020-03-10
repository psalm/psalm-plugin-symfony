<?php

namespace Seferov\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Seferov\SymfonyPsalmPlugin\Issue\PrivateService;
use Seferov\SymfonyPsalmPlugin\Issue\ServiceNotFound;
use Seferov\SymfonyPsalmPlugin\Symfony\ContainerMeta;

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
        if (!in_array($declaring_method_id, [
            'Psr\Container\ContainerInterface::get',
            'Symfony\Component\DependencyInjection\ContainerInterface::get',
            "Symfony\Bundle\FrameworkBundle\Controller\AbstractController::get",
        ])) {
            return;
        }

        if ($expr->args[0]->value instanceof String_) {
            $serviceId = (string) $expr->args[0]->value->value;
        } elseif ($expr->args[0]->value instanceof ClassConstFetch) {
            $serviceId = (string) $expr->args[0]->value->class->getAttribute('resolvedName');
        } else {
            return;
        }

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

        $containerMeta = new ContainerMeta($simpleXmlConfig);
        $service = $containerMeta->get($serviceId);
        if ($service) {
            if ($service->isPublic()) {
                $class = $service->getClassName();
                if ($class) {
                    $return_type_candidate = new Union([new TNamedObject($class)]);
                }
            } else {
                IssueBuffer::accepts(
                    new PrivateService($serviceId, new CodeLocation($statements_source, $expr->args[0]->value)),
                    $statements_source->getSuppressedIssues()
                );
            }
        } else {
            IssueBuffer::accepts(
                new ServiceNotFound($serviceId, new CodeLocation($statements_source, $expr->args[0]->value)),
                $statements_source->getSuppressedIssues()
            );
        }
    }
}
