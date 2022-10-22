@symfony-common
Feature: RepositoryStringShortcut
  In order to follow best practices for Symfony
  As a Psalm user
  I need Psalm to check preferred repository syntax

  Background:
    Given I have issue handlers "ArgumentTypeCoercion,MixedArgument,UndefinedClass" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      namespace RepositoryStringShortcut;

      use Doctrine\ORM\EntityManagerInterface;
      use Psalm\SymfonyPsalmPlugin\Tests\Fixture\Doctrine\Foo;
      """

  Scenario: Asserting using 'AppBundle:Entity' syntax raises issue
    Given I have the following code
      """
      class SomeService
      {
        public function __construct(EntityManagerInterface $entityManager)
        {
          $entityManager->getRepository('AppBundle:Entity');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                      | Message                          |
      | RepositoryStringShortcut  | Use Entity::class syntax instead |
    And I see no other errors

  Scenario: Asserting using 'Entity::class' notation does not raise issue
    Given I have the following code
      """
      class SomeService
      {
        public function __construct(EntityManagerInterface $entityManager)
        {
          $entityManager->getRepository(Foo::class);
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Dynamic repository calls should not be complained
    Given I have the following code
      """
      class SomeService
      {
        public function __construct(EntityManagerInterface $entityManager)
        {
          $className = Foo::class;
          $entityManager->getRepository($className);
        }
      }
      """
    When I run Psalm
    Then I see no errors
