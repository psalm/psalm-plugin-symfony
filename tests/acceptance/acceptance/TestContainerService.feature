@symfony-4 @symfony-5
Feature: Test Container service

  Background:
    Given I have issue handlers "PropertyNotSetInConstructor,UnusedFunctionCall,UnusedVariable" suppressed
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>../../tests/acceptance/container.xml</containerXml>
      """

  Scenario: KernelTestCase container can access private services
    Given I have the following code
      """
      <?php

      namespace App\Tests;

      use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

      class TestService extends KernelTestCase
      {
        public function testService(): void
        {
          $service = static::$container->get('dummy_private_service');
          trim($service->foo());
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: WebTestCase container can access private services
    Given I have the following code
      """
      <?php

      namespace App\Tests;

      use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

      class TestService extends WebTestCase
      {
        public function testService(): void
        {
          $service = static::$container->get('dummy_private_service');
          trim($service->foo());
        }
      }
      """
    When I run Psalm
    Then I see no errors
