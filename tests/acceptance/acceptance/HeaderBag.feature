@symfony-common
Feature: Header get

  Background:
    Given I have issue handler "UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      """

  Scenario: HeaderBag get method return type should return `?string`
    Given I have the following code
      """
      final class App
      {
        public function index(Request $request): void
        {
          /** @psalm-trace $nullableString */
          $nullableString = $request->headers->get('nullable_string');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                        |
      | Trace | $nullableString: null\|string  |
    And I see no other errors

  Scenario: HeaderBag get method return type should return `string` if default value is provided with string
    Given I have the following code
      """
      final class App
      {
        public function index(Request $request): void
        {
          /** @psalm-trace $string */
          $string = $request->headers->get('string', 'string');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message         |
      | Trace | $string: string |
    And I see no other errors
