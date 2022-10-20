@symfony-common
Feature: ConstraintViolationListInterface

  Background:
    Given I have Symfony plugin enabled

  Scenario: ConstraintViolationListInterface is a Traversable with int key and ConstraintViolationInterface value
    Given I have the following code
      """
      <?php

      use Symfony\Component\Validator\ConstraintViolationListInterface;

      function run(ConstraintViolationListInterface $list): void
      {
          foreach ($list as $key => $value) {
              /** @psalm-trace $key */
              echo $key;
              /** @psalm-trace $value */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                          |
      | Trace | $key: int                                                        |
      | Trace | $value: Symfony\Component\Validator\ConstraintViolationInterface |
    And I see no other errors
