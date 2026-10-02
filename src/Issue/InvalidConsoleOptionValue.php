<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class InvalidConsoleOptionValue extends PluginIssue
{
    /**
     * @psalm-capabilities read-props
     */
    public function __construct(CodeLocation $code_location)
    {
        parent::__construct('Use Symfony\Component\Console\Input\InputOption constants', $code_location);
    }
}
