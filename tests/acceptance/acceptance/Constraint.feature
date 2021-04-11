@symfony-common
Feature: Constraint

  Background:
    Given I have Symfony plugin enabled

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
