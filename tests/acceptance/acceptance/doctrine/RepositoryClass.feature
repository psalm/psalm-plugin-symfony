@symfony-common
Feature: RepositoryClass

  Background:
    Given I have issue handlers "UndefinedClass,UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php
      namespace RepositoryClass;

      use Psalm\SymfonyPsalmPlugin\Tests\Fixture\Doctrine\Foo;
      use Psalm\SymfonyPsalmPlugin\Tests\Fixture\Doctrine\FooRepository;
      use Doctrine\ORM\EntityManagerInterface;
      """

  Scenario: The plugin can find correct repository class from entity
    Given I have the following code
      """
      class SomeService
      {
        public function __construct(EntityManagerInterface $entityManager)
        {
          /** @psalm-trace $repository */
          $repository = $entityManager->getRepository(Foo::class);
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type   | Message                                                                     |
      | Trace  | $repository: Psalm\SymfonyPsalmPlugin\Tests\Fixture\Doctrine\FooRepository  |
    And I see no other errors

  Scenario: Passing variable class does not crash the plugin
    Given I have the following code
      """
      class SomeService
      {
        public function __construct(EntityManagerInterface $entityManager)
        {
          $entity = 'Psalm\SymfonyPsalmPlugin\Tests\Fixture\Doctrine\Foo';
          /** @psalm-trace $repository */
          $repository = $entityManager->getRepository($entity::class);
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type          | Message                                                                                                  |
      | Trace         | $repository: Doctrine\ORM\EntityRepository<object>                                                       |
      | MixedArgument | Argument 1 of Doctrine\ORM\EntityManagerInterface::getRepository cannot be mixed, expecting class-string |
    And I see no other errors
