@symfony-common @php-8
Feature: Doctrine entity event listeners

  Scenario: doctrine.orm.entity_listener tag method is marked as used
    Given I have the following code in "container_entity_listener.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
        <services>
          <service id="app.my_service_listener" class="App\EventListener\ProductListener">
            <tag name="doctrine.orm.entity_listener" entity="App\Entity\Product" event="prePersist" method="onPrePersist"/>
          </service>
        </services>
      </container>
      """
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>container_entity_listener.xml</containerXml>
      """
    And I have the following code
      """
      <?php
      namespace App\EventListener;

      final class ProductListener
      {
        public function onPrePersist(): void {}

        public function unusedMethod(): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type                 | Message                                                                               |
      | PossiblyUnusedMethod | Cannot find any calls to method App\EventListener\ProductListener::unusedMethod |
    And I see no other errors
