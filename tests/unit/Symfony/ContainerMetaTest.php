<?php

namespace Psalm\SymfonyPsalmPlugin\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psalm\Exception\ConfigException;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
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

    public function setUp(): void
    {
        $this->containerMeta = new ContainerMeta([__DIR__.'/../../acceptance/container.xml']);
    }

    public function tearDown(): void
    {
        unset($this->containerMeta);
    }

    /**
     * @testdox service attributes
     * @dataProvider publicServices
     */
    public function testServices(string $id, string $className, bool $isPublic): void
    {
        $serviceDefinition = $this->containerMeta->get($id);
        $this->assertInstanceOf(Definition::class, $serviceDefinition);
        $this->assertSame($className, $serviceDefinition->getClass());
        $this->assertSame($isPublic, $serviceDefinition->isPublic());
    }

    public function publicServices(): iterable
    {
        yield [
            'id' => 'service_container',
            'className' => 'Symfony\Component\DependencyInjection\ContainerInterface',
            'isPublic' => true,
        ];
        yield [
            'id' => 'Foo\Bar',
            'className' => 'Foo\Bar',
            'isPublic' => false,
        ];
        yield [
            'id' => 'public_service_wo_public_attr',
            'className' => 'Foo\Bar',
            'isPublic' => Kernel::MAJOR_VERSION < 5,
        ];
        yield [
            'id' => 'doctrine.orm.entity_manager',
            'className' => 'Doctrine\ORM\EntityManager',
            'isPublic' => true,
        ];
    }

    public function testGetClassesDoesNotContainExcludedServices(): void
    {
        $classNames = $this->containerMeta->getClassNames();

        $this->assertNotContains('App\Exception\FooException', $classNames);
        $this->assertNotContains('App\FooInterface', $classNames);
    }

    /**
     * @testdox with non-existent xml file
     */
    public function testInvalidFile(): void
    {
        $this->expectException(ConfigException::class);
        $this->containerMeta = new ContainerMeta(['non-existent-file.xml']);
    }

    /**
     * @testdox get non existent service
     */
    public function testNonExistentService(): void
    {
        $this->expectException(ServiceNotFoundException::class);
        $this->assertNull($this->containerMeta->get('non-existent-service'));
    }

    /**
     * @testdox one valid, one invalid file should not raise an issue
     */
    public function testBothValidAndInvalidArray(): void
    {
        $containerMeta = new ContainerMeta(['non-existent-file.xml', __DIR__.'/../../acceptance/container.xml']);
        $service = $containerMeta->get('service_container');
        $this->assertSame('Symfony\Component\DependencyInjection\ContainerInterface', $service->getClass());
    }

    public function testGetParameter(): void
    {
        $this->assertSame('dev', $this->containerMeta->getParameter('kernel.environment'));
        $this->assertSame(true, $this->containerMeta->getParameter('debug_enabled'));
        $this->assertSame('1', $this->containerMeta->getParameter('version'));
        $this->assertSame(1, $this->containerMeta->getParameter('integer_one'));
        $this->assertSame(3.14, $this->containerMeta->getParameter('pi'));
        $this->assertSame([
            'key1' => 'val1',
            'key2' => 'val2',
        ], $this->containerMeta->getParameter('collection1'));
        $this->assertSame([
            'key' => 'val',
            'child_collection' => [
                'boolean' => true,
                'float' => 2.18,
                'grandchild_collection' => [
                    'string' => 'something',
                ],
            ]
        ], $this->containerMeta->getParameter('nested_collection'));
    }

    /**
     * @dataProvider guessParameterTypeProvider
     */
    public function testGuessParameterType(?array $expectedTypes, string $parameterName): void
    {
        $this->assertSame($expectedTypes, $this->containerMeta->guessParameterType($parameterName));
    }

    public function guessParameterTypeProvider(): iterable
    {
        yield [['string'], 'kernel.environment'];
        yield [['bool'], 'debug_enabled'];
        yield [['string'], 'version'];
        yield [['int'], 'integer_one'];
        yield [['float'], 'pi'];
        yield [['array'], 'collection1'];
        yield [['array'], 'nested_collection'];
        yield [['bool'], 'env_param_bool'];
        yield [['string'], 'env_param_string'];
        yield [null, 'env_param_custom_type'];
        yield [['array'], 'env_param_json'];
        yield [['BackedEnum'], 'env_param_enum'];
        yield [['array'], 'env_param_url'];
    }

    public function testGetParameterNonExistent(): void
    {
        $this->expectException(ParameterNotFoundException::class);
        $this->containerMeta->getParameter('non_existent');
    }

    /**
     * @dataProvider serviceLocatorProvider
     */
    public function testGetServiceWithContext(string $id, string $contextClass, string $expectedClass): void
    {
        $serviceDefinition = $this->containerMeta->get($id, $contextClass);
        $this->assertSame($expectedClass, $serviceDefinition->getClass());
    }

    public function serviceLocatorProvider(): iterable
    {
        yield [
            'dummy_service_with_locator2',
            'App\Controller\DummyController',
            'Psalm\SymfonyPsalmPlugin\Tests\Fixture\DummyPrivateService'
        ];
        yield [
            'dummy_service_with_locator3',
            'App\Controller\DummyController',
            'Psalm\SymfonyPsalmPlugin\Tests\Fixture\DummyPrivateService'
        ];
        yield [
            'dummy_service_with_locator3',
            'App\SomeClass',
            'Psalm\SymfonyPsalmPlugin\Tests\Fixture\DummyPrivateService'
        ];
        yield [
            'dummy_service_with_locator2',
            'App\SomeClass',
            'Psalm\SymfonyPsalmPlugin\Tests\Fixture\DummyPrivateService'
        ];
        yield [
            'dummy_service_with_locator',
            'App\SomeClass',
            'Psalm\SymfonyPsalmPlugin\Tests\Fixture\DummyPrivateService'
        ];
    }
}
