<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

class PrivateService extends PluginIssue
{
    public function __construct(string $id, CodeLocation $code_location)
    {
        parent::__construct(sprintf('Private service "%s" used in container::get()', $id), $code_location);
    }
}
