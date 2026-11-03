@symfony-6 @symfony-7 @symfony-8
Feature: AbstractController

  Background:
    Given I have Symfony plugin enabled

  Scenario: PropertyNotSetInConstructor error about $container is not raised
    Given I have the following code
      """
      <?php

      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

      final class MyController extends AbstractController
      {
          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: createForm() returns a form of the data type of the form type
    Given I have the following code
      """
      <?php

      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
      use Symfony\Component\Form\AbstractType;

      final class Order {}

      /** @extends AbstractType<Order> */
      final class OrderType extends AbstractType {}

      final class MyController extends AbstractController
      {
          public function __invoke(): void
          {
              $form = $this->createForm(OrderType::class);
              /** @psalm-trace $form */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                          |
      | Trace | $form: Symfony\Component\Form\FormInterface<Order> |
    And I see no other errors
