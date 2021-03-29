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
  Scenario: Depending of typehinted form event, psalm will know type of data attached
    Given I have the following code
          """
      <?php

      class User {}

      use Symfony\Component\Form\AbstractType;
      use Symfony\Component\Form\FormBuilderInterface;
      use Symfony\Component\Form\Event\{PreSubmitEvent, PreSetDataEvent, PostSetDataEvent, SubmitEvent};
      use Symfony\Component\Form\FormEvents;

      /** @extends AbstractType<User> */
      class UserType extends AbstractType
      {
          public function buildForm(FormBuilderInterface $builder, array $options): void
          {
              $builder->addEventListener(FormEvents::PRE_SET_DATA, function(PreSetDataEvent $event) {
                  $presetData = $event->getData();
                  /** @psalm-trace $presetData */
              });

              $builder->addEventListener(FormEvents::POST_SET_DATA, function(PostSetDataEvent $event) {
                  $postSetData = $event->getData();
                  /** @psalm-trace $postSetData */
              });

              $builder->addEventListener(FormEvents::PRE_SUBMIT, function(PreSubmitEvent $event) {
                  $preSubmitData = $event->getData();
                  /** @psalm-trace $preSubmitData */
              });

              $builder->addEventListener(FormEvents::SUBMIT, function(SubmitEvent $event) {
                  $submitData = $event->getData();
                  /** @psalm-trace $submitData */
              });

              $config = $builder->getFormConfig();
              /** @psalm-trace $config */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                         |
      | Trace | $config: Symfony\Component\Form\FormConfigInterface<User>       |
      | Trace | $presetData: User\|null                                         |
      | Trace | $postSetData: User\|null                                        |
      | Trace | $preSubmitData: array<string, mixed>                            |
      | Trace | $submitData: User\|null                                         |
    And I see no other errors

