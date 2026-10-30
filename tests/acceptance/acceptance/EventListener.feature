@symfony-common @php-8
Feature: Symfony EventListener

  Scenario: kernel.event_listener tag method is marked as used
    Given I have the following code in "container_event_listener.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
        <services>
          <service id="App\Listener\ResponseListener" class="App\Listener\ResponseListener" public="true">
            <tag name="kernel.event_listener" method="onResponse"/>
          </service>
        </services>
      </container>
      """
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>container_event_listener.xml</containerXml>
      """
    And I have the following code
      """
      <?php
      namespace App\Listener;

      use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

      final class ResponseListener
      {
        #[AsEventListener]
        public function onResponse(): void {}

        public function unusedMethod(): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type | Message |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Listener\ResponseListener::unusedMethod |

    And I see no other errors
