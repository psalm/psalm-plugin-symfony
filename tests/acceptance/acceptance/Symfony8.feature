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

  Scenario: Request has no get() method since Symfony 8
    Given I have the following code
      """
      final class SearchController
      {
        public function __invoke(Request $request): mixed
        {
          return $request->get('q');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message                                                            |
      | UndefinedMethod | Method Symfony\Component\HttpFoundation\Request::get does not exist |
    And I see no other errors

  Scenario: A request class declares a get() method with side effects
    Given I have the following code
      """
      final class AuditedRequest extends Request
      {
        public function get(string $key): mixed
        {
          error_log('Read '.$key);

          return $this->query->get($key);
        }
      }
      """
    When I run Psalm
    Then I see no errors
