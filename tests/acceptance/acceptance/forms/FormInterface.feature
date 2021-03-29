@symfony-common
Feature: Form interface

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
        <issueHandlers>
          <UnusedVariable errorLevel="info"/>
        </issueHandlers>
      </psalm>
      """
  Scenario: FormInterface test
    Given I have the following code
          """
      <?php

      class User {}

      use Symfony\Component\Form\FormInterface;

      /** @psalm-var FormInterface<User> $form */

      $data = $form->getData();
      /** @psalm-trace $data */

      $view = $form->createView();
      /** @psalm-trace $view */

      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                |
      | Trace | $data: User\|null                      |
      | Trace | $view: Symfony\Component\Form\FormView |
    And I see no other errors

