@symfony-common
Feature: Naming conventions
  Detect naming convention violations

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

  Scenario: There is no service naming convention violation, so no complaint.
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

  Scenario: Detects service naming convention violation
    Given I have the following code
      """
      final class App
      {
        public function __invoke(ContainerInterface $container): void
        {
          $container->get('wronglyNamedService');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                      | Message                                                      |
      | NamingConventionViolation | Use snake_case for configuration parameter and service names |
    And I see no other errors

  Scenario: No service naming convention violation when using FQCNs
    Given I have the following code
      """
      final class App
      {
        public function __invoke(ContainerInterface $container): void
        {
          $container->get(HttpKernelInterface::class);
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: No naming convention violation for parameter
    Given I have the following code
      """
      final class App
      {
        public function __invoke(ContainerInterface $container): void
        {
          $container->getParameter('kernel.cache_dir');
        }
      }
      """
    When I run Psalm
    And I see no errors

  Scenario: Detects parameter naming convention violation
    Given I have the following code
      """
      final class App
      {
        public function __invoke(ContainerInterface $container): void
        {
          $container->getParameter('wronglyNamedParameter');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                      | Message                                                      |
      | NamingConventionViolation | Use snake_case for configuration parameter and service names |
    And I see no other errors

  Scenario: No parameter naming convention violation when using environment variables
    Given I have the following code
      """
      final class App
      {
        public function __invoke(ContainerInterface $container): void
        {
          $container->getParameter('env(SOME_ENV_VAR)');
        }
      }
      """
    When I run Psalm
    Then I see no errors
