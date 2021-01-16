@symfony-form
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
  Scenario: Make difference between different events
    Given I have the following code
          """
      <?php

      class User {}

      use Symfony\Component\Form\AbstractType;
      use Symfony\Component\Form\FormBuilderInterface;
      use Symfony\Component\Form\Event\{PreSubmitEvent, PreSetDataEvent};
      use Symfony\Component\Form\FormEvents;

      /** @extends AbstractType<User> */
      class UserType extends AbstractType
      {
          public function buildForm(FormBuilderInterface $builder, array $options): void
          {
              $builder->addEventListener(FormEvents::PRE_SUBMIT, function(PreSetDataEvent $event) {
                  $preset = $event->getData();
                  /** @psalm-trace $preset */
              });

              $builder->addEventListener(FormEvents::PRE_SUBMIT, function(PreSubmitEvent $event) {
                  $preSubmit = $event->getData();
                  /** @psalm-trace $preSubmit */
              });
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                    |
      | Trace | $preset: User\|null                        |
      | Trace | $preSubmit: array<string, mixed>           |
    And I see no other errors

