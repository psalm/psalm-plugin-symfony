@symfony-8
Feature: Symfony 8 stubs keep the types Symfony 8 declares

  Background:
    Given I have issue handler "UnusedFunctionCall,UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      """

  Scenario Outline: All parameters of a bag are keyed by string
    Given I have the following code
      """
      final class App
      {
        public function __invoke(Request $request): void
        {
          foreach ($request-><property>->all() as $key => $_value) {
            $this->takesString($key);
          }
        }

        private function takesString(string $key): void
        {
        }
      }
      """
    When I run Psalm
    Then I see no errors

    Examples:
      | property   |
      | query      |
      | attributes |

  Scenario: createForm() returns a form flow for a form flow type
    Given I have the following code
      """
      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
      use Symfony\Component\Form\Flow\AbstractFlowType;

      final class CheckoutType extends AbstractFlowType
      {
      }

      final class CheckoutController extends AbstractController
      {
        public function __invoke(): void
        {
          $flow = $this->createForm(CheckoutType::class);
          /** @psalm-trace $flow */
          $flow->getStepForm();
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                  |
      | Trace | $flow: Symfony\Component\Form\Flow\FormFlowInterface     |
    And I see no other errors
