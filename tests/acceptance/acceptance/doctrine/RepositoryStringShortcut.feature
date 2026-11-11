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
      final class SomeService
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
      final class SomeService
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
      final class SomeService
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

  Scenario: Psalter replaces a fully qualified class name string with the ::class notation
    Given I have the following code
      """
      final class SomeService
      {
        public function __construct(EntityManagerInterface $entityManager, Foo $foo)
        {
          $entityManager->getRepository('Psalm\\SymfonyPsalmPlugin\\Tests\\Fixture\\Doctrine\\Foo');
        }
      }
      """
    When I run Psalter fixing "RepositoryStringShortcut"
    Then I see the fixed code
      """
      $entityManager->getRepository(Foo::class);
      """

  Scenario: Psalter leaves a shortcut notation string unchanged
    Given I have the following code
      """
      final class SomeService
      {
        public function __construct(EntityManagerInterface $entityManager)
        {
          $entityManager->getRepository('AppBundle:Entity');
        }
      }
      """
    When I run Psalter fixing "RepositoryStringShortcut"
    Then I see the fixed code
      """
      $entityManager->getRepository('AppBundle:Entity');
      """

  Scenario: Psalter falls back to the leading backslash when an import shadows the namespace
    Given I have the following file "Entity/Invoice.php"
      """
      <?php

      namespace RepositoryStringShortcut\Entity;

      final class Invoice {}
      """
    And I have the following code
      """
      use Psalm\SymfonyPsalmPlugin\Tests\Fixture\Doctrine as Entity;

      final class SomeService
      {
        public function __construct(EntityManagerInterface $entityManager, \RepositoryStringShortcut\Entity\Invoice $invoice)
        {
          $entityManager->getRepository('RepositoryStringShortcut\\Entity\\Invoice');
        }
      }
      """
    When I run Psalter fixing "RepositoryStringShortcut"
    Then I see the fixed code
      """
      $entityManager->getRepository(\RepositoryStringShortcut\Entity\Invoice::class);
      """
