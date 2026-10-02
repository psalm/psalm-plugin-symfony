<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class RepositoryStringShortcut extends PluginIssue
{
    /**
     * @psalm-capabilities read-props
     */
    public function __construct(CodeLocation $code_location)
    {
        parent::__construct('Use Entity::class syntax instead', $code_location);
    }
}
