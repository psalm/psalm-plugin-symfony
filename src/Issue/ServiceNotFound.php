<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

class ServiceNotFound extends PluginIssue
{
    public function __construct(string $id, CodeLocation $code_location)
    {
        parent::__construct(sprintf('Service "%s" not found', $id), $code_location);
    }
}
