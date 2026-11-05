@symfony-common @php-8
Feature: Dead code of container services
  The container creates its services, so their constructors and the methods it calls are used

  Scenario: the methods that the container calls on its services are marked as used
    Given I have the following code in "container_services.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
        <services>
          <service id="App\Service\Setters" class="App\Service\Setters">
            <call method="setLogger"/>
            <configurator service="App\Service\Configurator" method="configure"/>
          </service>
          <service id="App\Service\Configurator" class="App\Service\Configurator"/>
          <service id="app.product" class="App\Service\Product">
            <factory class="App\Service\ProductFactory" method="create"/>
          </service>
          <service id="app.created_by_service" class="App\Service\Product">
            <factory service="App\Service\ProductFactory" method="build"/>
          </service>
          <service id="App\Service\ProductFactory" class="App\Service\ProductFactory"/>
          <service id="App\Service\Resettable" class="App\Service\Resettable">
            <tag name="kernel.reset" method="reset"/>
          </service>
          <service id="kernel" class="App\Kernel" synthetic="true" public="true"/>
          <service id="App\Service\Template" class="App\Service\Template" abstract="true"/>
        </services>
      </container>
      """
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>container_services.xml</containerXml>
      """
    And I have the following code
      """
      <?php
      namespace App\Service;

      final class Setters
      {
        public function __construct() {}
        public function setLogger(): void {}
        public function unused(): void {}
      }

      final class Configurator
      {
        public function configure(Setters $service): void {}
      }

      final class Product
      {
        public function __construct() {}
      }

      final class ProductFactory
      {
        public static function create(): Product { return new Product(); }
        public function build(): Product { return new Product(); }
      }

      final class Resettable
      {
        public function reset(): void {}
      }

      final class Template
      {
        public function __construct() {}
      }

      namespace App;

      final class Kernel
      {
        public function __construct() {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type                 | Message                                                    |
      | UnusedParam          | Param #1 is never referenced in this method                |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Service\Setters::unused |
      | UnusedClass          | Class App\Service\Template is never used                   |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Kernel::__construct    |
    And I see no other errors

  Scenario: the services that the compiler removed as unused are not marked as used
    Given I have the following code in "container_removed.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
        <services>
          <service id="App\Service\Used" class="App\Service\Used" public="true"/>
          <service id="App\Service\Removed" class="App\Service\Removed"/>
        </services>
      </container>
      """
    And I have the following code in "container_removedCompiler.log"
      """
      Symfony\Component\DependencyInjection\Compiler\RemoveUnusedDefinitionsPass: Removed service "App\Service\Removed"; reason: unused.
      """
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>container_removed.xml</containerXml>
      """
    And I have the following code
      """
      <?php
      namespace App\Service;

      final class Used
      {
        public function __construct() {}
      }

      final class Removed
      {
        public function __construct() {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type        | Message                                 |
      | UnusedClass | Class App\Service\Removed is never used |
    And I see no other errors
