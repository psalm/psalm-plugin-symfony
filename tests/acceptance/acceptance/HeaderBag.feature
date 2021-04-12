@symfony-common
Feature: Header get

  Background:
    Given I have issue handler "UnusedFunctionCall" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      """

  Scenario: HeaderBag get method return type should return `?string` (unless third argument is provided for < Sf4.4)
    Given I have the following code
      """
      class App
      {
        public function index(Request $request): void
        {
          $string = $request->headers->get('nullable_string');
          if (!$string) {
            return;
          }

          trim($string);
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: HeaderBag get method return type should return `string` if default value is provided with string
    Given I have the following code
      """
      class App
      {
        public function index(Request $request): void
        {
          $string = $request->headers->get('string', 'string');

          trim($string);
        }
      }
      """
    When I run Psalm
    Then I see no errors
