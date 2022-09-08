@symfony-common
Feature: Form view

  Background:
    Given I have Symfony plugin enabled

  Scenario: FormView test
    Given I have the following code
          """
      <?php

      class User {}

      use Symfony\Component\Form\AbstractType;
      use Symfony\Component\Form\FormView;
      use Symfony\Component\Form\FormInterface;

      /** @extends AbstractType<User> */
      class UserType extends AbstractType
      {
          public function finishView(FormView $view, FormInterface $form, array $options): void
          {
              $parentView = $view->parent;
              /** @psalm-trace $parentView */

              $children = $view->children;
              /** @psalm-trace $children */

              $viewData = $view->vars['value'];
              /** @psalm-trace $viewData */

              $valid = $view->vars['valid'];
              /** @psalm-trace $valid */

              $errors = $view->vars['errors'];
              /** @psalm-trace $errors */

              $valid = $view->vars['valid'];
              /** @psalm-trace $valid */

              $data = $view->vars['data'];
              /** @psalm-trace $data */

              $required = $view->vars['required'];
              /** @psalm-trace $required */

              $label_attr = $view->vars['label_attr'];
              /** @psalm-trace $label_attr */

              $help = $view->vars['help'];
              /** @psalm-trace $help */

              $help_attr = $view->vars['help_attr'];
              /** @psalm-trace $help_attr */

              $help_html = $view->vars['help_html'];
              /** @psalm-trace $help_html */

              $help_translation_parameters = $view->vars['help_translation_parameters'];
              /** @psalm-trace $help_translation_parameters */

              $compound = $view->vars['compound'];
              /** @psalm-trace $compound */

              $method = $view->vars['method'];
              /** @psalm-trace $method */

              $action = $view->vars['action'];
              /** @psalm-trace $action */

              $submitted = $view->vars['submitted'];
              /** @psalm-trace $submitted */

              // assert no errors
              $view->vars['random'] = new \stdClass();

              $attr = $view->vars['attr'];
              /** @psalm-trace $attr */
              $view->vars['attr']['placeholder'] = 'test';
              $savedValue = $view->vars['attr']['placeholder'];
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                      |
      | Trace | $parentView: Symfony\Component\Form\FormView\|null           |
      | Trace | $children: array<string, Symfony\Component\Form\FormView>    |
      | Trace | $viewData: User\|null                                        |
      | Trace | $valid: bool                                                 |
      | Trace | $errors: Symfony\Component\Form\FormErrorIterator\|null      |
      | Trace | $attr: array<array-key, mixed>                               |
      | Trace | $valid: bool                                                 |
      | Trace | $data: mixed\|null                                           |
      | Trace | $required: bool                                              |
      | Trace | $label_attr: array<array-key, mixed>                         |
      | Trace | $help: string\|null                                          |
      | Trace | $help_attr: array<array-key, mixed>                          |
      | Trace | $help_html: bool                                             |
      | Trace | $help_translation_parameters: array<array-key, mixed>        |
      | Trace | $compound: bool                                              |
      | Trace | $method: string                                              |
      | Trace | $action: string                                              |
      | Trace | $submitted: bool                                             |
    And I see no other errors

