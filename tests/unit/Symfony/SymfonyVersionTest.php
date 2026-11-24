<?php

namespace Psalm\SymfonyPsalmPlugin\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psalm\SymfonyPsalmPlugin\Symfony\SymfonyVersion;
use Symfony\Component\HttpKernel\Kernel;

/**
 * @testdox SymfonyVersionTest
 */
class SymfonyVersionTest extends TestCase
{
    public function testDetectsTheVersionOfTheKernel(): void
    {
        $this->assertSame((string) Kernel::MAJOR_VERSION, SymfonyVersion::detectMajorVersion());
    }

    /**
     * @dataProvider normalizedVersions
     */
    public function testReadsTheMajorVersion(?string $normalizedVersion, ?string $majorVersion): void
    {
        $this->assertSame($majorVersion, SymfonyVersion::getMajorVersion($normalizedVersion));
    }

    /**
     * @return iterable<array{?string, ?string}>
     */
    public function normalizedVersions(): iterable
    {
        yield 'release' => ['7.4.1.0', '7'];
        yield 'branch' => ['8.1.9999999.9999999-dev', '8'];
        yield 'two digits' => ['10.0.0.0', '10'];
        yield 'branch without a version' => ['dev-main', null];
        yield 'unknown' => [null, null];
    }
}
