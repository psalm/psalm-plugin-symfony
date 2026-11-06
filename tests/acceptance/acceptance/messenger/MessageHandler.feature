@symfony-common @php-8
Feature: Messenger message handler

  Scenario: messenger.message_handler defaults to __invoke and is marked as used
    Given I have the following code in "container_messenger.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
        <services>
          <service id="custom.id" class="App\Message\DummyHandler">
            <tag name="messenger.message_handler" method="" />
          </service>
        </services>
      </container>
      """
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>container_messenger.xml</containerXml>
      """
    And I have the following code
      """
      <?php
      namespace App\Message;

      use Symfony\Component\Messenger\Attribute\AsMessageHandler;

      #[AsMessageHandler]
      final class DummyHandler
      {
        public function __invoke(): void
        {
        }
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors

  Scenario: messenger.message_handler method is marked as used
    Given I have the following code in "container_messenger.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
        <services>
          <service id="App\Message\DummyHandler" class="App\Message\DummyHandler">
            <tag name="messenger.message_handler" method="onEvent" />
          </service>
        </services>
      </container>
      """
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>container_messenger.xml</containerXml>
      """
    And I have the following code
      """
      <?php
      namespace App\Message;

      use Symfony\Component\Messenger\Attribute\AsMessageHandler;

      final class DummyHandler
      {
        #[AsMessageHandler]
        public function onEvent(): void
        {
        }

        public function unused(): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Issue | Message |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Message\DummyHandler::unused |

