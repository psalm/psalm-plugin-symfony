@symfony-5
Feature: InputBag get return type

  Background:
    Given I have issue handler "UnusedFunctionCall,UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      """

  Scenario Outline: Return type is not null if default argument is string.
    Given I have the following code
      """
      class App
      {
        public function __invoke(Request $request): void
        {
          $string = $request-><property>->get('foo', 'bar');
          trim($string);
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                  | Message                                            |
      | InvalidScalarArgument | Argument 1 of trim expects string, scalar provided |
    Examples:
      | property |
      | query    |
      | cookies  |
      | request  |

  Scenario Outline: Return type is nullable if default argument is not provided.
    Given I have the following code
      """
      class App
      {
        public function __invoke(Request $request): void
        {
          $nullableString = $request-><property>->get('foo');
          trim($nullableString);
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                  | Message                                                  |
      | InvalidScalarArgument | Argument 1 of trim expects string, null\|scalar provided |
    And I see no other errors
    Examples:
      | property |
      | query    |
      | cookies  |
      | request  |
