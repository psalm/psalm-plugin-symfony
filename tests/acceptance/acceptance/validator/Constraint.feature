@symfony-5 @symfony-6
Feature: Constraint

  Background:
    # Analysed as PHP 8.4: for PHP 8.5, Psalm requires #[\Override] on a property overriding a parent property, which
    # a property can't take before PHP 8.5.
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1" findUnusedCode="false" phpVersion="8.4">
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

      final class CustomConstraint extends Constraint
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
