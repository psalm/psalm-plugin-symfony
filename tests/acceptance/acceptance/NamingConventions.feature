Feature: Naming conventions
  Detect naming convention violations

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>

        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
            <containerXml>../../tests/acceptance/container.xml</containerXml>
          </pluginClass>
        </plugins>
      </psalm>
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
      | Type                      | Message                                                       |
      | NamingConventionViolation | Use snake_case for configuration parameter and service names |
    And I see no other errors
