@symfony-7 @symfony-8
Feature: Constraint without $errorNames
  Symfony 7 removed Constraint::$errorNames in favour of the ERROR_NAMES constant.

  Background:
    Given I have Symfony plugin enabled

  Scenario: Reading $errorNames of a constraint not declaring it
    Given I have the following code
      """
      <?php

      use Symfony\Component\Validator\Constraint;

      final class CustomConstraint extends Constraint
      {
        public static function names(): mixed
        {
          return self::$errorNames;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                   | Message                                                  |
      | UndefinedPropertyFetch | Static property CustomConstraint::$errorNames is not defined |
    And I see no other errors

  Scenario: A constraint declaring $errorNames
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
