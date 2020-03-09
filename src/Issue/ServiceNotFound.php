<?php

namespace Seferov\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\CodeIssue;

class ServiceNotFound extends CodeIssue
{
    public function __construct(string $id, CodeLocation $code_location)
    {
        parent::__construct(sprintf('Service "%s" not found', $id), $code_location);
    }
}
