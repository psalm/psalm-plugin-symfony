Feature: Container service
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
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: Asserting psalm recognizes return type of service got via 'ContainerInterface::get()'
    Given I have the following code
      """
      <?php
      class SomeService
      {
        public function do(): void {}
      }

      class SomeController
      {
        use \Symfony\Component\DependencyInjection\ContainerAwareTrait;

        public function index(): void
        {
          $this->container->get(SomeService::class)->do();
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Asserting psalm recognizes return type of service got via 'ContainerInterface::get()'.
    Given I have the following code
      """
      <?php
      class SomeService
      {
        public function do(): void {}
      }

      class SomeController
      {
        use \Symfony\Component\DependencyInjection\ContainerAwareTrait;

        public function index(): void
        {
          $this->container->get(SomeService::class)->noSuchMethod();
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message                                          |
      | UndefinedMethod |  Method SomeService::nosuchmethod does not exist |
    And I see no other errors

  Scenario: Container get(self::class) should not crash
    Given I have the following code
      """
      <?php
      class SomeController
      {
        use \Symfony\Component\DependencyInjection\ContainerAwareTrait;

        public function index(): void
        {
          $this->container->get(self::class)->index();
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                  | Message                                                                          |
      | PossiblyNullReference | Cannot call method index on possibly null value                                  |
      | MixedMethodCall       | Cannot determine the type of the object on the left hand side of this expression |
    And I see no other errors
