@symfony-common
Feature: Naming conventions
  Detect naming convention violations

  Background:
    Given I have Symfony plugin enabled with the following config
      """
      <containerXml>../../tests/acceptance/container.xml</containerXml>
      """

  Scenario: There is no service naming convention violation, so no complaint.
    Given I have the following code
      """
      <?php

      class SomeController
      {
        use \Symfony\Component\DependencyInjection\ContainerAwareTrait;

        public function index(): bool
        {
          return $this->container->get('service_container')->has('lorem');
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Detects service naming convention violation
    Given I have the following code
      """
      <?php

      class SomeController
      {
        use \Symfony\Component\DependencyInjection\ContainerAwareTrait;

        public function index(): void
        {
          $this->container->get('wronglyNamedService');
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
      <?php

      use Symfony\Component\HttpKernel\HttpKernelInterface;

      class SomeController
      {
        use \Symfony\Component\DependencyInjection\ContainerAwareTrait;

        public function __invoke(): void
        {
          $this->container->get(HttpKernelInterface::class);
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: No naming convention violation for parameter
    Given I have the following code
      """
      <?php

      class SomeController
      {
        use \Symfony\Component\DependencyInjection\ContainerAwareTrait;

        public function index(): void
        {
          $this->container->getParameter('kernel.cache_dir');
        }
      }
      """
    When I run Psalm
    And I see no other errors

  Scenario: Detects parameter naming convention violation
    Given I have the following code
      """
      <?php

      class SomeController
      {
        use \Symfony\Component\DependencyInjection\ContainerAwareTrait;

        public function index(): void
        {
          $this->container->getParameter('wronglyNamedParameter');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                      | Message                                                      |
      | NamingConventionViolation | Use snake_case for configuration parameter and service names |
    And I see no other errors
