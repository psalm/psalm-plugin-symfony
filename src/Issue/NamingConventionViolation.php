<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

/**
 * @see https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions
 */
class NamingConventionViolation extends PluginIssue
{
    public function __construct(CodeLocation $code_location)
    {
        parent::__construct('Use snake_case for configuration parameter and service names', $code_location);
    }
}
