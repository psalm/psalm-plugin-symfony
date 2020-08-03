@symfony-common
Feature: Test Container service

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1">
        <projectFiles>
          <directory name="."/>
        </projectFiles>

        <issueHandlers>
          <PropertyNotSetInConstructor errorLevel="info" />
        </issueHandlers>

        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
            <containerXml>../../tests/acceptance/container.xml</containerXml>
          </pluginClass>
        </plugins>
      </psalm>
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
          $service = $this->container->get('dummy_private_service');
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
          $service = $this->container->get('dummy_private_service');
          $service->foo();
        }
      }
      """
    When I run Psalm
    Then I see no errors
