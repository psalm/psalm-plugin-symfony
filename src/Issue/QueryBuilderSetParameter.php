<?php

namespace Psalm\SymfonyPsalmPlugin\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

/**
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/2.8/reference/query-builder.html#binding-parameters-to-your-query
 */
class QueryBuilderSetParameter extends PluginIssue
{
    public function __construct(CodeLocation $code_location)
    {
        parent::__construct('To improve performance set explicit type for objects', $code_location);
    }
}
