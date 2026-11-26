@symfony-7 @symfony-8
Feature: Request bags are keyed by string

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
