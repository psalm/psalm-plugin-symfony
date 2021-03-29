@symfony-common
Feature: Form events

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
      | Trace | $children: list<Symfony\Component\Form\FormView>             |
      | Trace | $viewData: User\|null                                        |
      | Trace | $attr: array<array-key, mixed>                               |
    And I see no other errors

