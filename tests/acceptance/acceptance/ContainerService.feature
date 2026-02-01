@symfony-common
Feature: Container service
  Detect ContainerInterface::get() result type

  Background:
    Given I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use \Symfony\Component\DependencyInjection\ContainerInterface;

      final class SomeService
      {
        public function do(): void {}
      }
      """

  Scenario: Asserting psalm recognizes return type of service got via 'ContainerInterface::get()'
    Given I have the following code
      """
      final class App
      {
        public function __invoke(ContainerInterface $container): void
        {
          $container->get(SomeService::class)->do();
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Asserting psalm recognizes return type of service got via 'ContainerInterface::get()'.
    Given I have the following code
      """
      final class App
      {
        public function __invoke(ContainerInterface $container): void
        {
          $container->get(SomeService::class)->nosuchmethod();
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message                                         |
      | UndefinedMethod | Method SomeService::nosuchmethod does not exist |
    And I see no other errors

  Scenario: Container get(self::class) should not crash
    Given I have the following code
      """
      final class App
      {
        public function index(ContainerInterface $container): void
        {
          $container->get(self::class)->index();
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message                                                                          |
      | MixedMethodCall | Cannot determine the type of the object on the left hand side of this expression |
    And I see no other errors
