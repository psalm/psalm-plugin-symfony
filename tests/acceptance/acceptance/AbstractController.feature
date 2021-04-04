@symfony-4 @symfony-5
Feature: AbstractController

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

  Scenario: PropertyNotSetInConstructor error about $container is not raised
    Given I have the following code
      """
      <?php

      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

      class MyController extends AbstractController
      {
          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Find templated FormTypeInterface
    Given I have the following code
      """
      <?php

      class User {}

      use Symfony\Component\Form\AbstractType;

      /** @extends AbstractType<User> */
      class UserType extends AbstractType{}

      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

      class MyController extends AbstractController
      {
          public function testForm(): void
          {
              $form = $this->createForm(UserType::class);
              /** @psalm-trace $form */

              $user = $form->getData();
              /** @psalm-trace $user */

          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                           |
      | Trace | $form: Symfony\Component\Form\FormInterface<User> |
      | Trace | $user: User\|null                                 |
    And I see no other errors

  Scenario: Non templated form types continue to work without errors
    Given I have the following code
      """
      <?php

      class User {}

      use Symfony\Component\Form\AbstractType;

      class UserType extends AbstractType{}

      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

      class MyController extends AbstractController
      {
          public function testForm(): void
          {
              $form = $this->createForm(UserType::class);

              /** @var ?User $user : type must be manually defined */
              $user = $form->getData();
              /** @psalm-trace $user */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message           |
      | Trace | $user: User\|null |
    And I see no other errors

