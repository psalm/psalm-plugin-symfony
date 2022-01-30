@symfony-common
Feature: Form getErrors return type provider

  Background:
    Given I have Symfony plugin enabled
  Scenario: getErrors test
    Given I have the following code
          """
      <?php

      use Symfony\Component\Form\FormInterface;

      function foo(FormInterface $form): void
      {
          foreach ($form->getErrors() as $error1) {
              /** @psalm-trace $error1 */
          }

          foreach ($form->getErrors(true) as $error2) {
              /** @psalm-trace $error2 */
          }

          foreach ($form->getErrors(false) as $error3) {
              /** @psalm-trace $error3 */
          }

          foreach ($form->getErrors(true, true) as $error4) {
              /** @psalm-trace $error4 */
          }

          foreach ($form->getErrors(true, false) as $error5) {
              /** @psalm-trace $error5 */
          }

          foreach ($form->getErrors(false, true) as $error6) {
              /** @psalm-trace $error6 */
          }

          foreach ($form->getErrors(true, false) as $error7) {
              /** @psalm-trace $error7 */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                                             |
      | Trace | $error1: Symfony\Component\Form\FormError                                           |
      | Trace | $error2: Symfony\Component\Form\FormError                                           |
      | Trace | $error3: Symfony\Component\Form\FormError                                           |
      | Trace | $error4: Symfony\Component\Form\FormError                                           |
      | Trace | $error5: Symfony\Component\Form\FormError                                           |
      | Trace | $error6: Symfony\Component\Form\FormError                                           |
      | Trace | $error7: Symfony\Component\Form\FormError\|Symfony\Component\Form\FormErrorIterator |
    And I see no other errors
