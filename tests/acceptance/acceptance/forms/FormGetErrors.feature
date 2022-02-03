@symfony-common
Feature: Form getErrors return type provider

  Background:
    Given I have Symfony plugin enabled
  Scenario: getErrors with anything else than (true, false)
    Given I have the following code
          """
      <?php

      use Symfony\Component\Form\FormInterface;

      function foo(FormInterface $form): array
      {
          $messages = [];

          foreach ($form->getErrors() as $error1) {
              $messages[] = $error1->getMessage();
          }

          foreach ($form->getErrors(true) as $error2) {
              $messages[] = $error2->getMessage();
          }

          foreach ($form->getErrors(false) as $error3) {
              $messages[] = $error3->getMessage();
          }

          foreach ($form->getErrors(true, true) as $error4) {
              $messages[] = $error4->getMessage();
          }

          foreach ($form->getErrors(false, false) as $error5) {
              $messages[] = $error5->getMessage();
          }

          foreach ($form->getErrors(false, true) as $error6) {
              $messages[] = $error6->getMessage();
          }

          return $messages;
      }
      """
    When I run Psalm
    Then I see no other errors

  Scenario: getErrors with (true, false)
    Given I have the following code
          """
      <?php

      use Symfony\Component\Form\FormInterface;

      function foo(FormInterface $form): array
      {
          $messages = [];

          foreach ($form->getErrors(true, false) as $error7) {
              /** @psalm-trace $error7 */
              $messages[] = $error7->getMessage();
          }

          return $messages;
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                    | Message                                                                             |
      | Trace                   | $error7: Symfony\Component\Form\FormError\|Symfony\Component\Form\FormErrorIterator |
      | MixedAssignment         | Unable to determine the type of this assignment                                     |
      | PossiblyUndefinedMethod | Method Symfony\Component\Form\FormErrorIterator::getMessage does not exist          |
    And I see no other errors
