@symfony-common
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

      $view = $form->createView();
      /** @psalm-trace $view */

      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                       |
      | Trace | $view: Symfony\Component\Form\FormView<User>    |
    And I see no other errors
