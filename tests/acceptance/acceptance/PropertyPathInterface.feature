@symfony-common
Feature: PropertyPathInterface

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>

        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
      </psalm>
      """
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
