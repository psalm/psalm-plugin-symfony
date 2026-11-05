@symfony-6 @symfony-7 @symfony-8
Feature: InputBag get return type

  Background:
    Given I have issue handler "UnusedFunctionCall,UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      """

  Scenario Outline: Return type is string if default argument is string.
    Given I have the following code
      """
      final class App
      {
        public function __invoke(Request $request): void
        {
          $string = $request-><property>->get('foo', 'bar');
          /** @psalm-trace $string */
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message         |
      | Trace | $string: string |
    And I see no other errors
    Examples:
      | property |
      | query    |
      | cookies  |

  Scenario: Return type is nullable for request property if default argument is not provided.
    Given I have the following code
      """
      final class App
      {
        public function __invoke(Request $request): void
        {
          $nullableScalar = $request->request->get('foo');
          /** @psalm-trace $nullableScalar */
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                       |
      | Trace | $nullableScalar: null\|scalar |
    And I see no other errors

  Scenario Outline: Return type is nullable if default argument is not provided.
    Given I have the following code
      """
      final class App
      {
        public function __invoke(Request $request): void
        {
          $nullableString = $request-><property>->get('foo');
          /** @psalm-trace $nullableString */
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                       |
      | Trace | $nullableString: null\|string |
    And I see no other errors
    Examples:
      | property |
      | query    |
      | cookies  |

  Scenario: An input value can be null whatever the default
    Given I have the following code
      """
      final class App
      {
        public function __invoke(Request $request): void
        {
          $value = $request->request->get('foo', 'bar');
          /** @psalm-trace $value */
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message              |
      | Trace | $value: null\|scalar |

  Scenario: The default of an input value may have another type than the input
    Given I have the following code
      """
      final class App
      {
        public function __invoke(Request $request): void
        {
          $value = $request->query->get('foo', false);
          /** @psalm-trace $value */
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message              |
      | Trace | $value: false\|string |
