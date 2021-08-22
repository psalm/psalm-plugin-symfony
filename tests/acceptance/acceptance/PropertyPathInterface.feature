@symfony-common
Feature: PropertyPathInterface

  Background:
    Given I have issue handlers "UnusedParam,UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\PropertyAccess\PropertyPath;

      $propertyPath = new PropertyPath('foo.bar.baz');
      """


  Scenario: PropertyPathInterface accepts int keys and string values
    Given I have the following code
      """

      function acceptsInt(int $int): void
      {

      }

      function acceptsString(string $string): void
      {

      }

      foreach ($propertyPath as $key => $path) {
        acceptsInt($key);
        acceptsString($path);
      }

      """
    When I run Psalm
    Then I see no errors
