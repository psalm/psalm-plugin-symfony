@symfony-common
Feature: Extended form types

  Background:
    Given I have Symfony plugin enabled
  Scenario: Assert extends form types yields non-mixed values
    Given I have the following code
      """
      <?php

      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
      use Symfony\Component\Form\Extension\Core\Type\TextType;
      use Symfony\Component\Form\Extension\Core\Type\SearchType;
      use Symfony\Component\Form\Extension\Core\Type\TextareaType;

      class MyController extends AbstractController
      {
          public function text(): void
          {
              $form = $this->createForm(TextType::class);
              $text = $form->getData();
              /** @psalm-trace $text */
          }

          public function search(): void
          {
              $form = $this->createForm(SearchType::class);
              $search = $form->getData();
              /** @psalm-trace $search */
          }

          public function textarea(): void
          {
              $form = $this->createForm(TextareaType::class);
              $textarea = $form->getData();
              /** @psalm-trace $textarea */
          }
      }
    """
    When I run Psalm
    Then I see these errors
      | Type  | Message                       |
      | Trace | $text: null\|string           |
      | Trace | $search: null\|string         |
      | Trace | $textarea: null\|string       |
    And I see no other errors
