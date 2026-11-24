<?php

namespace Psalm\SymfonyPsalmPlugin\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psalm\SymfonyPsalmPlugin\Symfony\StubbedClass;

/**
 * @testdox StubbedClassTest
 */
class StubbedClassTest extends TestCase
{
    public function testAStubOfAnInstalledClassIsLoadable(): void
    {
        $this->assertTrue(StubbedClass::isLoadable(__DIR__.'/../../../src/Stubs/common/Component/HttpFoundation/Request.stubphp'));
    }

    public function testAStubOfAClassNoPackageDeclaresIsNotLoadable(): void
    {
        $stubFile = tempnam(sys_get_temp_dir(), 'stub');
        file_put_contents($stubFile, "<?php\n\nnamespace Symfony\\Component\\NotInstalled;\n\nclass Missing\n{\n}\n");

        try {
            $this->assertFalse(StubbedClass::isLoadable($stubFile));
        } finally {
            unlink($stubFile);
        }
    }

    public function testAStubWithoutANamespacedClassIsLoadable(): void
    {
        $this->assertTrue(StubbedClass::isLoadable(__DIR__.'/../../../src/Stubs/php/UnitEnum.stubphp'));
        $this->assertTrue(StubbedClass::isLoadable(__DIR__.'/../../../src/Stubs/common/Twig/twig_functions.stubphp'));
    }

    /**
     * @dataProvider stubs
     */
    public function testReadsTheClassName(string $stub, ?string $className): void
    {
        $this->assertSame($className, StubbedClass::getClassName($stub));
    }

    /**
     * @return iterable<array{string, ?string}>
     */
    public function stubs(): iterable
    {
        yield 'class' => ["<?php\nnamespace Symfony\\Component\\Form;\n\nclass Form implements \\IteratorAggregate\n{\n}\n", 'Symfony\Component\Form\Form'];
        yield 'abstract class' => ["<?php\nnamespace Symfony\\Component\\Form;\n\n/**\n * @template T\n */\nabstract class AbstractType\n{\n}\n", 'Symfony\Component\Form\AbstractType'];
        yield 'interface' => ["<?php\n\nnamespace Psr\\Container;\n\ninterface ContainerInterface\n{\n}\n", 'Psr\Container\ContainerInterface'];
        yield 'trait' => ["<?php\nnamespace Symfony\\Component\\Console\\Command;\n\ntrait LockableTrait\n{\n}\n", 'Symfony\Component\Console\Command\LockableTrait'];
        yield 'without a namespace' => ["<?php\n\ninterface UnitEnum\n{\n}\n", null];
        yield 'functions' => ["<?php\n\nfunction twig_raw_filter(string \$string): string {}\n", null];
    }
}
