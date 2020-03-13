<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\CodeIssue;

class RepositoryStringShortcut extends CodeIssue
{
    public function __construct(CodeLocation $code_location)
    {
        parent::__construct('Use Entity::class syntax instead', $code_location);
    }
}
