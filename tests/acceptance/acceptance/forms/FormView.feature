@symfony-common
Feature: Form view

  Background:
    Given I have Symfony plugin enabled
  Scenario: FormView test
    Given I have the following code
          """
      <?php

      final class User {}

      use Symfony\Component\Form\AbstractType;
      use Symfony\Component\Form\FormView;
      use Symfony\Component\Form\FormInterface;

      /** @extends AbstractType<User> */
      final class UserType extends AbstractType
      {
          #[\Override]
          public function finishView(FormView $view, FormInterface $form, array $options): void
          {
              $parentView = $view->parent;
              /** @psalm-trace $parentView */

              $children = $view->children;
              /** @psalm-trace $children */

              $viewData = $view->vars['value'];
              /** @psalm-trace $viewData */

              // assert no errors
              $view->vars['random'] = new \stdClass();

              $attr = $view->vars['attr'];
              /** @psalm-trace $attr */
              $view->vars['attr']['placeholder'] = 'test';
              $savedValue = $view->vars['attr']['placeholder'];

              $errors = $view->vars['errors'];
              /** @psalm-trace $errors */
              $valid = $view->vars['valid'];
              /** @psalm-trace $valid */
              $data = $view->vars['data'];
              /** @psalm-trace $data */
              $required = $view->vars['required'];
              /** @psalm-trace $required */
              $labelAttr = $view->vars['label_attr'];
              /** @psalm-trace $labelAttr */
              $help = $view->vars['help'];
              /** @psalm-trace $help */
              $helpAttr = $view->vars['help_attr'];
              /** @psalm-trace $helpAttr */
              $helpHtml = $view->vars['help_html'];
              /** @psalm-trace $helpHtml */
              $helpTranslationParameters = $view->vars['help_translation_parameters'];
              /** @psalm-trace $helpTranslationParameters */
              $compound = $view->vars['compound'];
              /** @psalm-trace $compound */
              $method = $view->vars['method'];
              /** @psalm-trace $method */
              $action = $view->vars['action'];
              /** @psalm-trace $action */
              $submitted = $view->vars['submitted'];
              /** @psalm-trace $submitted */

              // keys populated by other form types are not listed, but stay readable
              $fullName = $view->vars['full_name'];
              /** @psalm-trace $fullName */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                      |
      | Trace | $parentView: Symfony\Component\Form\FormView\|null           |
      | Trace | $children: array<int\|string, Symfony\Component\Form\FormView> |
      | Trace | $viewData: User\|null                                        |
      | Trace | $attr: array<string, mixed>                                  |
      | Trace | $errors: Symfony\Component\Form\FormErrorIterator<Symfony\Component\Form\FormError\|Symfony\Component\Form\FormErrorIterator> |
      | Trace | $valid: bool                                                 |
      | MixedAssignment | Unable to determine the type that $data is being assigned to |
      | Trace | $data: mixed                                                 |
      | Trace | $required: bool                                              |
      | Trace | $labelAttr: array<string, mixed>                             |
      | Trace | $help: Symfony\Contracts\Translation\TranslatableInterface\|null\|string |
      | Trace | $helpAttr: array<string, mixed>                              |
      | Trace | $helpHtml: bool                                              |
      | Trace | $helpTranslationParameters: array<string, mixed>             |
      | Trace | $compound: bool                                              |
      | Trace | $method: string                                              |
      | Trace | $action: string                                              |
      | Trace | $submitted: bool                                             |
      | MixedAssignment | Unable to determine the type that $fullName is being assigned to |
      | Trace | $fullName: mixed                                             |
    And I see no other errors

