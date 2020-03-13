<?php

namespace Psalm\SymfonyPsalmPlugin\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psalm\Exception\ConfigException;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\SymfonyPsalmPlugin\Symfony\Service;

/**
 * @testdox ContainerMetaTest
 */
class ContainerMetaTest extends TestCase
{
    /**
     * @var ContainerMeta
     */
    private $containerMeta;

    public function setUp()
    {
        $this->containerMeta = new ContainerMeta(__DIR__.'/../../acceptance/container.xml');
    }

    public function tearDown()
    {
        unset($this->containerMeta);
    }

    /**
     * @testdox service attributes
     * @dataProvider publicServices
     */
    public function testServices($id, string $className, bool $isPublic)
    {
        $service = $this->containerMeta->get($id);
        $this->assertInstanceOf(Service::class, $service);
        $this->assertSame($className, $service->getClassName());
        $this->assertSame($isPublic, $service->isPublic());
    }

    public function publicServices()
    {
        return [
            [
                'id' => 'service_container',
                'className' => 'Symfony\Component\DependencyInjection\ContainerInterface',
                'isPublic' => true,
            ],
            [
                'id' => 'Foo\Bar',
                'className' => 'Foo\Bar',
                'isPublic' => false,
            ],
            [
                'id' => 'Symfony\Component\HttpKernel\HttpKernelInterface',
                'className' => 'Symfony\Component\HttpKernel\HttpKernel',
                'isPublic' => true,
            ],
        ];
    }

    /**
     * @testdox with non existent xml file
     */
    public function testInvalidFile()
    {
        $this->expectException(ConfigException::class);
        $this->containerMeta = new ContainerMeta('non-existent-file.xml');
    }

    /**
     * @testdox get non existent service
     */
    public function testNonExistentService()
    {
        $this->assertNull($this->containerMeta->get('non-existent-service'));
    }
}
