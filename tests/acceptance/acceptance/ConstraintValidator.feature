@symfony-common
Feature: ConstraintValidator

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

  Scenario: PropertyNotSetInConstructor error about $context is not raised
    Given I have the following code
      """
      <?php

      use Symfony\Component\Validator\Constraint;
      use Symfony\Component\Validator\ConstraintValidator;

      class CustomValidator extends ConstraintValidator
      {
          public function __construct() {}

          public function validate($value, Constraint $constraint): void
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
