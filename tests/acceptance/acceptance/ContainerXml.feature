@symfony-common
Feature: Container XML config
  Detect ContainerInterface::get() result type

  Background:
    Given I have Symfony plugin enabled with the following config
      """
      <containerXml>../../tests/acceptance/container.xml</containerXml>
      """
    And I have the following code preamble
      """
      <?php

      use \Symfony\Component\DependencyInjection\ContainerInterface;
      use \Symfony\Component\HttpKernel\HttpKernelInterface;
      """

  Scenario: Asserting psalm recognizes return type of service got via 'ContainerInterface::get() using service ID'
    Given I have the following code
      """
      final class App
      {
        public function __invoke(ContainerInterface $container): bool
        {
          return $container->get('service_container')->has('lorem');
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Psalm emits when service ID not found in container'
    Given I have the following code
      """
      final class App
      {
        public function __invoke(ContainerInterface $container): void
        {
          $container->get('not_a_service')->has('lorem');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message                           |
      | ServiceNotFound | Service "not_a_service" not found |

  Scenario: Using service both via alias and class const
    Given I have the following code
      """
      final class App
      {
        public function __invoke(ContainerInterface $container): void
        {
          $container->get('http_kernel');
          $container->get(HttpKernelInterface::class);
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Using private service
    Given I have the following code
      """
      final class App
      {
        public function __invoke(ContainerInterface $container): void
        {
          $container->get('private_service');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message                                                    |
      | PrivateService  | Private service "private_service" used in container::get() |
    And I see no errors
