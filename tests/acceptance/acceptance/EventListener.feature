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

  Scenario: kernel.event_listener tag without a method calls the method named after the event, or __invoke
    Given I have the following code in "container_event_listener.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
        <services>
          <service id="App\Listener\NamedListener" class="App\Listener\NamedListener">
            <tag name="kernel.event_listener" event="kernel.finish_request"/>
            <tag name="kernel.event_listener" event="security.authentication.success"/>
          </service>
          <service id="App\Listener\InvokableListener" class="App\Listener\InvokableListener">
            <tag name="kernel.event_listener" event="kernel.request"/>
          </service>
          <service id="App\Listener\TypedListener" class="App\Listener\TypedListener">
            <tag name="kernel.event_listener"/>
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

      final class NamedListener
      {
        public function onKernelFinishRequest(): void {}
        public function onSecurityAuthenticationSuccess(): void {}
        public function unused(): void {}
      }

      final class InvokableListener
      {
        public function __invoke(): void {}
        public function onKernelResponse(): void {}
      }

      final class TypedListener
      {
        public function __invoke(\Symfony\Component\HttpKernel\Event\RequestEvent $event): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type                 | Message                                                                           |
      | UnusedParam          | Param #1 is never referenced in this method                                       |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Listener\NamedListener::unused                |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Listener\InvokableListener::onKernelResponse  |
    And I see no other errors

  Scenario: listeners that the container registered on an event dispatcher are marked as used
    Given I have the following code in "container_event_dispatcher.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
        <services>
          <service id="event_dispatcher" class="Symfony\Component\EventDispatcher\EventDispatcher" public="true">
            <call method="addListener">
              <argument>kernel.request</argument>
              <argument type="collection">
                <argument type="service_closure" id="app.subscriber"/>
                <argument>onRequest</argument>
              </argument>
              <argument>0</argument>
            </call>
          </service>
          <service id="security.event_dispatcher.main" class="Symfony\Component\EventDispatcher\EventDispatcher">
            <call method="addListener">
              <argument>Symfony\Component\Security\Http\Event\LoginSuccessEvent</argument>
              <argument type="collection">
                <argument type="service_closure" id="app.subscriber"/>
                <argument>onLogin</argument>
              </argument>
              <argument>0</argument>
            </call>
          </service>
          <service id="app.subscriber" class="App\Listener\Subscriber">
            <tag name="kernel.event_subscriber"/>
          </service>
        </services>
      </container>
      """
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>container_event_dispatcher.xml</containerXml>
      """
    And I have the following code
      """
      <?php
      namespace App\Listener;

      use Symfony\Component\EventDispatcher\EventSubscriberInterface;

      final class Subscriber implements EventSubscriberInterface
      {
        #[\Override]
        public static function getSubscribedEvents(): array
        {
          return ['kernel.request' => 'onRequest'];
        }

        public function onRequest(): void {}
        public function onLogin(): void {}
        public function unused(): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type                 | Message                                                           |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Listener\Subscriber::unused   |
    And I see no other errors
