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
              $view->vars['attr'][0] = 'integer keys are kept by Symfony';

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
      | Trace | $attr: array<array-key, mixed>                               |
      | MixedAssignment | Unable to determine the type that $fullName is being assigned to |
      | Trace | $fullName: mixed                                             |
    And I see no other errors

  Scenario: Keys set by FormType::buildView() are optional, as other view types do not have them
    Given I have the following code
          """
      <?php

      use Symfony\Component\Form\FormView;

      /** @param FormView<int> $view */
      function readVars(FormView $view): void
      {
          if (!isset($view->vars['errors']) || !isset($view->vars['valid']) || !isset($view->vars['data']) || !isset($view->vars['required']) || !isset($view->vars['label_attr']) || !isset($view->vars['help']) || !isset($view->vars['help_attr']) || !isset($view->vars['help_html']) || !isset($view->vars['help_translation_parameters']) || !isset($view->vars['compound']) || !isset($view->vars['method']) || !isset($view->vars['action']) || !isset($view->vars['submitted'])) {
              return;
          }

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
      }

      /** @param FormView<int> $view */
      function readUnguarded(FormView $view): void
      {
          $unguarded = $view->vars['valid'];
          /** @psalm-trace $unguarded */
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message |
      | Trace | $errors: Symfony\Component\Form\FormErrorIterator<Symfony\Component\Form\FormError>\|Symfony\Component\Form\FormErrorIterator<Symfony\Component\Form\FormError\|Symfony\Component\Form\FormErrorIterator> |
      | Trace | $valid: bool |
      | MixedAssignment | Unable to determine the type that $data is being assigned to |
      | Trace | $data: mixed |
      | Trace | $required: bool |
      | Trace | $labelAttr: array<array-key, mixed> |
      | Trace | $help: Symfony\Contracts\Translation\TranslatableInterface\|string |
      | Trace | $helpAttr: array<array-key, mixed> |
      | Trace | $helpHtml: bool |
      | Trace | $helpTranslationParameters: array<array-key, mixed> |
      | Trace | $compound: bool |
      | Trace | $method: string |
      | Trace | $action: string |
      | Trace | $submitted: bool |
      | PossiblyUndefinedArrayOffset | Possibly undefined array key $view->vars['valid'] on array{action?: string, attr: array<array-key, mixed>, compound?: bool, data?: mixed, errors?: Symfony\Component\Form\FormErrorIterator<Symfony\Component\Form\FormError>\|Symfony\Component\Form\FormErrorIterator<Symfony\Component\Form\FormError\|Symfony\Component\Form\FormErrorIterator>, help?: Symfony\Contracts\Translation\TranslatableInterface\|null\|string, help_attr?: array<array-key, mixed>, help_html?: bool, help_translation_parameters?: array<array-key, mixed>, label_attr?: array<array-key, mixed>, method?: string, required?: bool, submitted?: bool, valid?: bool, value: int\|null, ...<array-key, mixed>} |
      | Trace | $unguarded: bool\|null |
    And I see no other errors

  Scenario: Assigning getErrors() results to the errors view variable
    Given I have the following code
          """
      <?php

      use Symfony\Component\Form\FormView;
      use Symfony\Component\Form\FormInterface;

      /**
       * @param FormView<int> $view
       * @param FormInterface<int> $form
       */
      function withErrors(FormView $view, FormInterface $form): void
      {
          $view->vars['errors'] = $form->getErrors();
          $view->vars['errors'] = $form->getErrors(true);
          $view->vars['errors'] = $form->getErrors(true, false);
      }
      """
    When I run Psalm
    Then I see no errors
