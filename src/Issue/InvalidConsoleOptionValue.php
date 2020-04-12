<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\CodeIssue;

class InvalidConsoleOptionValue extends CodeIssue
{
    public function __construct(CodeLocation $code_location)
    {
        parent::__construct('Use Symfony\Component\Console\Input\InputOption constants', $code_location);
    }
}
