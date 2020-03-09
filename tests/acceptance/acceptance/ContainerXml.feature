Feature: Container XML config
  Detect ContainerInterface::get() result type

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
          <pluginClass class="Seferov\SymfonyPsalmPlugin\Plugin">
            <containerXml>../../tests/acceptance/container.xml</containerXml>
          </pluginClass>
        </plugins>
      </psalm>
      """

  Scenario: Asserting psalm recognizes return type of service got via 'ContainerInterface::get() using service ID'
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

  # todo: emit a service not found error instead of PossiblyNullReference
  Scenario: Psalm emits when service ID not found in container'
    Given I have the following code
      """
      <?php

      class SomeController
      {
        use \Symfony\Component\DependencyInjection\ContainerAwareTrait;

        public function index(): void
        {
          $this->container->get('not_a_service')->has('lorem');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                  | Message                                         |
      | PossiblyNullReference | Cannot call method has on possibly null value   |
