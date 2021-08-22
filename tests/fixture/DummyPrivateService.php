<?php

namespace Psalm\SymfonyPsalmPlugin\Tests\Fixture;

class DummyPrivateService
{
    const CUSTOM_SERVICE_NAME = 'dummy_service_with_locator';

    public function foo(): string
    {
        return 'foo';
    }
}
