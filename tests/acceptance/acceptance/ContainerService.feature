@symfony-common
Feature: Container service
  Detect ContainerInterface::get() result type

  Background:
    Given I have Symfony plugin enabled

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
          $this->container->get(SomeService::class)->nosuchmethod();
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
      <?php
      class SomeController
      {
        use \Symfony\Component\DependencyInjection\ContainerAwareTrait;

        public function index()
        {
          $this->container->get(self::class)->index();
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type              | Message                                                                  |
      | MissingReturnType | Method SomeController::index does not have a return type, expecting void |

  Scenario: Container get(some undefined constant) should not crash
    Given I have the following code
      """
      <?php
      trait SomeTrait
      {
        use \Symfony\Component\DependencyInjection\ContainerAwareTrait

        public function showConstant(): mixed
        {
          return $this->container->get(self::MY_CONSTANT);
        }
      }
      """
    When I run Psalm
    Then I see no errors
