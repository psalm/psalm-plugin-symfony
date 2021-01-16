@symfony-form
Feature: Form test

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
  Scenario: Assert that Form::getData() will return nullable type (empty_data failure)
    Given I have the following code
          """
      <?php

      class User {}

      use Symfony\Component\Form\Form;
      use Symfony\Component\Form\FormView;

      /** @psalm-var Form<User> $form */

      $data = $form->getData();
      /** @psalm-trace $data */

      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                       |
      | Trace | $data: User\|null    |
    And I see no other errors
