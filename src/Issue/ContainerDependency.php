<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

class ContainerDependency extends PluginIssue
{
    public function __construct(CodeLocation $code_location)
    {
        parent::__construct('Container must not inject into services as dependency! Use dependency-injection.', $code_location);
    }
}
