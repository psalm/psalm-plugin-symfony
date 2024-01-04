@symfony-6
Feature: ConstraintValidator

  Background:
    Given I have Symfony plugin enabled

  Scenario: PropertyNotSetInConstructor error about $context is not raised
    Given I have the following code
      """
      <?php

      use Symfony\Component\Validator\Constraint;
      use Symfony\Component\Validator\ConstraintValidator;

      class CustomValidator extends ConstraintValidator
      {
          public function __construct() {}

          public function validate(mixed $value, Constraint $constraint): void
          {
              if ($value) {
                  $this->context
                      ->buildViolation('foo')
                      ->atPath('foo')
                      ->addViolation();
              }
          }
      }
      """
    When I run Psalm
    Then I see no errors
