@symfony-6 @symfony-7
Feature: InputBag get return type before Symfony 8

  Background:
    Given I have issue handler "UnusedFunctionCall,UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      """

  Scenario: Return type is scalar for request property if default argument is string.
    Given I have the following code
      """
      final class App
      {
        public function __invoke(Request $request): void
        {
          $string = $request->request->get('foo', 'bar');
          /** @psalm-trace $string */
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message         |
      | Trace | $string: scalar |
