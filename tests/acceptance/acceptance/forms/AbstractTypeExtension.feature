@symfony-5 @symfony-6
Feature: FormType templates

  Background:
    Given I have Symfony plugin enabled
  Scenario: FormExtension::getExtendedTypes must return iterables of FormTypeInterface
    Given I have the following code
          """
      <?php

      use Symfony\Component\Form\AbstractTypeExtension;
      use Symfony\Component\Form\Extension\Core\Type\FormType;

      /**
       * @extends AbstractTypeExtension<string>
       */
      class TestExtension extends AbstractTypeExtension
      {
          public static function getExtendedTypes(): iterable
          {
              yield FormType::class;
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: FormExtension::getExtendedTypes fails with incorrect types
    Given I have the following code
          """
      <?php

      use Symfony\Component\Form\AbstractTypeExtension;
      use Symfony\Component\Form\Extension\Core\Type\FormType;

      /**
       * @extends AbstractTypeExtension<string>
       */
      class TestExtension extends AbstractTypeExtension
      {
          public static function getExtendedTypes(): iterable
          {
              yield \stdClass::class;
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                       |
      | InvalidReturnType | The declared return type 'iterable<mixed, class-string<Symfony\Component\Form\FormTypeInterface>>' for TestExtension::getExtendedTypes is incorrect, got 'Generator<int, stdClass::class, mixed, void>' |

    And I see no other errors

  Scenario: FormTypeExtension has same behaviour as FormType
    Given I have the following code
          """
      <?php

      class User {}

      use Symfony\Component\Form\AbstractType;
      use Symfony\Component\Form\AbstractTypeExtension;
      use Symfony\Component\Form\FormView;
      use Symfony\Component\Form\FormInterface;
      use Symfony\Component\Form\FormBuilderInterface;

      /** @extends AbstractType<User> */
      class UserType extends AbstractType {}

      /** @extends AbstractTypeExtension<User> */
      class UserTypeExtension extends AbstractTypeExtension
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

          public static function getExtendedTypes(): iterable
          {
              yield UserType::class;
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
