<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\CodeIssue;

/**
 * @see https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions
 */
class NamingConventionViolation extends CodeIssue
{
    public function __construct(CodeLocation $code_location)
    {
        parent::__construct('Use snake_case for configuration parameter and service names', $code_location);
    }
}
