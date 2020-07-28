<?php

namespace Psalm\SymfonyPsalmPlugin\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psalm\Exception\ConfigException;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\SymfonyPsalmPlugin\Symfony\Service;
use Symfony\Component\HttpKernel\Kernel;

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
        $this->containerMeta = new ContainerMeta([__DIR__.'/../../acceptance/container.xml']);
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
        if (3 === Kernel::MAJOR_VERSION) {
            $this->markTestSkipped('Should run for > Symfony 3');
        }

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
            [
                'id' => 'public_service_wo_public_attr',
                'className' => 'Foo\Bar',
                'isPublic' => false,
            ],
        ];
    }

    /**
     * @testdox service attributes
     * @dataProvider publicServices3
     */
    public function testServices3($id, string $className, bool $isPublic)
    {
        if (Kernel::MAJOR_VERSION > 3) {
            $this->markTestSkipped('Should run for Symfony 3');
        }

        $service = $this->containerMeta->get($id);
        $this->assertInstanceOf(Service::class, $service);
        $this->assertSame($className, $service->getClassName());
        $this->assertSame($isPublic, $service->isPublic());
    }

    public function publicServices3()
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
            [
                'id' => 'public_service_wo_public_attr',
                'className' => 'Foo\Bar',
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
        $this->containerMeta = new ContainerMeta(['non-existent-file.xml']);
    }

    /**
     * @testdox get non existent service
     */
    public function testNonExistentService()
    {
        $this->assertNull($this->containerMeta->get('non-existent-service'));
    }

    /**
     * @testdox one valid, one invalid file should not raise an issue
     */
    public function testBothValidAndInvalidArray()
    {
        $containerMeta = new ContainerMeta(['non-existent-file.xml', __DIR__.'/../../acceptance/container.xml']);
        $service = $containerMeta->get('service_container');
        $this->assertSame('Symfony\Component\DependencyInjection\ContainerInterface', $service->getClassName());
    }
}
