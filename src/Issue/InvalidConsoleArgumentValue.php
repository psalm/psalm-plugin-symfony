<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\CodeIssue;

class InvalidConsoleArgumentValue extends CodeIssue
{
    public function __construct(CodeLocation $code_location)
    {
        parent::__construct('Use Symfony\Component\Console\Input\InputArgument constants', $code_location);
    }
}
