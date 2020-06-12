<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

class InvalidConsoleOptionValue extends PluginIssue
{
    public function __construct(CodeLocation $code_location)
    {
        parent::__construct('Use Symfony\Component\Console\Input\InputOption constants', $code_location);
    }
}
