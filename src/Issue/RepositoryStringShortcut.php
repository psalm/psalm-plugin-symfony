<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

class RepositoryStringShortcut extends PluginIssue
{
    public function __construct(CodeLocation $code_location)
    {
        parent::__construct('Use Entity::class syntax instead', $code_location);
    }
}
