@symfony-common @php-8
Feature: Container service alias dead code detection

  Scenario: Concrete class behind an interface alias is not reported as unused
    Given I have the following code in "container_aliased_service.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
        <services>
          <service id="App\Service\Foo" class="App\Service\Foo"/>
          <service id="App\Contract\FooInterface" alias="App\Service\Foo"/>
        </services>
      </container>
      """
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>container_aliased_service.xml</containerXml>
      """
    And I have the following code
      """
      <?php
      namespace App\Contract;

      interface FooInterface
      {
        public function run(): void;
      }

      namespace App\Service;

      use App\Contract\FooInterface;

      final class Foo implements FooInterface
      {
        public function __construct() {}

        #[\Override]
        public function run(): void {}

        public function unused(): void {}
      }

      namespace App\Controller;
      use Symfony\Component\HttpKernel\Attribute\AsController;

      #[AsController]
      final class Controller {
        public function __invoke(\App\Contract\FooInterface $service): void {
          $service->run();
        }
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Issue | Message |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Service\Foo::unused |
    And I see no other errors
