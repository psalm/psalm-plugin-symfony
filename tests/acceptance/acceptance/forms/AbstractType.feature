@symfony-form
Feature: FormType templates

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
  Scenario: Assert FormType is using nullable template value in methods
    Given I have the following code
          """
      <?php

      class User {}

      use Symfony\Component\Form\AbstractType;
      use Symfony\Component\Form\FormView;
      use Symfony\Component\Form\FormInterface;
      use Symfony\Component\Form\FormBuilderInterface;

      /** @extends AbstractType<User> */
      class UserType extends AbstractType
      {
          public function buildForm(FormBuilderInterface $builder, array $options): void
          {
              $buildFormUser = $builder->getData();
              /** @psalm-trace $buildFormUser */
          }

          public function buildView(FormView $view, FormInterface $form, array $options): void
          {
              $buildViewUser = $form->getData();
              /** @psalm-trace $buildViewUser */
          }

          public function finishView(FormView $view, FormInterface $form, array $options): void
          {
              $finishViewUser = $form->getData();
              /** @psalm-trace $finishViewUser */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                       |
      | Trace | $buildFormUser: User\|null    |
      | Trace | $buildViewUser: User\|null    |
      | Trace | $finishViewUser: User\|null   |
    And I see no other errors
