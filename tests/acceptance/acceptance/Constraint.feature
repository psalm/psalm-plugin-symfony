@symfony-common
Feature: Constraint

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

  Scenario: NonInvariantDocblockPropertyType error about $errorNames is not raised
    Given I have the following code
      """
      <?php

      use Symfony\Component\Validator\Constraint;

      class CustomConstraint extends Constraint
      {
        /**
         * @var array<string, string>
         */
        protected static $errorNames = [
          'test' => 'test',
        ];
      }
      """
    When I run Psalm
    Then I see no errors
