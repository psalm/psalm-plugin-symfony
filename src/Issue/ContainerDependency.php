<?php

namespace Seferov\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\CodeIssue;

class ContainerDependency extends CodeIssue
{
    public function __construct(CodeLocation $code_location)
    {
        parent::__construct('Container must not inject into services as dependency!', $code_location);
    }
}
