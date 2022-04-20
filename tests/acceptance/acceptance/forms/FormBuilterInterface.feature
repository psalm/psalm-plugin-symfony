@symfony-common
Feature: Form builder

  Background:
    Given I have Symfony plugin enabled
  Scenario: Depending of typehinted form event, psalm will know type of data attached
    Given I have the following code
          """
      <?php

      class User {}

      use Symfony\Component\Form\AbstractType;
      use Symfony\Component\Form\FormBuilderInterface;
      use Symfony\Component\Form\FormEvents;
      use Symfony\Component\Form\Extension\Core\Type\CollectionType;

      /** @extends AbstractType<User> */
      class UserType extends AbstractType
      {
          public function buildForm(FormBuilderInterface $builder, array $options): void
          {
              $form = $builder->getForm();
              /** @psalm-trace $form */

              $builder->create('works');
              $builder->create('works', CollectionType::class);

              $builder->create('fails', \stdClass::class);
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                         |
      | Trace | $form: Symfony\Component\Form\FormInterface<User>               |
      | InvalidArgument | Argument 2 of Symfony\Component\Form\FormBuilderInterface::create expects class-string<Symfony\Component\Form\FormTypeInterface>\|null, but stdClass::class provided |
    And I see no other errors
