<?php

namespace Psalm\SymfonyPsalmPlugin\Tests\Fixture;

class DummyPrivateService
{
    public function foo(): string
    {
        return 'foo';
    }
}
