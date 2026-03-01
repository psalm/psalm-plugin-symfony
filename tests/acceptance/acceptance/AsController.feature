@symfony-common @php-8
Feature: AsController dynamic callers
  Mark invokable controllers as used

  Scenario: AsController invokable class is marked as used
    Given I have the following code in "container_as_controller.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
        <services>
          <service id="App\Controller\InvokableController" class="App\Controller\InvokableController" public="true"/>
        </services>
      </container>
      """
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>container_as_controller.xml</containerXml>
      """
    And I have the following code
      """
      <?php
      namespace App\Controller;

      use Symfony\Component\HttpKernel\Attribute\AsController;

      #[AsController]
      final class InvokableController
      {
        public function __invoke(): void
        {
        }
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors
