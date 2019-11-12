<?php

namespace Seferov\SymfonyPsalmPlugin;

use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Seferov\SymfonyPsalmPlugin\Handler\ClassHandler;
use SimpleXMLElement;

class Plugin implements PluginEntryPointInterface
{
    /** @return void */
    public function __invoke(RegistrationInterface $api, SimpleXMLElement $config = null)
    {
        require_once __DIR__.'/Handler/ClassHandler.php';

        $api->registerHooksFromClass(ClassHandler::class);
    }
}
