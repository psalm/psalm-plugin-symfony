@symfony-common @php-8
Feature: Validator callback constraint

  Scenario: Validator callback attribute method is marked as used
    Given I have the following code in "container_validator.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
        <services>
          <service id="validator" class="Symfony\Component\Validator\Validator\ValidatorInterface" public="true"/>
        </services>
      </container>
      """
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>container_validator.xml</containerXml>
      """
    And I have the following code
      """
      <?php
      namespace App\Validator;

      use Symfony\Component\Validator\Constraints as Assert;

      final class Payload
      {
        #[Assert\Callback]
        public function calledByValidator(): void
        {
        }

        public function unused(): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Issue | Message |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Validator\Payload::unused |
    And I see no other errors

