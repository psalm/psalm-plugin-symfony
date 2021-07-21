@symfony-common
Feature: FileLocator locate

  Background:
    Given I have Symfony plugin enabled

  Scenario: FileLocator locate method return type should be `string` when third argument is true
    Given I have the following code
      """
      <?php
      function test(): string
      {
        $locator = new \Symfony\Component\Config\FileLocator(__DIR__);

        return $locator->locate(__FILE__);
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: FileLocator locate method return type should be `array` when third argument is false
    Given I have the following code
      """
      <?php
      function test(): array
      {
        $locator = new \Symfony\Component\Config\FileLocator(__DIR__);

        return $locator->locate(__FILE__, null, false);
      }
      """
    When I run Psalm
    Then I see no errors
